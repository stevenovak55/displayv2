<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class BME_Extraction
 *
 * The core engine for fetching and processing MLS data from the Bridge API.
 */
class BME_Extraction {

    /**
     * Constructor.
     */
    public function __construct() {
        // Intentionally left blank.
    }

    /**
     * Runs a single extraction profile.
     *
     * @param int  $post_id The ID of the bme_extraction post.
     * @param bool $is_resync If true, deletes existing data for this profile first.
     * @return bool True on success, false on failure.
     */
    public function run_single_extraction($post_id, $is_resync = false) {
        // Fetch credentials at the moment of execution for reliability.
        $options = get_option('bme_api_credentials');
        $api_token = isset($options['server_token']) ? $options['server_token'] : null;
        $api_url = isset($options['endpoint_url']) ? $options['endpoint_url'] : null;

        if (!$api_token || !$api_url) {
            $this->log_activity($post_id, 'Failure', 'API credentials could not be retrieved. Please check the Settings page and save your credentials again.', 0, []);
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
            $select_fields = get_post_meta($post_id, '_bme_select_fields', true);
            $new_last_modified = get_post_meta($post_id, '_bme_last_modified', true);
            
            $all_listings = [];
            $top = 100; // API batch size

            // Build the initial request URL
            $initial_query_args = [
                'access_token' => $api_token,
                '$filter'      => $filter_query,
                '$top'         => $top,
                '$orderby'     => 'ModificationTimestamp asc',
            ];

            if (!empty($select_fields)) {
                $initial_query_args['$select'] = $select_fields;
            }
            
            $next_link = add_query_arg($initial_query_args, $api_url);

            // Loop using the API's nextLink for pagination
            do {
                $response = wp_remote_get($next_link, ['timeout' => 60]);

                if (is_wp_error($response)) {
                    throw new Exception("API Request Error: " . $response->get_error_message());
                }

                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);

                if (isset($data['error'])) {
                    $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown API Error';
                    throw new Exception("API Error: " . $error_message);
                }

                if (!empty($data['value'])) {
                    $all_listings = array_merge($all_listings, $data['value']);
                    $last_listing = end($data['value']);

                    if (isset($last_listing['ModificationTimestamp'])) {
                        $new_last_modified = $last_listing['ModificationTimestamp'];
                    }
                }
                
                // Get the nextLink for the subsequent request
                $next_link = isset($data['@odata.nextLink']) ? $data['@odata.nextLink'] : null;
                
                // If there's another page, pause to respect rate limits
                if ($next_link) {
                    sleep(1);
                }

            } while ($next_link);


            $processed_listings_info = [];
            if (!empty($all_listings)) {
                $processed_listings_info = $this->process_listings($post_id, $all_listings);
                update_post_meta($post_id, '_bme_last_modified', $new_last_modified);
            }

            $run_type = $is_resync ? 'Full Re-sync' : 'Standard Run';
            $message = sprintf('%s completed. %d listings were added or updated.', $run_type, count($all_listings));
            $this->log_activity($post_id, 'Success', $message, count($all_listings), $processed_listings_info);

            return true;

        } catch (Exception $e) {
            $this->log_activity($post_id, 'Failure', $e->getMessage(), 0, []);
            return false;
        }
    }

    /**
     * Builds the OData $filter query string based on extraction settings.
     */
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

    /**
     * Inserts or updates listings in the database.
     */
    private function process_listings($post_id, $listings) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        $table_columns = BME_DB::get_table_columns();
        $processed_info = [];

        foreach ($listings as $listing) {
            $data = ['source_extraction_id' => $post_id];
            
            foreach ($listing as $key => $value) {
                if (in_array($key, $table_columns)) {
                    if (is_array($value)) {
                        $data[$key] = json_encode($value);
                    } else if (is_bool($value)) {
                        $data[$key] = $value ? 1 : 0;
                    } else {
                        $data[$key] = ($value !== null && $value !== '') ? $value : null;
                    }
                }
            }

            if (empty($data['ListingKey'])) continue;

            $wpdb->replace($table_name, $data);

            // Now, update the new Coordinates field for the record we just inserted/updated.
            if (isset($listing['Latitude'], $listing['Longitude']) && is_numeric($listing['Latitude']) && is_numeric($listing['Longitude'])) {
                $lat = (float) $listing['Latitude'];
                $lon = (float) $listing['Longitude'];
                $listing_key = $listing['ListingKey'];

                // Note: ST_PointFromText uses WKT format 'POINT(lon lat)'
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE `$table_name` SET `Coordinates` = ST_PointFromText(%s) WHERE `ListingKey` = %s",
                        "POINT($lon $lat)",
                        $listing_key
                    )
                );
            }

            $address = trim(sprintf('%s %s, %s, %s %s',
                $listing['StreetNumber'] ?? '',
                $listing['StreetName'] ?? '',
                $listing['City'] ?? '',
                $listing['StateOrProvince'] ?? '',
                $listing['PostalCode'] ?? ''
            ));
            $processed_info[] = [
                'mls_number' => $listing['ListingId'] ?? 'N/A',
                'address'    => $address,
            ];
        }
        return $processed_info;
    }

    /**
     * Logs the result of an extraction run.
     */
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
