<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * High-performance API client with concurrent request capabilities
 */
class BME_API_Client {
    
    private $api_token;
    private $base_url;
    private $timeout;
    private $max_concurrent = 5;
    private $rate_limit_delay = 1; // seconds
    
    public function __construct() {
        $credentials = get_option('bme_pro_api_credentials', []);
        $this->api_token = $credentials['server_token'] ?? null;
        $this->base_url = $credentials['endpoint_url'] ?? null;
        $this->timeout = BME_API_TIMEOUT;
    }
    
    /**
     * Validate API credentials
     */
    public function validate_credentials() {
        if (empty($this->api_token) || empty($this->base_url)) {
            throw new Exception('API credentials not configured');
        }
        
        // Test connection with a simple query
        $test_url = add_query_arg([
            'access_token' => $this->api_token,
            '$top' => 1
        ], $this->base_url);
        
        $response = wp_remote_get($test_url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);
        
        if (is_wp_error($response)) {
            throw new Exception('API connection failed: ' . $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new Exception('API authentication failed. Response code: ' . $code);
        }
        
        return true;
    }
    
    /**
     * Fetch listings with pagination and filtering
     */
    public function fetch_listings($filters, $callback = null) {
        $this->validate_credentials();
        
        $query_args = [
            'access_token' => $this->api_token,
            '$filter' => $filters,
            '$top' => BME_BATCH_SIZE,
            '$orderby' => 'ModificationTimestamp asc'
        ];
        
        $next_url = add_query_arg($query_args, $this->base_url);
        $total_processed = 0;
        
        do {
            $start_time = microtime(true);
            
            $response = $this->make_request($next_url);
            $data = $this->parse_response($response);
            
            if (!empty($data['value'])) {
                $batch_count = count($data['value']);
                $total_processed += $batch_count;
                
                // Call the callback function with the batch data
                if ($callback && is_callable($callback)) {
                    $callback($data['value'], $total_processed);
                }
                
                // Log performance metrics
                $duration = microtime(true) - $start_time;
                $this->log_performance_metric('listings_batch', $batch_count, $duration);
            }
            
            $next_url = $data['@odata.nextLink'] ?? null;
            
            if ($next_url) {
                sleep($this->rate_limit_delay);
            }
            
        } while ($next_url);
        
        return $total_processed;
    }
    
    /**
     * Fetch related data concurrently (agents, offices, open houses)
     */
    public function fetch_related_data_concurrent($agents_ids, $offices_ids, $listing_keys) {
        $requests = [];
        $results = [
            'agents' => [],
            'offices' => [],
            'open_houses' => []
        ];
        
        // Prepare agent requests
        if (!empty($agents_ids)) {
            foreach (array_chunk(array_unique($agents_ids), 50) as $chunk) {
                $requests[] = $this->prepare_related_request('Member', 'MemberMlsId', $chunk, 'agents');
            }
        }
        
        // Prepare office requests
        if (!empty($offices_ids)) {
            foreach (array_chunk(array_unique($offices_ids), 50) as $chunk) {
                $requests[] = $this->prepare_related_request('Office', 'OfficeMlsId', $chunk, 'offices');
            }
        }
        
        // Prepare open house requests
        if (!empty($listing_keys)) {
            foreach (array_chunk(array_unique($listing_keys), 50) as $chunk) {
                $requests[] = $this->prepare_related_request('OpenHouse', 'ListingKey', $chunk, 'open_houses');
            }
        }
        
        // Execute concurrent requests
        if (!empty($requests)) {
            $responses = $this->execute_concurrent_requests($requests);
            $results = $this->process_concurrent_responses($responses);
        }
        
        return $results;
    }
    
    /**
     * Prepare a related data request
     */
    private function prepare_related_request($resource, $key_field, $ids, $type) {
        $resource_url = str_replace('/Property', '/' . $resource, $this->base_url);
        $filter_values = "'" . implode("','", array_map('esc_sql', $ids)) . "'";
        
        $query_args = [
            'access_token' => $this->api_token,
            '$filter' => "{$key_field} in ({$filter_values})",
            '$top' => 200
        ];
        
        return [
            'url' => add_query_arg($query_args, $resource_url),
            'type' => $type,
            'key_field' => $key_field,
            'group_results' => ($type === 'open_houses')
        ];
    }
    
    /**
     * Execute multiple requests concurrently
     */
    private function execute_concurrent_requests($requests) {
        $responses = [];
        $request_chunks = array_chunk($requests, $this->max_concurrent);
        
        foreach ($request_chunks as $chunk) {
            $multi_requests = [];
            
            foreach ($chunk as $index => $request) {
                $multi_requests[$index] = [
                    'url' => $request['url'],
                    'args' => [
                        'timeout' => $this->timeout,
                        'headers' => ['Accept' => 'application/json']
                    ]
                ];
            }
            
            // Use WordPress's built-in concurrent request handling
            $chunk_responses = $this->make_concurrent_requests($multi_requests);
            
            foreach ($chunk_responses as $index => $response) {
                $responses[] = [
                    'response' => $response,
                    'request' => $chunk[$index]
                ];
            }
            
            // Rate limiting between chunks
            if (count($request_chunks) > 1) {
                sleep($this->rate_limit_delay);
            }
        }
        
        return $responses;
    }
    
    /**
     * Make concurrent HTTP requests
     */
    private function make_concurrent_requests($requests) {
        $responses = [];
        
        // For WordPress, we'll use curl_multi for true concurrency
        $multi_handle = curl_multi_init();
        $curl_handles = [];
        
        foreach ($requests as $index => $request) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $request['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $request['args']['timeout'],
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: WordPress/' . get_bloginfo('version') . '; ' . home_url()
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3
            ]);
            
            curl_multi_add_handle($multi_handle, $ch);
            $curl_handles[$index] = $ch;
        }
        
