<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optimized admin interface with enhanced performance and UX
 */
class BME_Admin {
    
    private $plugin;
    private $cache_manager;
    
    public function __construct(Bridge_MLS_Extractor_Pro $plugin) {
        $this->plugin = $plugin;
        $this->cache_manager = $plugin->get('cache');
        $this->init_hooks();
    }
    
    /**
     * Initialize admin hooks
     */
    private function init_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menus']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // Meta boxes for extraction CPT
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_bme_extraction', [$this, 'save_extraction_meta']);
        
        // Custom columns for extraction list
        add_filter('manage_bme_extraction_posts_columns', [$this, 'set_extraction_columns']);
        add_action('manage_bme_extraction_posts_custom_column', [$this, 'display_extraction_column'], 10, 2);
        
        // Admin actions
        add_action('admin_post_bme_run_extraction', [$this, 'handle_run_extraction']);
        add_action('admin_post_bme_run_resync', [$this, 'handle_run_resync']);
        add_action('admin_post_bme_clear_data', [$this, 'handle_clear_data']);
        add_action('admin_post_bme_test_config', [$this, 'handle_test_config']);
        add_action('admin_post_bme_export_listings_csv', [$this, 'handle_export_listings_csv']); // New export handler
        
        // AJAX handlers
        add_action('wp_ajax_bme_get_filter_values', [$this, 'ajax_get_filter_values']);
        add_action('wp_ajax_bme_search_listings', [$this, 'ajax_search_listings']);
        add_action('wp_ajax_bme_get_extraction_stats', [$this, 'ajax_get_extraction_stats']);
        
