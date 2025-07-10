<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class BME_Extraction
 *
 * The core engine for fetching and processing MLS data from the Bridge API.
 *
 * v3.4.0
 * - REFACTOR: `process_listings` now intelligently sorts API data. Standard fields are saved to dedicated columns, while all other fields (including MLS-specific ones) are collected and stored in the `AdditionalData` JSON column. This ensures no data is lost.
 */
class BME_Extraction {

    private $api_token;
    private $base_api_url;

    public function __construct() {
        // Intentionally left blank.
    }

    public function run_single_extraction($post_id, $is_resync = false) {
        $options = get_option('bme_api_credentials');
        $this->api_token = isset($options['server_token']) ? $options['server_token'] : null;
        $this->base_api_url = isset($options['endpoint_url']) ? $options['endpoint_url'] : null;

        if (!$this->api_token || !$this->base_api_url) {
            $this->log_activity($post_id, 'Failure', 'API credentials could not be retrieved. Please check Settings.', 0, []);
            return false;
        }

        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'bme_listings';

            if ($is_resync) {
                $wpdb->delete($table_name, ['source_extraction_id' => $post_id], ['%d']);
                update_post_meta($post_id, '_bme_last_modified', '1970-01-01T00:00:00Z');
            }

            $filter_query = $this->build_filter_query($post_id, $is_resync);
            $new_last_modified = get_post_meta($post_id, '_bme_last_modified', true);
            
            $total_listings_processed = 0;
            $all_processed_info = [];
            $top = 100;

            $initial_query_args = [
                'access_token' => $this->api_token,
                '$filter'      => $filter_query,
                '$top'         => $top,
                '$orderby'     => 'ModificationTimestamp asc'
            ];
            
            $next_link = add_query_arg($initial_query_args, $this->base_api_url);

            do {
                $response = wp_remote_get($next_link, ['timeout' => 60]);

                if (is_wp_error($response)) {
                    throw new Exception("API Request Error: " . $response->get_error_message());
                }

                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);

                if (isset($data['error'])) {
                    throw new Exception("API Error: " . ($data['error']['message'] ?? 'Unknown API Error'));
                }

                if (!empty($data['value'])) {
                    $batch_listings = $data['value'];
                    
                    $agent_ids = [];
                    $office_ids = [];
                    $open_house_listing_keys = [];

                    foreach ($batch_listings as $listing) {
                        if (!empty($listing['ListAgentMlsId'])) $agent_ids[] = $listing['ListAgentMlsId'];
                        if (!empty($listing['BuyerAgentMlsId'])) $agent_ids[] = $listing['BuyerAgentMlsId'];
                        if (!empty($listing['ListOfficeMlsId'])) $office_ids[] = $listing['ListOfficeMlsId'];
                        if (!empty($listing['BuyerOfficeMlsId'])) $office_ids[] = $listing['BuyerOfficeMlsId'];
                        if (!empty($listing['ListingKey'])) {
                            $open_house_listing_keys[] = $listing['ListingKey'];
                        }
                    }

                    $agents_map = $this->fetch_related_data('Member', 'MemberMlsId', array_unique($agent_ids));
                    $offices_map = $this->fetch_related_data('Office', 'OfficeMlsId', array_unique($office_ids));
                    $open_houses_map = $this->fetch_related_data('OpenHouse', 'ListingKey', array_unique($open_house_listing_keys), true);

                    $processed_info = $this->process_listings($post_id, $batch_listings, $agents_map, $offices_map, $open_houses_map);
                    
                    $all_processed_info = array_merge($all_processed_info, $processed_info);
                    $total_listings_processed += count($batch_listings);

                    $last_listing = end($batch_listings);
                    if (isset($last_listing['ModificationTimestamp'])) {
                        $new_last_modified = $last_listing['ModificationTimestamp'];
                    }
                }
                
                $next_link = isset($data['@odata.nextLink']) ? $data['@odata.nextLink'] : null;
                
                if ($next_link) {
                    sleep(1);
                }

            } while ($next_link);

            if ($total_listings_processed > 0) {
                update_post_meta($post_id, '_bme_last_modified', $new_last_modified);
            }

            $run_type = $is_resync ? 'Full Re-sync' : 'Standard Run';
            $message = sprintf('%s completed. %d listings were added or updated.', $run_type, $total_listings_processed);
            $this->log_activity($post_id, 'Success', $message, $total_listings_processed, $all_processed_info);