        // Execute all requests
        $running = null;
        do {
            curl_multi_exec($multi_handle, $running);
            curl_multi_select($multi_handle);
        } while ($running > 0);
        
        // Collect responses
        foreach ($curl_handles as $index => $ch) {
            $response_body = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            $responses[$index] = [
                'body' => $response_body,
                'response' => ['code' => $http_code],
                'error' => $error
            ];
            
            curl_multi_remove_handle($multi_handle, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($multi_handle);
        
        return $responses;
    }
    
    /**
     * Process concurrent responses
     */
    private function process_concurrent_responses($responses) {
        $results = [
            'agents' => [],
            'offices' => [],
            'open_houses' => []
        ];
        
        foreach ($responses as $response_data) {
            $response = $response_data['response'];
            $request = $response_data['request'];
            
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                continue;
            }
            
            $data = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!empty($data['value'])) {
                $type = $request['type'];
                $key_field = $request['key_field'];
                $group_results = $request['group_results'];
                
                foreach ($data['value'] as $item) {
                    if (isset($item[$key_field])) {
                        $key = $item[$key_field];
                        
                        if ($group_results) {
                            if (!isset($results[$type][$key])) {
                                $results[$type][$key] = [];
                            }
                            $results[$type][$key][] = $item;
                        } else {
                            $results[$type][$key] = $item;
                        }
                    }
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Make a single HTTP request
     */
    private function make_request($url) {
        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);
        
        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new Exception("API request failed with code {$code}");
        }
        
        return $response;
    }
    
    /**
     * Parse API response
     */
    private function parse_response($response) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response: ' . json_last_error_msg());
        }
        
        if (isset($data['error'])) {
            throw new Exception('API Error: ' . ($data['error']['message'] ?? 'Unknown error'));
        }
        