        // Admin notices
        add_action('admin_notices', [$this, 'display_admin_notices']);
    }
    
    /**
     * Add admin menus
     */
    public function add_admin_menus() {
        // Main menu
        add_menu_page(
            __('MLS Extractions Pro', 'bridge-mls-extractor-pro'),
            __('MLS Extractions', 'bridge-mls-extractor-pro'),
            'manage_options',
            'edit.php?post_type=bme_extraction',
            '',
            'dashicons-database-export',
            25
        );
        
        // Database browser - FIX: Use proper parent menu
        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Database Browser', 'bridge-mls-extractor-pro'),
            __('Database Browser', 'bridge-mls-extractor-pro'),
            'manage_options',
            'bme-database-browser',
            [$this, 'render_database_browser']
        );
        
        // Performance dashboard
        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Performance Dashboard', 'bridge-mls-extractor-pro'),
            __('Performance Dashboard', 'bridge-mls-extractor-pro'),
            'manage_options',
            'bme-performance',
            [$this, 'render_performance_dashboard']
        );
        
        // Activity logs
        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Activity Logs', 'bridge-mls-extractor-pro'),
            __('Activity Logs', 'bridge-mls-extractor-pro'),
            'manage_options',
            'bme-activity-logs',
            [$this, 'render_activity_logs']
        );
        
        // Settings
        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Settings', 'bridge-mls-extractor-pro'),
            __('Settings', 'bridge-mls-extractor-pro'),
            'manage_options',
            'bme-settings',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Enqueue admin assets with cache busting
     */
    public function enqueue_admin_assets($hook) {
        $screen = get_current_screen();
        
        if (!$screen || strpos($screen->id, 'bme') === false) {
            return;
        }
        
        $version = BME_PRO_VERSION . '.' . filemtime(BME_PLUGIN_DIR . 'assets/admin.css');
        
        // CSS
        wp_enqueue_style(
            'bme-admin',
            BME_PLUGIN_URL . 'assets/admin.css',
            [],
            $version
        );
        
        // JavaScript
        wp_enqueue_script(
            'bme-admin',
            BME_PLUGIN_URL . 'assets/admin.js',
            ['jquery', 'wp-util'],
            $version,
            true
        );
        
        // Localize script
        wp_localize_script('bme-admin', 'bmeAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bme_admin_nonce'),
            'strings' => [
                'confirmClear' => __('Are you sure? This will delete all data for this extraction.', 'bridge-mls-extractor-pro'),
                'confirmResync' => __('This will clear existing data and re-download everything. Continue?', 'bridge-mls-extractor-pro'),
                'loading' => __('Loading...', 'bridge-mls-extractor-pro'),
                'error' => __('An error occurred. Please try again.', 'bridge-mls-extractor-pro')
            ]
        ]);
        
        // Third-party libraries for specific pages
        if (strpos($hook, 'bme-database-browser') !== false) {
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0-rc.0', true);
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0-rc.0');
        }
        
        if (strpos($hook, 'bme-performance') !== false) {
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', [], '3.9.1', true);
        }
    }
    
    /**
     * Add meta boxes for extraction CPT
     */
    public function add_meta_boxes() {
        add_meta_box(
            'bme_extraction_config',
            __('Extraction Configuration', 'bridge-mls-extractor-pro'),
            [$this, 'render_extraction_config_meta_box'],
            'bme_extraction',
            'normal',
            'high'
        );
        
        add_meta_box(
            'bme_extraction_stats',
            __('Statistics & Performance', 'bridge-mls-extractor-pro'),
            [$this, 'render_extraction_stats_meta_box'],
            'bme_extraction',
            'side',
            'default'
        );
    }
    
    /**
     * Render extraction configuration meta box
     */
    public function render_extraction_config_meta_box($post) {
        wp_nonce_field('bme_save_extraction_meta', 'bme_extraction_nonce');
        
        // Get saved values
        $config = [
            'statuses' => get_post_meta($post->ID, '_bme_statuses', true) ?: [],
            'cities' => get_post_meta($post->ID, '_bme_cities', true),
            'states' => get_post_meta($post->ID, '_bme_states', true) ?: [],
            'list_agent_id' => get_post_meta($post->ID, '_bme_list_agent_id', true),
            'buyer_agent_id' => get_post_meta($post->ID, '_bme_buyer_agent_id', true),
            'closed_lookback_months' => get_post_meta($post->ID, '_bme_closed_lookback_months', true) ?: 12,
            'schedule' => get_post_meta($post->ID, '_bme_schedule', true) ?: 'none'
        ];
        
        ?>
        <table class="form-table bme-config-table">
            <tr>
                <th><label for="bme_schedule"><?php _e('Schedule', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <select name="bme_schedule" id="bme_schedule" class="regular-text">
                        <?php
                        $schedules = array_merge(['none' => ['display' => __('Manual Only', 'bridge-mls-extractor-pro')]], wp_get_schedules());
                        $allowed_schedules = ['none', 'every_15_minutes', 'every_30_minutes', 'hourly', 'twicedaily', 'daily'];
                        
                        foreach ($schedules as $key => $details) {
                            if (in_array($key, $allowed_schedules)) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($key),
                                    selected($config['schedule'], $key, false),
                                    esc_html($details['display'])
                                );
                            }
                        }
                        ?>
                    </select>
                    <p class="description"><?php _e('Automatic extraction frequency', 'bridge-mls-extractor-pro'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th><label><?php _e('Listing Statuses', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <fieldset id="bme-statuses">
                        <?php
                        $status_options = ['Active', 'Active Under Contract', 'Pending', 'Closed', 'Expired', 'Withdrawn', 'Canceled'];
                        foreach ($status_options as $status) {
                            printf(
                                '<label><input type="checkbox" name="bme_statuses[]" value="%s" %s> %s</label><br>',
                                esc_attr($status),
                                checked(in_array($status, $config['statuses']), true, false),
                                esc_html($status)
                            );
                        }
                        ?>
                    </fieldset>
                </td>
            </tr>
            
            <tr id="bme-closed-lookback" style="display: none;">
                <th><label for="bme_closed_lookback_months"><?php _e('Closed Listings Lookback', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <input type="number" name="bme_closed_lookback_months" id="bme_closed_lookback_months" 
                           value="<?php echo esc_attr($config['closed_lookback_months']); ?>" 
                           class="small-text" min="1" max="120" step="1">
                    <span><?php _e('months', 'bridge-mls-extractor-pro'); ?></span>
                    <p class="description"><?php _e('How many months back to search for closed listings', 'bridge-mls-extractor-pro'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th><label for="bme_cities"><?php _e('Cities', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <textarea name="bme_cities" id="bme_cities" rows="3" class="large-text" 
                              placeholder="Boston, Cambridge, Somerville"><?php echo esc_textarea($config['cities']); ?></textarea>
                    <p class="description"><?php _e('Comma-separated list of cities. Leave blank for all cities.', 'bridge-mls-extractor-pro'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th><label><?php _e('States/Provinces', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <fieldset>
                        <?php
                        $state_options = ['MA', 'NH', 'RI', 'VT', 'CT', 'ME'];
                        foreach ($state_options as $state) {
                            printf(
                                '<label><input type="checkbox" name="bme_states[]" value="%s" %s> %s</label> ',
                                esc_attr($state),
                                checked(in_array($state, $config['states']), true, false),
                                esc_html($state)
                            );
                        }
                        ?>
                    </fieldset>
                </td>
            </tr>
            
            <tr>
                <th><label for="bme_list_agent_id"><?php _e('List Agent MLS ID', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <input type="text" name="bme_list_agent_id" id="bme_list_agent_id" 
                           value="<?php echo esc_attr($config['list_agent_id']); ?>" class="regular-text">
                    <p class="description"><?php _e('Filter by listing agent MLS ID', 'bridge-mls-extractor-pro'); ?></p>
                </td>
            </tr>
            
            <tr id="bme-buyer-agent" style="display: none;">
                <th><label for="bme_buyer_agent_id"><?php _e('Buyer Agent MLS ID', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <input type="text" name="bme_buyer_agent_id" id="bme_buyer_agent_id" 
                           value="<?php echo esc_attr($config['buyer_agent_id']); ?>" class="regular-text">
                    <p class="description"><?php _e('Filter by buyer agent MLS ID (for contracted/pending/closed listings)', 'bridge-mls-extractor-pro'); ?></p>
                </td>
            </tr>
        </table>
        
        <script>
        jQuery(document).ready(function($) {
            function toggleConditionalFields() {
                var selectedStatuses = [];
                $('#bme-statuses input:checked').each(function() {
                    selectedStatuses.push($(this).val());
                });
                
                // Show buyer agent field for applicable statuses
                if (selectedStatuses.includes('Active Under Contract') || 
                    selectedStatuses.includes('Pending') || 
                    selectedStatuses.includes('Closed')) {
                    $('#bme-buyer-agent').show();
                } else {
                    $('#bme-buyer-agent').hide();
                }
                
                // Show closed lookback field for closed status
                if (selectedStatuses.includes('Closed')) {
                    $('#bme-closed-lookback').show();
                } else {
                    $('#bme-closed-lookback').hide();
                }
            }
            
            toggleConditionalFields();
            $('#bme-statuses input').on('change', toggleConditionalFields);
        });
        </script>
        <?php
    }
    
    /**
     * Render extraction statistics meta box
     */
    public function render_extraction_stats_meta_box($post) {
        $stats = $this->cache_manager->get_extraction_stats($post->ID);
        
        if (!$stats) {
            $data_processor = $this->plugin->get('processor');
            $stats = $data_processor->get_extraction_stats($post->ID);
            $this->cache_manager->cache_extraction_stats($post->ID, $stats);
        }
        
        $last_run_status = get_post_meta($post->ID, '_bme_last_run_status', true);
        $last_run_time = get_post_meta($post->ID, '_bme_last_run_time', true);
        $last_run_duration = get_post_meta($post->ID, '_bme_last_run_duration', true);
        $last_run_count = get_post_meta($post->ID, '_bme_last_run_count', true);
        
        ?>
        <div class="bme-stats-grid">
            <div class="bme-stat-item">
                <div class="bme-stat-value"><?php echo esc_html($stats['total_listings'] ?? 0); ?></div>
                <div class="bme-stat-label"><?php _e('Total Listings', 'bridge-mls-extractor-pro'); ?></div>
            </div>
            
            <div class="bme-stat-item">
                <div class="bme-stat-value"><?php echo esc_html($stats['unique_statuses'] ?? 0); ?></div>
                <div class="bme-stat-label"><?php _e('Statuses', 'bridge-mls-extractor-pro'); ?></div>
            </div>
            
            <div class="bme-stat-item">
                <div class="bme-stat-value">$<?php echo esc_html(number_format($stats['avg_price'] ?? 0)); ?></div>
                <div class="bme-stat-label"><?php _e('Avg Price', 'bridge-mls-extractor-pro'); ?></div>
            </div>
            
            <div class="bme-stat-item">
                <div class="bme-stat-value <?php echo $this->get_status_class($last_run_status); ?>">
                    <?php echo esc_html($last_run_status ?: __('Never', 'bridge-mls-extractor-pro')); ?>
                </div>
                <div class="bme-stat-label"><?php _e('Last Run', 'bridge-mls-extractor-pro'); ?></div>
            </div>
            
            <?php if ($last_run_time): ?>
            <div class="bme-stat-item">
                <div class="bme-stat-value"><?php echo esc_html(date('M j, Y g:i A', $last_run_time)); ?></div>
                <div class="bme-stat-label"><?php _e('Last Run Time', 'bridge-mls-extractor-pro'); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if ($last_run_duration): ?>
            <div class="bme-stat-item">
                <div class="bme-stat-value"><?php echo esc_html($last_run_duration); ?>s</div>
                <div class="bme-stat-label"><?php _e('Duration', 'bridge-mls-extractor-pro'); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="bme-actions">
            <?php
            $run_url = wp_nonce_url(admin_url('admin-post.php?action=bme_run_extraction&post_id=' . $post->ID), 'bme_run_extraction_' . $post->ID);
            $resync_url = wp_nonce_url(admin_url('admin-post.php?action=bme_run_resync&post_id=' . $post->ID), 'bme_run_resync_' . $post->ID);
            $clear_url = wp_nonce_url(admin_url('admin-post.php?action=bme_clear_data&post_id=' . $post->ID), 'bme_clear_data_' . $post->ID);
            $test_url = wp_nonce_url(admin_url('admin-post.php?action=bme_test_config&post_id=' . $post->ID), 'bme_test_config_' . $post->ID);
            ?>
            
            <a href="<?php echo esc_url($test_url); ?>" class="button button-secondary">
                <?php _e('Test Config', 'bridge-mls-extractor-pro'); ?>
            </a>
            
            <a href="<?php echo esc_url($run_url); ?>" class="button button-primary">
                <?php _e('Run Now', 'bridge-mls-extractor-pro'); ?>
            </a>
            
            <a href="<?php echo esc_url($resync_url); ?>" class="button button-secondary bme-confirm-resync">
                <?php _e('Full Resync', 'bridge-mls-extractor-pro'); ?>
            </a>
            
            <a href="<?php echo esc_url($clear_url); ?>" class="button button-link-delete bme-confirm-clear">
                <?php _e('Clear Data', 'bridge-mls-extractor-pro'); ?>
            </a>
        </div>
        <?php
    }
    
    /**
     * Save extraction meta data
     */
    public function save_extraction_meta($post_id) {
        if (!isset($_POST['bme_extraction_nonce']) || 
            !wp_verify_nonce($_POST['bme_extraction_nonce'], 'bme_save_extraction_meta')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Debug: Log what we're receiving
        // error_log('BME Debug - POST data: ' . print_r($_POST, true)); // Commented out for production
        
        // Save configuration - Fixed field names to match form
        $fields = [
            '_bme_schedule' => 'bme_schedule',
            '_bme_cities' => 'bme_cities',
            '_bme_list_agent_id' => 'bme_list_agent_id',
            '_bme_buyer_agent_id' => 'bme_buyer_agent_id',
            '_bme_closed_lookback_months' => 'bme_closed_lookback_months'
        ];
        
        foreach ($fields as $meta_key => $post_key) {
            $value = $_POST[$post_key] ?? '';
            
            // Apply appropriate sanitization
            switch ($meta_key) {
                case '_bme_schedule':
                    $value = sanitize_key($value);
                    break;
                case '_bme_cities':
                    $value = sanitize_textarea_field($value);
                    break;
                case '_bme_closed_lookback_months':
                    $value = absint($value);
                    break;
                default:
                    $value = sanitize_text_field($value);
                    break;
            }
            
            update_post_meta($post_id, $meta_key, $value);
            // error_log("BME Debug - Saved {$meta_key}: " . $value); // Commented out for production
        }
        
        // Save array fields
        $statuses = isset($_POST['bme_statuses']) && is_array($_POST['bme_statuses']) 
            ? array_map('sanitize_text_field', $_POST['bme_statuses']) 
            : [];
        update_post_meta($post_id, '_bme_statuses', $statuses);
        // error_log('BME Debug - Saved statuses: ' . print_r($statuses, true)); // Commented out for production
        
        $states = isset($_POST['bme_states']) && is_array($_POST['bme_states']) 
            ? array_map('sanitize_text_field', $_POST['bme_states']) 
            : [];
        update_post_meta($post_id, '_bme_states', $states);
        // error_log('BME Debug - Saved states: ' . print_r($states, true)); // Commented out for production
        
        // Clear cached stats after update
        $this->cache_manager->delete('extraction_stats_' . $post_id);
    }
    
    /**
     * Set custom columns for extraction list
     */
    public function set_extraction_columns($columns) {
        $new_columns = [
            'cb' => $columns['cb'],
            'title' => $columns['title'],
            'schedule' => __('Schedule', 'bridge-mls-extractor-pro'),
            'listings_count' => __('Listings', 'bridge-mls-extractor-pro'),
            'last_run' => __('Last Run', 'bridge-mls-extractor-pro'),
            'performance' => __('Performance', 'bridge-mls-extractor-pro'),
            'actions' => __('Actions', 'bridge-mls-extractor-pro'),
            'date' => $columns['date']
        ];
        
        return $new_columns;
    }
    
    /**
     * Display custom column content
     */
    public function display_extraction_column($column, $post_id) {
        switch ($column) {
            case 'schedule':
                $schedule = get_post_meta($post_id, '_bme_schedule', true) ?: 'none';
                if ($schedule === 'none') {
                    echo '<span class="bme-schedule-disabled">' . esc_html__('Manual', 'bridge-mls-extractor-pro') . '</span>';
                } else {
                    $schedules = wp_get_schedules();
                    echo '<span class="bme-schedule-active">' . 
                         esc_html($schedules[$schedule]['display'] ?? $schedule) . 
                         '</span>';
                }
                break;
                
            case 'listings_count':
                $stats = $this->cache_manager->get_extraction_stats($post_id);
                if (!$stats) {
                    echo '<span class="bme-loading" data-extraction-id="' . esc_attr($post_id) . '">—</span>';
                } else {
                    echo '<strong>' . esc_html(number_format($stats['total_listings'] ?? 0)) . '</strong>';
                }
                break;
                
            case 'last_run':
                $status = get_post_meta($post_id, '_bme_last_run_status', true);
                $time = get_post_meta($post_id, '_bme_last_run_time', true);
                
                if ($status && $time) {
                    printf(
                        '<div class="bme-last-run %s"><strong>%s</strong><br><small>%s</small></div>',
                        esc_attr($this->get_status_class($status)),
                        esc_html($status),
                        esc_html(human_time_diff(strtotime($time) ?? 0) . ' ago') // Added null coalesce for strtotime
                    );
                } else {
                    echo '<span class="bme-never">' . esc_html__('Never', 'bridge-mls-extractor-pro') . '</span>';
                }
                break;
                
            case 'performance':
                $duration = get_post_meta($post_id, '_bme_last_run_duration', true);
                $count = get_post_meta($post_id, '_bme_last_run_count', true);
                
                if ($duration && $count) {
                    $rate = round($count / $duration, 1);
                    printf(
                        '<div class="bme-performance"><strong>%s</strong><br><small>%s listings/sec</small></div>',
                        esc_html(sprintf('%.1fs', $duration)),
                        esc_html($rate)
                    );
                } else {
                    echo '—';
                }
                break;
                
            case 'actions':
                $this->render_quick_actions($post_id);
                break;
        }
    }
    
    /**
     * Render quick action buttons
     */
    private function render_quick_actions($post_id) {
        $run_url = wp_nonce_url(
            admin_url('admin-post.php?action=bme_run_extraction&post_id=' . $post_id), 
            'bme_run_extraction_' . $post_id
        );
        
        printf(
            '<a href="%s" class="button button-small button-primary" title="%s">%s</a>',
            esc_url($run_url),
            esc_attr__('Run extraction now', 'bridge-mls-extractor-pro'),
            esc_html__('Run', 'bridge-mls-extractor-pro')
        );
    }
    
    /**
     * Get CSS class for status
     */
    private function get_status_class($status) {
        switch ($status) {
            case 'Success':
                return 'bme-status-success';
            case 'Failure':
                return 'bme-status-failure';
            case 'Completed with errors':
                return 'bme-status-warning';
            default:
                return 'bme-status-unknown';
        }
    }
    
    /**
     * Render database browser page
     */
    public function render_database_browser() {
        require_once BME_PLUGIN_DIR . 'includes/class-bme-listings-list-table.php';
        
        $list_table = new BME_Advanced_Listings_List_Table($this->plugin);
        $list_table->prepare_items();
        
        // Get all possible columns for export selection
        $all_columns = $this->plugin->get('data_processor')->get_all_listing_columns();
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Database Browser', 'bridge-mls-extractor-pro'); ?></h1>
            <hr class="wp-header-end">
            
            <form method="get" id="bme-listings-filter">
                <input type="hidden" name="page" value="bme-database-browser">
                <?php wp_nonce_field('bme_database_browser_filter', 'bme_filter_nonce'); // Nonce for filtering ?>
                
                <div class="bme-filters-panel">
                    <div class="bme-filters-row">
                        <div class="bme-filter-group">
                            <label for="filter_standard_status"><?php esc_html_e('Status', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_standard_status" id="filter_standard_status" class="bme-filter-select">
                                <option value=""><?php esc_html_e('All Statuses', 'bridge-mls-extractor-pro'); ?></option>
                                <?php echo $this->render_filter_options('standard_status'); ?>
                            </select>
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="filter_property_type"><?php esc_html_e('Property Type', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_property_type" id="filter_property_type" class="bme-filter-select">
                                <option value=""><?php esc_html_e('All Types', 'bridge-mls-extractor-pro'); ?></option>
                                <?php echo $this->render_filter_options('property_type'); ?>
                            </select>
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="filter_city"><?php esc_html_e('City', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_city" id="filter_city" class="bme-filter-select">
                                <option value=""><?php esc_html_e('All Cities', 'bridge-mls-extractor-pro'); ?></option>
                                <?php echo $this->render_filter_options('city'); ?>
                            </select>
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="filter_listing_id"><?php esc_html_e('MLS Number', 'bridge-mls-extractor-pro'); ?></label>
                            <input type="text" name="filter_listing_id" id="filter_listing_id" 
                                   value="<?php echo esc_attr($_GET['filter_listing_id'] ?? ''); ?>" 
                                   class="regular-text">
                        </div>
                    </div>
                    
                    <div class="bme-filters-actions">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'bridge-mls-extractor-pro'); ?></button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=bme-database-browser')); ?>" class="button button-secondary">
                            <?php esc_html_e('Clear', 'bridge-mls-extractor-pro'); ?>
                        </a>
                        <button type="button" id="bme-advanced-filters" class="button button-secondary">
                            <?php esc_html_e('Advanced Filters', 'bridge-mls-extractor-pro'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Advanced Filters Panel (hidden by default) -->
                <div id="bme-advanced-panel" class="bme-advanced-filters" style="display: none;">
                    <div class="bme-filters-row">
                        <div class="bme-filter-group">
                            <label for="filter_price_min"><?php esc_html_e('Min Price', 'bridge-mls-extractor-pro'); ?></label>
                            <input type="number" name="filter_price_min" id="filter_price_min" 
                                   value="<?php echo esc_attr($_GET['filter_price_min'] ?? ''); ?>" 
                                   class="regular-text" min="0">
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="filter_price_max"><?php esc_html_e('Max Price', 'bridge-mls-extractor-pro'); ?></label>
                            <input type="number" name="filter_price_max" id="filter_price_max" 
                                   value="<?php echo esc_attr($_GET['filter_price_max'] ?? ''); ?>" 
                                   class="regular-text" min="0">
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="filter_bedrooms_min"><?php esc_html_e('Min Bedrooms', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_bedrooms_min" id="filter_bedrooms_min">
                                <option value=""><?php esc_html_e('Any', 'bridge-mls-extractor-pro'); ?></option>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?php echo esc_attr($i); ?>" <?php selected($_GET['filter_bedrooms_min'] ?? '', $i); ?>>
                                        <?php echo esc_html($i); ?>+
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="filter_bathrooms_min"><?php esc_html_e('Min Bathrooms', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_bathrooms_min" id="filter_bathrooms_min">
                                <option value=""><?php esc_html_e('Any', 'bridge-mls-extractor-pro'); ?></option>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?php echo esc_attr($i); ?>" <?php selected($_GET['filter_bathrooms_min'] ?? '', $i); ?>>
                                        <?php echo esc_html($i); ?>+
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="bme-export-form">
                <input type="hidden" name="action" value="bme_export_listings_csv">
                <?php wp_nonce_field('bme_export_listings_csv_nonce', 'bme_export_nonce'); ?>
                
                <?php
                // Pass current filters to the export form
                foreach ($_GET as $key => $value) {
                    if (strpos($key, 'filter_') === 0 || $key === 's') {
                        if (is_array($value)) {
                            foreach ($value as $val) {
                                printf('<input type="hidden" name="%s[]" value="%s">', esc_attr($key), esc_attr($val));
                            }
                        } else {
                            printf('<input type="hidden" name="%s" value="%s">', esc_attr($key), esc_attr($value));
                        }
                    }
                }
                ?>

                <div class="bme-export-panel card">
                    <h3><?php esc_html_e('Export Options', 'bridge-mls-extractor-pro'); ?></h3>
                    <p class="description"><?php esc_html_e('Select the fields you want to include in the CSV export. Only listings matching your current filters will be exported.', 'bridge-mls-extractor-pro'); ?></p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="bme_export_columns"><?php esc_html_e('Select Columns', 'bridge-mls-extractor-pro'); ?></label></th>
                            <td>
                                <select name="bme_export_columns[]" id="bme_export_columns" class="bme-select2-export-columns" multiple="multiple" style="width: 100%;">
                                    <?php foreach ($all_columns as $col_key => $col_label) : ?>
                                        <option value="<?php echo esc_attr($col_key); ?>" selected><?php echo esc_html($col_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Choose which columns to include in your CSV file. All columns are selected by default.', 'bridge-mls-extractor-pro'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Export Filtered Listings to CSV', 'bridge-mls-extractor-pro'), 'primary', 'bme_export_submit', true); ?>
                </div>
            </form>
            
            <?php $list_table->display(); ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Initialize Select2 for filter dropdowns
            $('.bme-filter-select').select2({
                width: '100%',
                allowClear: true
            });
            
            // Initialize Select2 for export columns
            $('.bme-select2-export-columns').select2({
                width: '100%',
                placeholder: '<?php esc_html_e('Select columns to export', 'bridge-mls-extractor-pro'); ?>',
                closeOnSelect: false // Keep dropdown open after selection
            });

            // Toggle advanced filters
            $('#bme-advanced-filters').on('click', function() {
                $('#bme-advanced-panel').slideToggle();
                $(this).text($(this).text() === '<?php esc_html_e('Advanced Filters', 'bridge-mls-extractor-pro'); ?>' ? 
                            '<?php esc_html_e('Hide Advanced', 'bridge-mls-extractor-pro'); ?>' : 
                            '<?php esc_html_e('Advanced Filters', 'bridge-mls-extractor-pro'); ?>');
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render filter options with caching
     */
    private function render_filter_options($field) {
        $values = $this->cache_manager->get_filter_values($field);
        
        if (!$values) {
            global $wpdb;
            $db_manager = $this->plugin->get('db');
            
            $table_map = [
                'standard_status' => 'listings',
                'property_type' => 'listings',
                'city' => 'listing_location',
                'state_or_province' => 'listing_location',
                'postal_code' => 'listing_location'
            ];
            
            $table = $db_manager->get_table($table_map[$field] ?? 'listings');
            $values = $wpdb->get_col(
                "SELECT DISTINCT {$field} FROM {$table} WHERE {$field} IS NOT NULL AND {$field} != '' ORDER BY {$field} ASC"
            );
            
            $this->cache_manager->cache_filter_values($field, $values);
        }
        
        $current_value = $_GET['filter_' . $field] ?? '';
        $options = '';
        
        foreach ($values as $value) {
            $options .= sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($current_value, $value, false),
                esc_html($value)
            );
        }
        
        return $options;
    }
    
    /**
     * Handle admin action: run extraction
     */
    public function handle_run_extraction() {
        $post_id = absint($_GET['post_id'] ?? 0);
        
        if (!$post_id || !current_user_can('edit_post', $post_id) || 
            !check_admin_referer('bme_run_extraction_' . $post_id)) {
            wp_die(esc_html__('Invalid request.', 'bridge-mls-extractor-pro'));
        }
        
        $extractor = $this->plugin->get('extractor');
        $success = $extractor->run_extraction($post_id, false);
        
        $redirect_url = add_query_arg([
            'message' => $success ? 'extraction_success' : 'extraction_failed'
        ], admin_url('edit.php?post_type=bme_extraction'));
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Handle admin action: run resync
     */
    public function handle_run_resync() {
        $post_id = absint($_GET['post_id'] ?? 0);
        
        if (!$post_id || !current_user_can('edit_post', $post_id) || 
            !check_admin_referer('bme_run_resync_' . $post_id)) {
            wp_die(esc_html__('Invalid request.', 'bridge-mls-extractor-pro'));
        }
        
        $extractor = $this->plugin->get('extractor');
        $success = $extractor->run_extraction($post_id, true);
        
        $redirect_url = add_query_arg([
            'message' => $success ? 'resync_success' : 'resync_failed'
        ], admin_url('edit.php?post_type=bme_extraction'));
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Handle admin action: clear data
     */
    public function handle_clear_data() {
        $post_id = absint($_GET['post_id'] ?? 0);
        
        if (!$post_id || !current_user_can('edit_post', $post_id) || 
            !check_admin_referer('bme_clear_data_' . $post_id)) {
            wp_die(esc_html__('Invalid request.', 'bridge-mls-extractor-pro'));
        }
        
        $data_processor = $this->plugin->get('processor');
        $cleared = $data_processor->clear_extraction_data($post_id);
        
        // Reset last modified timestamp
        update_post_meta($post_id, '_bme_last_modified', '1970-01-01T00:00:00Z');
        
        // Clear cached stats
        $this->cache_manager->delete('extraction_stats_' . $post_id);
        
        $redirect_url = add_query_arg([
            'message' => 'data_cleared',
            'cleared_count' => $cleared
        ], admin_url('edit.php?post_type=bme_extraction'));
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Handle admin action: test configuration
     */
    public function handle_test_config() {
        $post_id = absint($_GET['post_id'] ?? 0);
        
        if (!$post_id || !current_user_can('edit_post', $post_id) || 
            !check_admin_referer('bme_test_config_' . $post_id)) {
            wp_die(esc_html__('Invalid request.', 'bridge-mls-extractor-pro'));
        }
        
        $extractor = $this->plugin->get('extractor');
        $result = $extractor->test_extraction_config($post_id);
        
        $message = $result['success'] ? 'config_valid' : 'config_invalid';
        
        $redirect_url = add_query_arg([
            'message' => $message,
            'test_result' => base64_encode(json_encode($result))
        ], admin_url('post.php?post=' . $post_id . '&action=edit'));
        
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Handle export listings to CSV.
     */
    public function handle_export_listings_csv() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to export listings.', 'bridge-mls-extractor-pro'));
        }

        if (!isset($_POST['bme_export_nonce']) || !wp_verify_nonce($_POST['bme_export_nonce'], 'bme_export_listings_csv_nonce')) {
            wp_die(esc_html__('Security check failed. Please refresh the page and try again.', 'bridge-mls-extractor-pro'));
        }

        // Set higher limits for potentially large exports
        set_time_limit(0);
        ini_set('memory_limit', '512M'); // Adjust as needed

        $data_processor = $this->plugin->get('processor');
        
        // Get filters from the POST request (these were hidden fields from the filter form)
        $filters = [];
        $filter_keys = [
            'filter_standard_status', 'filter_property_type', 'filter_city',
            'filter_listing_id', 'filter_price_min', 'filter_price_max',
            'filter_bedrooms_min', 'filter_bathrooms_min', 'filter_year_built_min',
            'filter_year_built_max', 's' // Main search query
        ];

        foreach ($filter_keys as $key) {
            if (isset($_POST[$key]) && !empty($_POST[$key])) {
                // Remove 'filter_' prefix for data processor's filter keys
                $processed_key = str_replace('filter_', '', $key); 
                $filters[$processed_key] = is_array($_POST[$key]) ? array_map('sanitize_text_field', $_POST[$key]) : sanitize_text_field($_POST[$key]);
            }
        }

        // Get selected columns for export
        $selected_columns = [];
        if (isset($_POST['bme_export_columns']) && is_array($_POST['bme_export_columns'])) {
            $selected_columns = array_map('sanitize_text_field', $_POST['bme_export_columns']);
        }

        // If no columns are selected, default to all available columns
        if (empty($selected_columns)) {
            $all_columns_map = $data_processor->get_all_listing_columns();
            $selected_columns = array_keys($all_columns_map); // Use the keys (database column names)
        }

        // Fetch all listings matching the filters (no limit/offset for export)
        $listings = $data_processor->search_listings($filters, -1, 0, $selected_columns); // Pass selected columns

        // Set CSV headers
        header('Content-Type: text/csv; charset=' . get_option('blog_charset'));
        header('Content-Disposition: attachment; filename="mls-listings-export-' . date('Ymd-His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Write CSV headers (human-readable labels)
        $header_row = [];
        $all_columns_map = $data_processor->get_all_listing_columns(); // Get the full map for labels
        foreach ($selected_columns as $col_key) {
            $header_row[] = $all_columns_map[$col_key] ?? ucfirst(str_replace('_', ' ', $col_key));
        }
        fputcsv($output, $header_row);

        // Write CSV data
        foreach ($listings as $listing) {
            $row = [];
            foreach ($selected_columns as $col_key) {
                $value = $listing[$col_key] ?? '';
                // Handle array/object values by converting to JSON string
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                }
                $row[] = $value;
            }
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }
    
    /**
     * AJAX: Get filter values
     */
    public function ajax_get_filter_values() {
        check_ajax_referer('bme_admin_nonce', 'nonce');
        
        $field = sanitize_key($_POST['field'] ?? '');
        if (empty($field)) {
            wp_send_json_error(['message' => esc_html__('Invalid field.', 'bridge-mls-extractor-pro')]);
        }
        
        $values = $this->cache_manager->get_filter_values($field);
        
        wp_send_json_success($values);
    }
    
    /**
     * AJAX: Search listings
     */
    public function ajax_search_listings() {
        check_ajax_referer('bme_admin_nonce', 'nonce');
        
        $filters = $_POST['filters'] ?? [];
        $page = absint($_POST['page'] ?? 1);
        $per_page = 30;
        $offset = ($page - 1) * $per_page;
        
        // Check cache first
        $cache_key = 'search_' . md5(serialize($filters) . $page);
        $cached_result = $this->cache_manager->get($cache_key);
        
        if ($cached_result) {
            wp_send_json_success($cached_result);
            return;
        }
        
        $data_processor = $this->plugin->get('processor');
        $results = $data_processor->search_listings($filters, $per_page, $offset);
        $total = $data_processor->get_search_count($filters);
        
        $response = [
            'listings' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page)
        ];
        
        // Cache for 5 minutes
        $this->cache_manager->set($cache_key, $response, 300);
        
        wp_send_json_success($response);
    }
    
    /**
     * AJAX: Get extraction statistics
     */
    public function ajax_get_extraction_stats() {
        check_ajax_referer('bme_admin_nonce', 'nonce');
        
        $extraction_id = absint($_POST['extraction_id'] ?? 0);
        if (!$extraction_id) {
            wp_send_json_error(['message' => esc_html__('Invalid extraction ID.', 'bridge-mls-extractor-pro')]);
        }
        
        $stats = $this->cache_manager->get_extraction_stats($extraction_id);
        
        if (!$stats) {
            $data_processor = $this->plugin->get('processor');
            $stats = $data_processor->get_extraction_stats($extraction_id);
            $this->cache_manager->cache_extraction_stats($extraction_id, $stats);
        }
        
        wp_send_json_success($stats);
    }
    
    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'bme') === false) {
            return;
        }
        
        $message = $_GET['message'] ?? '';
        
        $messages = [
            'extraction_success' => ['success', __('Extraction completed successfully.', 'bridge-mls-extractor-pro')],
            'extraction_failed' => ['error', __('Extraction failed. Please check the Activity Logs for details.', 'bridge-mls-extractor-pro')],
            'resync_success' => ['success', __('Full resync completed successfully.', 'bridge-mls-extractor-pro')],
            'resync_failed' => ['error', __('Resync failed. Please check the Activity Logs for details.', 'bridge-mls-extractor-pro')],
            'data_cleared' => ['success', sprintf(__('Data cleared. %d listings removed.', 'bridge-mls-extractor-pro'), absint($_GET['cleared_count'] ?? 0))],
            'config_valid' => ['success', __('Configuration is valid and ready for extraction.', 'bridge-mls-extractor-pro')],
            'config_invalid' => ['error', __('Configuration has errors. Please check your settings and the Activity Logs.', 'bridge-mls-extractor-pro')]
        ];
        
        if (isset($messages[$message])) {
            [$type, $text] = $messages[$message];
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($type),
                esc_html($text)
            );
        }
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'bme_pro_settings',
            'bme_pro_api_credentials',
            [$this, 'sanitize_api_credentials']
        );
        
        register_setting(
            'bme_pro_settings',
            'bme_pro_performance_settings',
            [$this, 'sanitize_performance_settings']
        );
    }
    
    /**
     * Sanitize API credentials
     */
    public function sanitize_api_credentials($input) {
        return [
            'server_token' => sanitize_text_field($input['server_token'] ?? ''),
            'endpoint_url' => esc_url_raw($input['endpoint_url'] ?? '')
        ];
    }
    
    /**
     * Sanitize performance settings
     */
    public function sanitize_performance_settings($input) {
        return [
            'api_timeout' => max(30, absint($input['api_timeout'] ?? 60)),
            'batch_size' => max(10, min(500, absint($input['batch_size'] ?? 100))),
            'max_concurrent' => max(1, min(10, absint($input['max_concurrent'] ?? 5))),
            'cache_duration' => max(300, absint($input['cache_duration'] ?? 3600))
        ];
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bridge MLS Extractor Pro Settings', 'bridge-mls-extractor-pro'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('bme_pro_settings');
                
                $api_credentials = get_option('bme_pro_api_credentials', []);
                $performance_settings = get_option('bme_pro_performance_settings', []);
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('API Server Token', 'bridge-mls-extractor-pro'); ?></th>
                        <td>
                            <input type="password" name="bme_pro_api_credentials[server_token]" 
                                   value="<?php echo esc_attr($api_credentials['server_token'] ?? ''); ?>" 
                                   class="regular-text">
                            <p class="description"><?php esc_html_e('Your Bridge API server token', 'bridge-mls-extractor-pro'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('API Endpoint URL', 'bridge-mls-extractor-pro'); ?></th>
                        <td>
                            <input type="url" name="bme_pro_api_credentials[endpoint_url]" 
                                   value="<?php echo esc_attr($api_credentials['endpoint_url'] ?? ''); ?>" 
                                   class="large-text">
                            <p class="description"><?php esc_html_e('Complete OData endpoint URL (e.g., https://api.bridgedataoutput.com/api/v2/OData/mlspin/Property)', 'bridge-mls-extractor-pro'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2><?php esc_html_e('Performance Settings', 'bridge-mls-extractor-pro'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('API Timeout', 'bridge-mls-extractor-pro'); ?></th>
                        <td>
                            <input type="number" name="bme_pro_performance_settings[api_timeout]" 
                                   value="<?php echo esc_attr($performance_settings['api_timeout'] ?? 60); ?>" 
                                   class="small-text" min="30" max="300">
                            <span><?php esc_html_e('seconds', 'bridge-mls-extractor-pro'); ?></span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Batch Size', 'bridge-mls-extractor-pro'); ?></th>
                        <td>
                            <input type="number" name="bme_pro_performance_settings[batch_size]" 
                                   value="<?php echo esc_attr($performance_settings['batch_size'] ?? 100); ?>" 
                                   class="small-text" min="10" max="500">
                            <span><?php esc_html_e('listings per request', 'bridge-mls-extractor-pro'); ?></span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Max Concurrent Requests', 'bridge-mls-extractor-pro'); ?></th>
                        <td>
                            <input type="number" name="bme_pro_performance_settings[max_concurrent]" 
                                   value="<?php echo esc_attr($performance_settings['max_concurrent'] ?? 5); ?>" 
                                   class="small-text" min="1" max="10">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Cache Duration', 'bridge-mls-extractor-pro'); ?></th>
                        <td>
                            <input type="number" name="bme_pro_performance_settings[cache_duration]" 
                                   value="<?php echo esc_attr($performance_settings['cache_duration'] ?? 3600); ?>" 
                                   class="small-text" min="300">
                            <span><?php esc_html_e('seconds', 'bridge-mls-extractor-pro'); ?></span>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render the activity log page.
     */
    public function render_activity_logs() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.'));
        }
        
        global $wpdb;
        
        // Try to get logs from the new table structure first
        $logs_table = $wpdb->prefix . 'bme_extraction_logs';
        $logs_exist = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $logs_table)) === $logs_table;
        
        $logs = [];
        $total_logs = 0;
        
        if ($logs_exist) {
            // Get logs from extraction_logs table
            $logs = $wpdb->get_results("
                SELECT el.*, 
                       CASE 
                           WHEN p.post_title IS NOT NULL THEN p.post_title 
                           ELSE CONCAT('Extraction ID: ', el.extraction_id)
                       END as extraction_title
                FROM `{$logs_table}` el
                LEFT JOIN {$wpdb->posts} p ON el.extraction_id = p.ID
                ORDER BY el.created_at DESC 
                LIMIT 100
            ");
            
            $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM `{$logs_table}`");
        }
        
        // If no logs in new table, check for old log posts
        if (empty($logs)) {
            $old_logs = get_posts([
                'post_type' => 'bme_log',
                'posts_per_page' => 50,
                'post_status' => 'publish',
                'orderby' => 'date',
                'order' => 'DESC'
            ]);
            
            foreach ($old_logs as $log_post) {
                $extraction_id = get_post_meta($log_post->ID, '_bme_log_extraction_id', true);
                $status = get_post_meta($log_post->ID, '_bme_log_status', true);
                $count = get_post_meta($log_post->ID, '_bme_log_listings_count', true);
                
                $logs[] = (object)[
                    'id' => $log_post->ID,
                    'extraction_id' => $extraction_id,
                    'extraction_title' => get_the_title($extraction_id) ?: 'Unknown',
                    'status' => $status ?: 'Unknown',
                    'message' => $log_post->post_content,
                    'listings_processed' => $count ?: 0,
                    'duration_seconds' => null,
                    'created_at' => $log_post->post_date,
                    'api_requests_count' => null,
                    'memory_peak_mb' => null
                ];
            }
            
            $total_logs = count($logs);
        }
        
        // Get summary statistics
        $stats = [];
        if ($logs_exist) {
            $stats = $wpdb->get_row("
                SELECT 
                    COUNT(*) as total_runs,
                    SUM(listings_processed) as total_listings,
                    AVG(duration_seconds) as avg_duration,
                    SUM(CASE WHEN status = 'Success' THEN 1 END) as successful_runs
                FROM `{$logs_table}`
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
        }
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Activity Logs', 'bridge-mls-extractor-pro'); ?></h1>
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=bme_extraction')); ?>" class="page-title-action">← <?php esc_html_e('Back to Extractions', 'bridge-mls-extractor-pro'); ?></a>
            <hr class="wp-header-end">
            
            <?php if ($stats): ?>
            <div class="notice notice-info">
                <p><strong>📊 <?php esc_html_e('Last 30 Days Summary:', 'bridge-mls-extractor-pro'); ?></strong> 
                <?php echo esc_html(number_format($stats->total_runs)); ?> <?php esc_html_e('runs', 'bridge-mls-extractor-pro'); ?>, 
                <?php echo esc_html(number_format($stats->total_listings)); ?> <?php esc_html_e('listings processed', 'bridge-mls-extractor-pro'); ?>, 
                <?php echo esc_html(number_format($stats->successful_runs)); ?> <?php esc_html_e('successful', 'bridge-mls-extractor-pro'); ?>
                <?php if ($stats->avg_duration): ?>
                    (<?php esc_html_e('avg:', 'bridge-mls-extractor-pro'); ?> <?php echo esc_html(round($stats->avg_duration, 1)); ?>s <?php esc_html_e('per run', 'bridge-mls-extractor-pro'); ?>)
                <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($logs)): ?>
                <p><strong><?php echo esc_html(number_format($total_logs)); ?> <?php esc_html_e('total log entries', 'bridge-mls-extractor-pro'); ?></strong> (<?php esc_html_e('showing most recent', 'bridge-mls-extractor-pro'); ?> <?php echo esc_html(count($logs)); ?>)</p>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 140px;"><?php esc_html_e('Date/Time', 'bridge-mls-extractor-pro'); ?></th>
                            <th style="width: 200px;"><?php esc_html_e('Extraction', 'bridge-mls-extractor-pro'); ?></th>
                            <th style="width: 100px;"><?php esc_html_e('Status', 'bridge-mls-extractor-pro'); ?></th>
                            <th style="width: 80px;"><?php esc_html_e('Listings', 'bridge-mls-extractor-pro'); ?></th>
                            <th style="width: 80px;"><?php esc_html_e('Duration', 'bridge-mls-extractor-pro'); ?></th>
                            <th><?php esc_html_e('Message', 'bridge-mls-extractor-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td>
                                <?php 
                                $date = new DateTime($log->created_at);
                                echo esc_html($date->format('M j, Y')) . '<br><small>' . esc_html($date->format('g:i A')) . '</small>';
                                ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html($log->extraction_title); ?></strong>
                                <?php if ($log->extraction_id): ?>
                                    <br><small>ID: <?php echo esc_html($log->extraction_id); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $status_class = 'bme-status-' . strtolower(str_replace(' ', '-', $log->status));
                                ?>
                                <span class="bme-status-badge <?php echo esc_attr($status_class); ?>">
                                    <?php echo esc_html($log->status); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(number_format($log->listings_processed ?: 0)); ?></td>
                            <td>
                                <?php 
                                if ($log->duration_seconds) {
                                    echo esc_html(round($log->duration_seconds, 1)) . 's';
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td>
                                <div style="max-width: 400px; word-wrap: break-word;">
                                    <?php echo esc_html($log->message); ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
            <?php else: ?>
                <div class="notice notice-info">
                    <p><strong><?php esc_html_e('No activity logs found.', 'bridge-mls-extractor-pro'); ?></strong></p>
                    <p><?php esc_html_e('Logs will appear here after running extractions.', 'bridge-mls-extractor-pro'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render performance dashboard page
     */
    public function render_performance_dashboard() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.'));
        }
        
        global $wpdb;
        
        // Database table references
        $listings_table = $wpdb->prefix . 'bme_listings';
        $location_table = $wpdb->prefix . 'bme_listing_location';
        $logs_table = $wpdb->prefix . 'bme_extraction_logs';
        
        // Check which tables exist
        $tables_exist = [];
        $table_list = ['listings' => $listings_table, 'location' => $location_table, 'logs' => $logs_table];
        foreach ($table_list as $name => $table) {
            $tables_exist[$name] = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        }
        
        // Get database statistics
        $db_stats = [];
        foreach ($tables_exist as $name => $exists) {
            if ($exists) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM " . $table_list[$name]);
                $db_stats[$name] = intval($count);
            } else {
                $db_stats[$name] = 0;
            }
        }
        
        // Get performance metrics (last 24 hours)
        $performance_metrics = null;
        if ($tables_exist['logs']) {
            $performance_metrics = $wpdb->get_row("
                SELECT 
                    COUNT(*) as total_runs,
                    SUM(listings_processed) as total_listings,
                    AVG(duration_seconds) as avg_duration,
                    SUM(CASE WHEN status = 'Success' THEN 1 END) as successful_runs
                FROM `{$logs_table}` 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            
            if ($performance_metrics && $performance_metrics->total_runs > 0) {
                $performance_metrics->success_rate = round(($performance_metrics->successful_runs / $performance_metrics->total_runs) * 100, 1);
            }
        }
        
        // Get listing statistics
        $listing_stats = null;
        if ($tables_exist['listings']) {
            $listing_stats = $wpdb->get_row("
                SELECT 
                    COUNT(*) as total_listings,
                    AVG(list_price) as avg_price,
                    COUNT(CASE WHEN standard_status = 'Active' THEN 1 END) as active_listings
                FROM `{$listings_table}`
                WHERE list_price > 0
            ");
        }
        
        // Get extraction profiles summary
        $extractions = get_posts([
            'post_type' => 'bme_extraction',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Performance Dashboard', 'bridge-mls-extractor-pro'); ?></h1>
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=bme_extraction')); ?>" class="page-title-action">← <?php esc_html_e('Back to Extractions', 'bridge-mls-extractor-pro'); ?></a>
            <hr class="wp-header-end">
            
            <!-- Quick Stats -->
            <div class="bme-stats-grid">
                <div class="bme-stat-item">
                    <div class="bme-stat-value"><?php echo esc_html(number_format($db_stats['listings'])); ?></div>
                    <div class="bme-stat-label"><?php esc_html_e('Total Listings', 'bridge-mls-extractor-pro'); ?></div>
                </div>
                
                <div class="bme-stat-item">
                    <div class="bme-stat-value"><?php echo esc_html($listing_stats ? number_format($listing_stats->active_listings) : '0'); ?></div>
                    <div class="bme-stat-label"><?php esc_html_e('Active Listings', 'bridge-mls-extractor-pro'); ?></div>
                </div>
                
                <div class="bme-stat-item">
                    <div class="bme-stat-value"><?php echo esc_html(count($extractions)); ?></div>
                    <div class="bme-stat-label"><?php esc_html_e('Extraction Profiles', 'bridge-mls-extractor-pro'); ?></div>
                </div>
                
                <div class="bme-stat-item">
                    <div class="bme-stat-value"><?php echo esc_html($performance_metrics ? $performance_metrics->success_rate . '%' : '—'); ?></div>
                    <div class="bme-stat-label"><?php esc_html_e('Success Rate (24h)', 'bridge-mls-extractor-pro'); ?></div>
                </div>
            </div>
            
            <!-- Database Overview -->
            <div class="card">
                <h3><?php esc_html_e('Database Overview', 'bridge-mls-extractor-pro'); ?></h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Table', 'bridge-mls-extractor-pro'); ?></th>
                            <th><?php esc_html_e('Records', 'bridge-mls-extractor-pro'); ?></th>
                            <th><?php esc_html_e('Status', 'bridge-mls-extractor-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('Listings', 'bridge-mls-extractor-pro'); ?></strong></td>
                            <td><?php echo esc_html(number_format($db_stats['listings'])); ?></td>
                            <td>
                                <?php if ($tables_exist['listings']): ?>
                                    <span style="color: green;">✅ <?php esc_html_e('Active', 'bridge-mls-extractor-pro'); ?></span>
                                <?php else: ?>
                                    <span style="color: red;">❌ <?php esc_html_e('Missing', 'bridge-mls-extractor-pro'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Locations', 'bridge-mls-extractor-pro'); ?></strong></td>
                            <td><?php echo esc_html(number_format($db_stats['location'])); ?></td>
                            <td>
                                <?php if ($tables_exist['location']): ?>
                                    <span style="color: green;">✅ <?php esc_html_e('Active', 'bridge-mls-extractor-pro'); ?></span>
                                <?php else: ?>
                                    <span style="color: red;">❌ <?php esc_html_e('Missing', 'bridge-mls-extractor-pro'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Activity Logs', 'bridge-mls-extractor-pro'); ?></strong></td>
                            <td><?php echo esc_html(number_format($db_stats['logs'])); ?></td>
                            <td>
                                <?php if ($tables_exist['logs']): ?>
                                    <span style="color: green;">✅ <?php esc_html_e('Active', 'bridge-mls-extractor-pro'); ?></span>
                                <?php else: ?>
                                    <span style="color: orange;">⚠️ <?php esc_html_e('Limited', 'bridge-mls-extractor-pro'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <?php if ($performance_metrics && $performance_metrics->total_runs > 0): ?>
            <div class="card">
                <h3><?php esc_html_e('Performance Metrics (Last 24 Hours)', 'bridge-mls-extractor-pro'); ?></h3>
                <div class="bme-stats-grid">
                    <div class="bme-stat-item">
                        <div class="bme-stat-value"><?php echo esc_html($performance_metrics->total_runs); ?></div>
                        <div class="bme-stat-label"><?php esc_html_e('Total Runs', 'bridge-mls-extractor-pro'); ?></div>
                    </div>
                    <div class="bme-stat-item">
                        <div class="bme-stat-value"><?php echo esc_html(number_format($performance_metrics->total_listings)); ?></div>
                        <div class="bme-stat-label"><?php esc_html_e('Listings Processed', 'bridge-mls-extractor-pro'); ?></div>
                    </div>
                    <div class="bme-stat-item">
                        <div class="bme-stat-value"><?php echo esc_html(round($performance_metrics->avg_duration, 1)); ?>s</div>
                        <div class="bme-stat-label"><?php esc_html_e('Avg Duration', 'bridge-mls-extractor-pro'); ?></div>
                    </div>
                    <div class="bme-stat-item">
                        <div class="bme-stat-value"><?php echo esc_html($performance_metrics->success_rate); ?>%</div>
                        <div class="bme-stat-label"><?php esc_html_e('Success Rate', 'bridge-mls-extractor-pro'); ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- System Information -->
            <div class="card">
                <h3><?php esc_html_e('System Information', 'bridge-mls-extractor-pro'); ?></h3>
                <p><strong><?php esc_html_e('WordPress Version:', 'bridge-mls-extractor-pro'); ?></strong> <?php echo esc_html(get_bloginfo('version')); ?></p>
                <p><strong><?php esc_html_e('PHP Version:', 'bridge-mls-extractor-pro'); ?></strong> <?php echo esc_html(PHP_VERSION); ?></p>
                <p><strong><?php esc_html_e('Plugin Version:', 'bridge-mls-extractor-pro'); ?></strong> <?php echo defined('BME_PRO_VERSION') ? esc_html(BME_PRO_VERSION) : esc_html__('Unknown', 'bridge-mls-extractor-pro'); ?></p>
                <p><strong><?php esc_html_e('Database Schema:', 'bridge-mls-extractor-pro'); ?></strong> 
                    <?php echo array_sum($tables_exist) === count($tables_exist) ? '✅ ' . esc_html__('Complete', 'bridge-mls-extractor-pro') : '⚠️ ' . esc_html__('Incomplete', 'bridge-mls-extractor-pro'); ?>
                </p>
            </div>
        </div>
        <?php
    }
}
