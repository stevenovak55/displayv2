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
                <div class="bme-stat-value">$<?php echo number_format($stats['avg_price'] ?? 0); ?></div>
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
                <div class="bme-stat-value"><?php echo date('M j, Y g:i A', $last_run_time); ?></div>
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
        error_log('BME Debug - POST data: ' . print_r($_POST, true));
        
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
            error_log("BME Debug - Saved {$meta_key}: " . $value);
        }
        
        // Save array fields
        $statuses = isset($_POST['bme_statuses']) && is_array($_POST['bme_statuses']) 
            ? array_map('sanitize_text_field', $_POST['bme_statuses']) 
            : [];
        update_post_meta($post_id, '_bme_statuses', $statuses);
        error_log('BME Debug - Saved statuses: ' . print_r($statuses, true));
        
        $states = isset($_POST['bme_states']) && is_array($_POST['bme_states']) 
            ? array_map('sanitize_text_field', $_POST['bme_states']) 
            : [];
        update_post_meta($post_id, '_bme_states', $states);
        error_log('BME Debug - Saved states: ' . print_r($states, true));
        
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
                    echo '<span class="bme-schedule-disabled">' . __('Manual', 'bridge-mls-extractor-pro') . '</span>';
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
                    echo '<span class="bme-loading" data-extraction-id="' . $post_id . '">—</span>';
                } else {
                    echo '<strong>' . number_format($stats['total_listings'] ?? 0) . '</strong>';
                }
                break;
                
            case 'last_run':
                $status = get_post_meta($post_id, '_bme_last_run_status', true);
                $time = get_post_meta($post_id, '_bme_last_run_time', true);
                
                if ($status && $time) {
                    printf(
                        '<div class="bme-last-run %s"><strong>%s</strong><br><small>%s</small></div>',
                        $this->get_status_class($status),
                        esc_html($status),
                        esc_html(human_time_diff($time) . ' ago')
                    );
                } else {
                    echo '<span class="bme-never">' . __('Never', 'bridge-mls-extractor-pro') . '</span>';
                }
                break;
                
            case 'performance':
                $duration = get_post_meta($post_id, '_bme_last_run_duration', true);
                $count = get_post_meta($post_id, '_bme_last_run_count', true);
                
                if ($duration && $count) {
                    $rate = round($count / $duration, 1);
                    printf(
                        '<div class="bme-performance"><strong>%.1fs</strong><br><small>%s listings/sec</small></div>',
                        $duration,
                        $rate
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
            __('Run', 'bridge-mls-extractor-pro')
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
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Database Browser', 'bridge-mls-extractor-pro'); ?></h1>
            <hr class="wp-header-end">
            
            <form method="get" id="bme-listings-filter">
                <input type="hidden" name="page" value="bme-database-browser">
                
                <div class="bme-filters-panel">
                    <div class="bme-filters-row">
                        <div class="bme-filter-group">
                            <label for="filter_standard_status"><?php _e('Status', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_standard_status" id="filter_standard_status" class="bme-filter-select">
                                <option value=""><?php _e('All Statuses', 'bridge-mls-extractor-pro'); ?></option>
                                <?php echo $this->render_filter_options('standard_status'); ?>
                            </select>
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="filter_property_type"><?php _e('Property Type', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_property_type" id="filter_property_type" class="bme-filter-select">
                                <option value=""><?php _e('All Types', 'bridge-mls-extractor-pro'); ?></option>
                                <?php echo $this->render_filter_options('property_type'); ?>
                            </select>
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="filter_city"><?php _e('City', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_city" id="filter_city" class="bme-filter-select">
                                <option value=""><?php _e('All Cities', 'bridge-mls-extractor-pro'); ?></option>
                                <?php echo $this->render_filter_options('city'); ?>
                            </select>
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="filter_listing_id"><?php _e('MLS Number', 'bridge-mls-extractor-pro'); ?></label>
                            <input type="text" name="filter_listing_id" id="filter_listing_id" 
                                   value="<?php echo esc_attr($_GET['filter_listing_id'] ?? ''); ?>" 
                                   class="regular-text">
                        </div>
                    </div>
                    
                    <div class="bme-filters-actions">
                        <button type="submit" class="button button-primary"><?php _e('Filter', 'bridge-mls-extractor-pro'); ?></button>
                        <a href="<?php echo admin_url('admin.php?page=bme-database-browser'); ?>" class="button button-secondary">
                            <?php _e('Clear', 'bridge-mls-extractor-pro'); ?>
                        </a>
                        <button type="button" id="bme-advanced-filters" class="button button-secondary">
                            <?php _e('Advanced Filters', 'bridge-mls-extractor-pro'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Advanced Filters Panel (hidden by default) -->
                <div id="bme-advanced-panel" class="bme-advanced-filters" style="display: none;">
                    <div class="bme-filters-row">
                        <div class="bme-filter-group">
                            <label for="filter_price_min"><?php _e('Min Price', 'bridge-mls-extractor-pro'); ?></label>
                            <input type="number" name="filter_price_min" id="filter_price_min" 
                                   value="<?php echo esc_attr($_GET['filter_price_min'] ?? ''); ?>" 
                                   class="regular-text" min="0">
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="filter_price_max"><?php _e('Max Price', 'bridge-mls-extractor-pro'); ?></label>
                            <input type="number" name="filter_price_max" id="filter_price_max" 
                                   value="<?php echo esc_attr($_GET['filter_price_max'] ?? ''); ?>" 
                                   class="regular-text" min="0">
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="filter_bedrooms_min"><?php _e('Min Bedrooms', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_bedrooms_min" id="filter_bedrooms_min">
                                <option value=""><?php _e('Any', 'bridge-mls-extractor-pro'); ?></option>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php selected($_GET['filter_bedrooms_min'] ?? '', $i); ?>>
                                        <?php echo $i; ?>+
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="filter_bathrooms_min"><?php _e('Min Bathrooms', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_bathrooms_min" id="filter_bathrooms_min">
                                <option value=""><?php _e('Any', 'bridge-mls-extractor-pro'); ?></option>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php selected($_GET['filter_bathrooms_min'] ?? '', $i); ?>>
                                        <?php echo $i; ?>+
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
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
            
            // Toggle advanced filters
            $('#bme-advanced-filters').on('click', function() {
                $('#bme-advanced-panel').slideToggle();
                $(this).text($(this).text() === '<?php _e('Advanced Filters', 'bridge-mls-extractor-pro'); ?>' ? 
                            '<?php _e('Hide Advanced', 'bridge-mls-extractor-pro'); ?>' : 
                            '<?php _e('Advanced Filters', 'bridge-mls-extractor-pro'); ?>');
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
            wp_die(__('Invalid request.', 'bridge-mls-extractor-pro'));
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
            wp_die(__('Invalid request.', 'bridge-mls-extractor-pro'));
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
            wp_die(__('Invalid request.', 'bridge-mls-extractor-pro'));
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
            wp_die(__('Invalid request.', 'bridge-mls-extractor-pro'));
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
     * AJAX: Get filter values
     */
    public function ajax_get_filter_values() {
        check_ajax_referer('bme_admin_nonce', 'nonce');
        
        $field = sanitize_key($_POST['field'] ?? '');
        if (empty($field)) {
            wp_die('Invalid field');
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
            wp_die('Invalid extraction ID');
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
            'extraction_failed' => ['error', __('Extraction failed. Please check the activity logs.', 'bridge-mls-extractor-pro')],
            'resync_success' => ['success', __('Full resync completed successfully.', 'bridge-mls-extractor-pro')],
            'resync_failed' => ['error', __('Resync failed. Please check the activity logs.', 'bridge-mls-extractor-pro')],
            'data_cleared' => ['success', sprintf(__('Data cleared. %d listings removed.', 'bridge-mls-extractor-pro'), absint($_GET['cleared_count'] ?? 0))],
            'config_valid' => ['success', __('Configuration is valid and ready for extraction.', 'bridge-mls-extractor-pro')],
            'config_invalid' => ['error', __('Configuration has errors. Please check your settings.', 'bridge-mls-extractor-pro')]
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
            <h1><?php _e('Bridge MLS Extractor Pro Settings', 'bridge-mls-extractor-pro'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('bme_pro_settings');
                
                $api_credentials = get_option('bme_pro_api_credentials', []);
                $performance_settings = get_option('bme_pro_performance_settings', []);
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('API Server Token', 'bridge-mls-extractor-pro'); ?></th>
                        <td>
                            <input type="password" name="bme_pro_api_credentials[server_token]" 
                                   value="<?php echo esc_attr($api_credentials['server_token'] ?? ''); ?>" 
                                   class="regular-text">
                            <p class="description"><?php _e('Your Bridge API server token', 'bridge-mls-extractor-pro'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('API Endpoint URL', 'bridge-mls-extractor-pro'); ?></th>
                        <td>
                            <input type="url" name="bme_pro_api_credentials[endpoint_url]" 
                                   value="<?php echo esc_attr($api_credentials['endpoint_url'] ?? ''); ?>" 
                                   class="large-text">
                            <p class="description"><?php _e('Complete OData endpoint URL (e.g., https://api.bridgedataoutput.com/api/v2/OData/mlspin/Property)', 'bridge-mls-extractor-pro'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2><?php _e('Performance Settings', 'bridge-mls-extractor-pro'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('API Timeout', 'bridge-mls-extractor-pro'); ?></th>
                        <td>
                            <input type="number" name="bme_pro_performance_settings[api_timeout]" 
                                   value="<?php echo esc_attr($performance_settings['api_timeout'] ?? 60); ?>" 
                                   class="small-text" min="30" max="300">
                            <span><?php _e('seconds', 'bridge-mls-extractor-pro'); ?></span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Batch Size', 'bridge-mls-extractor-pro'); ?></th>
                        <td>
                            <input type="number" name="bme_pro_performance_settings[batch_size]" 
                                   value="<?php echo esc_attr($performance_settings['batch_size'] ?? 100); ?>" 
                                   class="small-text" min="10" max="500">
                            <span><?php _e('listings per request', 'bridge-mls-extractor-pro'); ?></span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Max Concurrent Requests', 'bridge-mls-extractor-pro'); ?></th>
                        <td>
                            <input type="number" name="bme_pro_performance_settings[max_concurrent]" 
                                   value="<?php echo esc_attr($performance_settings['max_concurrent'] ?? 5); ?>" 
                                   class="small-text" min="1" max="10">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Cache Duration', 'bridge-mls-extractor-pro'); ?></th>
                        <td>
                            <input type="number" name="bme_pro_performance_settings[cache_duration]" 
                                   value="<?php echo esc_attr($performance_settings['cache_duration'] ?? 3600); ?>" 
                                   class="small-text" min="300">
                            <span><?php _e('seconds', 'bridge-mls-extractor-pro'); ?></span>
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
            wp_die(__('You do not have sufficient permissions to access this page.'));
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
                    SUM(CASE WHEN status = 'Success' THEN 1 ELSE 0 END) as successful_runs,
                    MAX(created_at) as last_run
                FROM `{$logs_table}`
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
        }
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Activity Logs', 'bridge-mls-extractor-pro'); ?></h1>
            <a href="<?php echo admin_url('edit.php?post_type=bme_extraction'); ?>" class="page-title-action">← Back to Extractions</a>
            <hr class="wp-header-end">
            
            <?php if ($stats): ?>
            <div class="notice notice-info">
                <p><strong>📊 Last 30 Days Summary:</strong> 
                <?php echo number_format($stats->total_runs); ?> runs, 
                <?php echo number_format($stats->total_listings); ?> listings processed, 
                <?php echo number_format($stats->successful_runs); ?> successful
                <?php if ($stats->avg_duration): ?>
                    (avg: <?php echo round($stats->avg_duration, 1); ?>s per run)
                <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($logs)): ?>
                <p><strong><?php echo number_format($total_logs); ?> total log entries</strong> (showing most recent <?php echo count($logs); ?>)</p>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 140px;"><?php _e('Date/Time', 'bridge-mls-extractor-pro'); ?></th>
                            <th style="width: 200px;"><?php _e('Extraction', 'bridge-mls-extractor-pro'); ?></th>
                            <th style="width: 100px;"><?php _e('Status', 'bridge-mls-extractor-pro'); ?></th>
                            <th style="width: 80px;"><?php _e('Listings', 'bridge-mls-extractor-pro'); ?></th>
                            <th style="width: 80px;"><?php _e('Duration', 'bridge-mls-extractor-pro'); ?></th>
                            <th><?php _e('Message', 'bridge-mls-extractor-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td>
                                <?php 
                                $date = new DateTime($log->created_at);
                                echo $date->format('M j, Y') . '<br><small>' . $date->format('g:i A') . '</small>';
                                ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html($log->extraction_title); ?></strong>
                                <?php if ($log->extraction_id): ?>
                                    <br><small>ID: <?php echo $log->extraction_id; ?></small>
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
                            <td><?php echo number_format($log->listings_processed ?: 0); ?></td>
                            <td>
                                <?php 
                                if ($log->duration_seconds) {
                                    echo round($log->duration_seconds, 1) . 's';
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
                    <p><strong><?php _e('No activity logs found.', 'bridge-mls-extractor-pro'); ?></strong></p>
                    <p><?php _e('Logs will appear here after running extractions.', 'bridge-mls-extractor-pro'); ?></p>
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
            wp_die(__('You do not have sufficient permissions to access this page.'));
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
                    SUM(CASE WHEN status = 'Success' THEN 1 ELSE 0 END) as successful_runs
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
            <h1 class="wp-heading-inline"><?php _e('Performance Dashboard', 'bridge-mls-extractor-pro'); ?></h1>
            <a href="<?php echo admin_url('edit.php?post_type=bme_extraction'); ?>" class="page-title-action">← Back to Extractions</a>
            <hr class="wp-header-end">
            
            <!-- Quick Stats -->
            <div class="bme-stats-grid">
                <div class="bme-stat-item">
                    <div class="bme-stat-value"><?php echo number_format($db_stats['listings']); ?></div>
                    <div class="bme-stat-label">Total Listings</div>
                </div>
                
                <div class="bme-stat-item">
                    <div class="bme-stat-value"><?php echo $listing_stats ? number_format($listing_stats->active_listings) : '0'; ?></div>
                    <div class="bme-stat-label">Active Listings</div>
                </div>
                
                <div class="bme-stat-item">
                    <div class="bme-stat-value"><?php echo count($extractions); ?></div>
                    <div class="bme-stat-label">Extraction Profiles</div>
                </div>
                
                <div class="bme-stat-item">
                    <div class="bme-stat-value"><?php echo $performance_metrics ? $performance_metrics->success_rate . '%' : '—'; ?></div>
                    <div class="bme-stat-label">Success Rate (24h)</div>
                </div>
            </div>
            
            <!-- Database Overview -->
            <div class="card">
                <h3><?php _e('Database Overview', 'bridge-mls-extractor-pro'); ?></h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Table', 'bridge-mls-extractor-pro'); ?></th>
                            <th><?php _e('Records', 'bridge-mls-extractor-pro'); ?></th>
                            <th><?php _e('Status', 'bridge-mls-extractor-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Listings</strong></td>
                            <td><?php echo number_format($db_stats['listings']); ?></td>
                            <td>
                                <?php if ($tables_exist['listings']): ?>
                                    <span style="color: green;">✅ Active</span>
                                <?php else: ?>
                                    <span style="color: red;">❌ Missing</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Locations</strong></td>
                            <td><?php echo number_format($db_stats['location']); ?></td>
                            <td>
                                <?php if ($tables_exist['location']): ?>
                                    <span style="color: green;">✅ Active</span>
                                <?php else: ?>
                                    <span style="color: red;">❌ Missing</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Activity Logs</strong></td>
                            <td><?php echo number_format($db_stats['logs']); ?></td>
                            <td>
                                <?php if ($tables_exist['logs']): ?>
                                    <span style="color: green;">✅ Active</span>
                                <?php else: ?>
                                    <span style="color: orange;">⚠️ Limited</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <?php if ($performance_metrics && $performance_metrics->total_runs > 0): ?>
            <div class="card">
                <h3><?php _e('Performance Metrics (Last 24 Hours)', 'bridge-mls-extractor-pro'); ?></h3>
                <div class="bme-stats-grid">
                    <div class="bme-stat-item">
                        <div class="bme-stat-value"><?php echo $performance_metrics->total_runs; ?></div>
                        <div class="bme-stat-label">Total Runs</div>
                    </div>
                    <div class="bme-stat-item">
                        <div class="bme-stat-value"><?php echo number_format($performance_metrics->total_listings); ?></div>
                        <div class="bme-stat-label">Listings Processed</div>
                    </div>
                    <div class="bme-stat-item">
                        <div class="bme-stat-value"><?php echo round($performance_metrics->avg_duration, 1); ?>s</div>
                        <div class="bme-stat-label">Avg Duration</div>
                    </div>
                    <div class="bme-stat-item">
                        <div class="bme-stat-value"><?php echo $performance_metrics->success_rate; ?>%</div>
                        <div class="bme-stat-label">Success Rate</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- System Information -->
            <div class="card">
                <h3><?php _e('System Information', 'bridge-mls-extractor-pro'); ?></h3>
                <p><strong>WordPress Version:</strong> <?php echo get_bloginfo('version'); ?></p>
                <p><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></p>
                <p><strong>Plugin Version:</strong> <?php echo defined('BME_PRO_VERSION') ? BME_PRO_VERSION : 'Unknown'; ?></p>
                <p><strong>Database Schema:</strong> 
                    <?php echo array_sum($tables_exist) === count($tables_exist) ? '✅ Complete' : '⚠️ Incomplete'; ?>
                </p>
            </div>
        </div>
        <?php
    }
}