        return $data;
    }
    
    /**
     * Build OData filter query
     */
    public function build_filter_query($extraction_config) {
        $filters = [];
        
        // Debug logging
        error_log('BME Debug - Building filter with config: ' . print_r($extraction_config, true));
        
        // Status filters - REQUIRED
        if (!empty($extraction_config['statuses'])) {
            $status_filters = array_map(function($status) {
                return "StandardStatus eq '{$status}'";
            }, $extraction_config['statuses']);
            
            $filters[] = count($status_filters) > 1 
                ? '(' . implode(' or ', $status_filters) . ')'
                : $status_filters[0];
        } else {
            // If no statuses specified, default to Active to prevent pulling everything
            $filters[] = "StandardStatus eq 'Active'";
            error_log('BME Debug - No statuses specified, defaulting to Active only');
        }
        
        // City filters
        if (!empty($extraction_config['cities'])) {
            $cities_raw = $extraction_config['cities'];
            error_log('BME Debug - Raw cities value: ' . $cities_raw);
            
            if (is_string($cities_raw) && !empty(trim($cities_raw))) {
                $cities = array_map('trim', explode(',', $cities_raw));
                $cities = array_filter($cities); // Remove empty values
                
                if (!empty($cities)) {
                    $city_filters = array_map(function($city) {
                        return "City eq '" . str_replace("'", "''", trim($city)) . "'";
                    }, $cities);
                    
                    $filters[] = count($city_filters) > 1
                        ? '(' . implode(' or ', $city_filters) . ')'
                        : $city_filters[0];
                        
                    error_log('BME Debug - Added city filter: ' . end($filters));
                }
            }
        }
        
        // State filters
        if (!empty($extraction_config['states'])) {
            $state_filters = array_map(function($state) {
                return "StateOrProvince eq '{$state}'";
            }, $extraction_config['states']);
            
            $filters[] = count($state_filters) > 1
                ? '(' . implode(' or ', $state_filters) . ')'
                : $state_filters[0];
        }
        
        // Agent filters
        if (!empty($extraction_config['list_agent_id'])) {
            $filters[] = "toupper(ListAgentMlsId) eq '" . strtoupper($extraction_config['list_agent_id']) . "'";
        }
        
        if (!empty($extraction_config['buyer_agent_id'])) {
            $applicable_statuses = ['Active Under Contract', 'Pending', 'Closed'];
            if (!empty(array_intersect($applicable_statuses, $extraction_config['statuses'] ?? []))) {
                $filters[] = "toupper(BuyerAgentMlsId) eq '" . strtoupper($extraction_config['buyer_agent_id']) . "'";
            }
        }
        
        // Date filters
        if (!$extraction_config['is_resync']) {
            $last_modified = $extraction_config['last_modified'] ?? '1970-01-01T00:00:00Z';
            $filters[] = "ModificationTimestamp gt {$last_modified}";
        }
        
        // Closed listings lookback
        if (in_array('Closed', $extraction_config['statuses'] ?? []) && !empty($extraction_config['closed_lookback_months'])) {
            $months = absint($extraction_config['closed_lookback_months']);
            $date = new DateTime('now', new DateTimeZone('UTC'));
            $date->modify("-{$months} months");
            $iso_date = $date->format('Y-m-d\TH:i:s\Z');
            $filters[] = "CloseDate ge {$iso_date}";
        }
        
        $final_filter = implode(' and ', $filters);
        error_log('BME Debug - Final filter query: ' . $final_filter);
        
        return $final_filter;
    }
    
    /**
     * Log performance metrics
     */
    private function log_performance_metric($operation, $count, $duration) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'BME API Performance - %s: %d items in %.3f seconds (%.2f items/sec)',
                $operation,
                $count,
                $duration,
                $count / max($duration, 0.001)
            ));
        }
    }
    
    /**
     * Get API client configuration
     */
    public function get_config() {
        return [
            'has_credentials' => !empty($this->api_token) && !empty($this->base_url),
            'timeout' => $this->timeout,
            'max_concurrent' => $this->max_concurrent,
            'rate_limit_delay' => $this->rate_limit_delay
        ];
    }
    
    /**
     * Update API configuration
     */
    public function update_config($config) {
        if (isset($config['timeout'])) {
            $this->timeout = max(30, intval($config['timeout']));
        }
        
        if (isset($config['max_concurrent'])) {
            $this->max_concurrent = max(1, min(10, intval($config['max_concurrent'])));
        }
        
        if (isset($config['rate_limit_delay'])) {
            $this->rate_limit_delay = max(0.5, floatval($config['rate_limit_delay']));
        }
    }
}