            return true;

        } catch (Exception $e) {
            $this->log_activity($post_id, 'Failure', $e->getMessage(), 0, []);
            return false;
        }
    }

    private function fetch_related_data($resource, $key_field, $ids, $group_results = false) {
        if (empty($ids)) {
            return [];
        }

        $resource_url = str_replace('/Property', '/' . $resource, $this->base_api_url);
        $results_map = [];
        $id_chunks = array_chunk($ids, 50);

        foreach ($id_chunks as $chunk) {
            $filter_values = "'" . implode("','", array_map('esc_sql', $chunk)) . "'";
            $query_args = [
                'access_token' => $this->api_token,
                '$filter'      => "$key_field in ($filter_values)",
                '$top'         => 200
            ];

            $next_link = add_query_arg($query_args, $resource_url);

            do {
                $response = wp_remote_get($next_link, ['timeout' => 45]);
                if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                    $next_link = null;
                    continue;
                }

                $data = json_decode(wp_remote_retrieve_body($response), true);

                if (!empty($data['value'])) {
                    foreach ($data['value'] as $item) {
                        if (isset($item[$key_field])) {
                            $key = $item[$key_field];
                            if ($group_results) {
                                if (!isset($results_map[$key])) {
                                    $results_map[$key] = [];
                                }
                                $results_map[$key][] = $item;
                            } else {
                                $results_map[$key] = $item;
                            }
                        }
                    }
                }
                $next_link = isset($data['@odata.nextLink']) ? $data['@odata.nextLink'] : null;
                if ($next_link) sleep(1);

            } while ($next_link);
        }

        return $results_map;
    }

    private function build_filter_query($post_id, $is_resync) {
        $statuses = get_post_meta($post_id, '_bme_statuses', true) ?: [];
        $property_types = get_post_meta($post_id, '_bme_property_types', true) ?: [];
        $cities = get_post_meta($post_id, '_bme_cities', true);
        $states = get_post_meta($post_id, '_bme_states', true) ?: [];
        $list_agent_id = get_post_meta($post_id, '_bme_list_agent_id', true);
        $buyer_agent_id = get_post_meta($post_id, '_bme_buyer_agent_id', true);
        $closed_lookback_months = get_post_meta($post_id, '_bme_closed_lookback_months', true);

        $filters = [];

        if (!empty($statuses)) {
            $status_filters = array_map(fn($s) => "StandardStatus eq '" . $s . "'", $statuses);
            $filters[] = count($status_filters) > 1 ? "(" . implode(' or ', $status_filters) . ")" : $status_filters[0];
        }

        if (!empty($property_types)) {
            $type_filters = array_map(fn($t) => "PropertyType eq '" . $t . "'", $property_types);
            $filters[] = count($type_filters) > 1 ? "(" . implode(' or ', $type_filters) . ")" : $type_filters[0];
        }
        
        if (!empty($cities)) {
            $cities_array = array_map('trim', explode(',', $cities));
            $city_filters = array_map(fn($c) => "City eq '" . $c . "'", $cities_array);
            $filters[] = count($city_filters) > 1 ? "(" . implode(' or ', $city_filters) . ")" : $city_filters[0];
        }

        if (!empty($states)) {
            $state_filters = array_map(fn($s) => "StateOrProvince eq '" . $s . "'", $states);
            $filters[] = count($state_filters) > 1 ? "(" . implode(' or ', $state_filters) . ")" : $state_filters[0];
        }

        if (!empty($list_agent_id)) {
            $filters[] = "toupper(ListAgentMlsId) eq '" . strtoupper($list_agent_id) . "'";
        }
        $applicable_agent_statuses = ['Active Under Contract', 'Pending', 'Closed'];
        if (!empty($buyer_agent_id) && !empty(array_intersect($applicable_agent_statuses, $statuses))) {
            $filters[] = "toupper(BuyerAgentMlsId) eq '" . strtoupper($buyer_agent_id) . "'";
        }

        $is_historical_closed_search = in_array('Closed', $statuses) && !empty($closed_lookback_months);

        if ($is_historical_closed_search) {
            $lookback_months = absint($closed_lookback_months);
            $date = new DateTime('now', new DateTimeZone('UTC'));
            $date->modify("-{$lookback_months} months");
            $iso_date = $date->format('Y-m-d\TH:i:s\Z');
            $filters[] = "CloseDate ge " . $iso_date;
        }

        if (!$is_resync && !$is_historical_closed_search) {
            $last_modified = get_post_meta($post_id, '_bme_last_modified', true) ?: '1970-01-01T00:00:00Z';
            $filters[] = "ModificationTimestamp gt " . $last_modified;
        }

        return implode(' and ', $filters);
    }

    private function process_listings($post_id, $listings, $agents_map, $offices_map, $open_houses_map) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        $table_columns = BME_DB::get_table_columns();
        $processed_info = [];

        foreach ($listings as $listing) {
            $data = ['source_extraction_id' => $post_id];
            $additional_data = [];
            
            // Iterate over the full API response for a listing.
            foreach ($listing as $key => $value) {
                // If a dedicated column exists, save it there.
                if (in_array($key, $table_columns)) {
                    if (is_array($value)) {
                        $data[$key] = json_encode($value);
                    } else if (is_bool($value)) {
                        $data[$key] = $value ? 1 : 0;
                    } else {
                        $data[$key] = ($value !== null && $value !== '') ? $value : null;
                    }
                } 
                // Otherwise, add it to our "catch-all" array.
                else {
                    $additional_data[$key] = $value;
                }
            }
            
            // Save the "catch-all" data as a single JSON string.
            if (!empty($additional_data)) {
                $data['AdditionalData'] = json_encode($additional_data);
            }

            if (!empty($listing['ListAgentMlsId']) && isset($agents_map[$listing['ListAgentMlsId']])) {
                $data['ListAgentData'] = json_encode($agents_map[$listing['ListAgentMlsId']]);
            }
            if (!empty($listing['BuyerAgentMlsId']) && isset($agents_map[$listing['BuyerAgentMlsId']])) {
                $data['BuyerAgentData'] = json_encode($agents_map[$listing['BuyerAgentMlsId']]);
            }
            if (!empty($listing['ListOfficeMlsId']) && isset($offices_map[$listing['ListOfficeMlsId']])) {
                $data['ListOfficeData'] = json_encode($offices_map[$listing['ListOfficeMlsId']]);
            }
            if (!empty($listing['BuyerOfficeMlsId']) && isset($offices_map[$listing['BuyerOfficeMlsId']])) {
                $data['BuyerOfficeData'] = json_encode($offices_map[$listing['BuyerOfficeMlsId']]);
            }
            if (!empty($listing['ListingKey']) && isset($open_houses_map[$listing['ListingKey']])) {
                $data['OpenHouseData'] = json_encode($open_houses_map[$listing['ListingKey']]);
            }

            if (empty($data['ListingKey'])) continue;

            $wpdb->replace($table_name, $data);

            if (isset($listing['Latitude'], $listing['Longitude']) && is_numeric($listing['Latitude']) && is_numeric($listing['Longitude'])) {
                $lat = (float) $listing['Latitude'];
                $lon = (float) $listing['Longitude'];
                $listing_key = $listing['ListingKey'];
                $wpdb->query($wpdb->prepare("UPDATE `$table_name` SET `Coordinates` = ST_PointFromText(%s) WHERE `ListingKey` = %s", "POINT($lon $lat)", $listing_key));
            }

            $address = trim(sprintf('%s %s, %s, %s %s', $listing['StreetNumber'] ?? '', $listing['StreetName'] ?? '', $listing['City'] ?? '', $listing['StateOrProvince'] ?? '', $listing['PostalCode'] ?? ''));
            $processed_info[] = ['mls_number' => $listing['ListingId'] ?? 'N/A', 'address' => $address];
        }
        return $processed_info;
    }

    private function log_activity($extraction_id, $status, $message, $count, $processed_listings) {
        $log_title = sprintf('Extraction "%s" - %s', get_the_title($extraction_id), $status);
        
        $log_post = [
            'post_title'   => $log_title,
            'post_content' => $message,
            'post_type'    => 'bme_log',
            'post_status'  => 'publish',
        ];
        $log_id = wp_insert_post($log_post);

        if ($log_id && !is_wp_error($log_id)) {
            update_post_meta($log_id, '_bme_log_extraction_id', $extraction_id);
            update_post_meta($log_id, '_bme_log_status', $status);
            update_post_meta($log_id, '_bme_log_listings_count', $count);
            if (!empty($processed_listings)) {
                update_post_meta($log_id, '_bme_log_processed_listings', $processed_listings);
            }

            update_post_meta($extraction_id, '_bme_last_run_status', $status);
            update_post_meta($extraction_id, '_bme_last_run_time', time());
        }
    }
}
