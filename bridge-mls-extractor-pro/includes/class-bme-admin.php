<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class BME_Admin
 *
 * Handles all admin-facing functionality, including menus, settings pages,
 * meta boxes, and custom columns.
 */
class BME_Admin {

    public function __construct() {
        // No-op
    }

    /**
     * Register admin-related hooks.
     */
    public function init_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menus']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_lookup_scripts']);
        add_action('add_meta_boxes', [$this, 'add_settings_meta_box']);
        add_action('save_post_bme_extraction', [$this, 'save_settings_meta_box']);
        add_filter('manage_bme_extraction_posts_columns', [$this, 'set_custom_edit_columns']);
        add_action('manage_bme_extraction_posts_custom_column', [$this, 'custom_column_content'], 10, 2);
        add_action('admin_notices', [$this, 'display_admin_notices']);
    }

    /**
     * Enqueue scripts and styles for the lookup page (Select2).
     */
    public function enqueue_lookup_scripts($hook) {
        if ($hook !== 'mls-extractions_page_bme-database-lookup') {
            return;
        }
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0-rc.0');
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0-rc.0', true);
    }

    /**
     * Add admin menus and submenus.
     */
    public function add_admin_menus() {
        add_menu_page(
            __('MLS Extractions', 'bridge-mls-extractor'),
            __('MLS Extractions', 'bridge-mls-extractor'),
            'manage_options',
            'edit.php?post_type=bme_extraction',
            '',
            'dashicons-admin-home',
            20
        );
        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Database Lookup', 'bridge-mls-extractor'),
            __('Database Lookup', 'bridge-mls-extractor'),
            'manage_options',
            'bme-database-lookup',
            [$this, 'render_lookup_page']
        );
        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Activity Log', 'bridge-mls-extractor'),
            __('Activity Log', 'bridge-mls-extractor'),
            'manage_options',
            'bme-activity-log',
            [$this, 'render_log_page']
        );
        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Settings', 'bridge-mls-extractor'),
            __('Settings', 'bridge-mls-extractor'),
            'manage_options',
            'bme-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Helper function to get distinct values for a filter dropdown.
     */
    private function get_distinct_values($field) {
        global $wpdb;
        $allowed_fields = [
            'City', 'PropertyType', 'StandardStatus', 'PostalCode', 'StreetName', 
            'PropertySubType', 'BuildingName', 'MLSAreaMajor', 'MLSAreaMinor', 'StructureType'
        ];
        if (!in_array($field, $allowed_fields)) {
            return [];
        }
        $table_name = $wpdb->prefix . 'bme_listings';
        static $cache = [];
        if (isset($cache[$field])) {
            return $cache[$field];
        }
        $results = $wpdb->get_col("SELECT DISTINCT `$field` FROM `$table_name` WHERE `$field` IS NOT NULL AND `$field` != '' ORDER BY `$field` ASC");
        $cache[$field] = $results;
        return $results;
    }

    /**
     * Render the Database Lookup page with advanced filters.
     */
    public function render_lookup_page() {
        require_once BME_PLUGIN_DIR . 'includes/class-bme-listings-list-table.php';
        
        $list_table = new BME_Listings_List_Table();
        $list_table->prepare_items();

        $main_filters = [
            'City' => $this->get_distinct_values('City'),
            'PropertyType' => $this->get_distinct_values('PropertyType'),
            'StandardStatus' => $this->get_distinct_values('StandardStatus'),
        ];
        $advanced_filters = [
            'PostalCode' => ['label' => 'Postal Code', 'values' => $this->get_distinct_values('PostalCode')],
            'StreetName' => ['label' => 'Street Name', 'values' => $this->get_distinct_values('StreetName')],
            'PropertySubType' => ['label' => 'Property Sub Type', 'values' => $this->get_distinct_values('PropertySubType')],
            'BuildingName' => ['label' => 'Building Name', 'values' => $this->get_distinct_values('BuildingName')],
            'MLSAreaMajor' => ['label' => 'MLS Area Major', 'values' => $this->get_distinct_values('MLSAreaMajor')],
            'MLSAreaMinor' => ['label' => 'MLS Area Minor', 'values' => $this->get_distinct_values('MLSAreaMinor')],
            'StructureType' => ['label' => 'Structure Type', 'values' => $this->get_distinct_values('StructureType')],
        ];
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Database Lookup', 'bridge-mls-extractor'); ?></h1>
            <p><?php _e('Search and browse the listings stored in your local database. All filters are combined.', 'bridge-mls-extractor'); ?></p>
            
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="bme-database-lookup" />

                <div id="bme-filters" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-bottom: 15px;">
                    <div style="display: flex; gap: 15px; align-items: flex-end;">
                        <!-- Main Filters -->
                        <div style="flex: 1 1 20%;">
                            <label for="filter_City" style="font-weight: bold; display: block; margin-bottom: 5px;"><?php _e('City', 'bridge-mls-extractor'); ?></label>
                            <select name="filter_City" id="filter_City" class="bme-select2" style="width: 100%;">
                                <option value="">All Cities</option>
                                <?php
                                $current_city = isset($_REQUEST['filter_City']) ? $_REQUEST['filter_City'] : '';
                                foreach ($main_filters['City'] as $city) {
                                    echo '<option value="' . esc_attr($city) . '"' . selected($current_city, $city, false) . '>' . esc_html($city) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div style="flex: 1 1 20%;">
                            <label for="filter_PropertyType" style="font-weight: bold; display: block; margin-bottom: 5px;"><?php _e('Property Type', 'bridge-mls-extractor'); ?></label>
                            <select name="filter_PropertyType" id="filter_PropertyType" class="bme-select2" style="width: 100%;">
                                <option value="">All Types</option>
                                <?php
                                $current_ptype = isset($_REQUEST['filter_PropertyType']) ? $_REQUEST['filter_PropertyType'] : '';
                                foreach ($main_filters['PropertyType'] as $ptype) {
                                    echo '<option value="' . esc_attr($ptype) . '"' . selected($current_ptype, $ptype, false) . '>' . esc_html($ptype) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div style="flex: 1 1 20%;">
                            <label for="filter_StandardStatus" style="font-weight: bold; display: block; margin-bottom: 5px;"><?php _e('Status', 'bridge-mls-extractor'); ?></label>
                            <select name="filter_StandardStatus" id="filter_StandardStatus" class="bme-select2" style="width: 100%;">
                                <option value="">All Statuses</option>
                                <?php
                                $current_status = isset($_REQUEST['filter_StandardStatus']) ? $_REQUEST['filter_StandardStatus'] : '';
                                foreach ($main_filters['StandardStatus'] as $status) {
                                    echo '<option value="' . esc_attr($status) . '"' . selected($current_status, $status, false) . '>' . esc_html($status) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                         <div style="flex: 1 1 20%;">
                             <label for="filter_ListingId" style="font-weight: bold; display: block; margin-bottom: 5px;"><?php _e('MLS Number', 'bridge-mls-extractor'); ?></label>
                             <input type="text" class="regular-text" name="filter_ListingId" id="filter_ListingId" value="<?php echo isset($_REQUEST['filter_ListingId']) ? esc_attr($_REQUEST['filter_ListingId']) : ''; ?>">
                        </div>
                        <div style="flex: 1 1 20%;">
                            <button type="button" id="bme-advanced-btn" class="button"><?php _e('Advanced', 'bridge-mls-extractor'); ?></button>
                        </div>
                    </div>
                    <div style="margin-top: 15px;">
                        <input type="submit" class="button button-primary" value="<?php _e('Filter Listings', 'bridge-mls-extractor'); ?>">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=bme-database-lookup')); ?>" class="button-secondary" style="margin-left: 5px;"><?php _e('Clear Filters', 'bridge-mls-extractor'); ?></a>
                    </div>
                </div>

                <!-- Advanced Filters Modal -->
                <div id="bme-advanced-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
                    <div style="background: #f1f1f1; width: 600px; max-width: 90%; padding: 20px; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); border-radius: 5px;">
                        <h2 style="margin-top: 0;"><?php _e('Advanced Filters', 'bridge-mls-extractor'); ?></h2>
                        <button type="button" class="notice-dismiss bme-modal-close" style="position: absolute; top: 10px; right: 10px;"><span class="screen-reader-text">Dismiss this notice.</span></button>
                        <table class="form-table">
                            <?php foreach ($advanced_filters as $key => $data) : 
                                $param_name = 'filter_' . $key;
                                $current_value = isset($_REQUEST[$param_name]) ? sanitize_text_field($_REQUEST[$param_name]) : '';
                            ?>
                            <tr>
                                <th scope="row"><label for="<?php echo esc_attr($param_name); ?>"><?php echo esc_html($data['label']); ?></label></th>
                                <td>
                                    <select name="<?php echo esc_attr($param_name); ?>" id="<?php echo esc_attr($param_name); ?>" class="bme-select2" style="width: 100%;">
                                        <option value="">All</option>
                                        <?php
                                        foreach ($data['values'] as $value) {
                                            echo '<option value="' . esc_attr($value) . '"' . selected($current_value, $value, false) . '>' . esc_html($value) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <button type="button" class="button-primary bme-modal-close"><?php _e('Done', 'bridge-mls-extractor'); ?></button>
                    </div>
                </div>
            </form>
            
            <?php $list_table->display(); ?>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.bme-select2').select2();
                $('#bme-advanced-btn').on('click', function() {
                    $('#bme-advanced-modal').show();
                });
                $('.bme-modal-close').on('click', function() {
                    $('#bme-advanced-modal').hide();
                });
            });
        </script>
        <?php
    }

    /**
     * Render the main settings page.
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Bridge MLS Extractor Settings', 'bridge-mls-extractor'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('bme_settings_group');
                do_settings_sections('bme-settings');
                submit_button();
                ?>
            </form>

            <div class="card" style="margin-top: 20px;">
                <h2 class="title"><?php _e('Clear All Listing Data', 'bridge-mls-extractor'); ?></h2>
                <p><strong><?php _e('Warning:', 'bridge-mls-extractor'); ?></strong> <?php _e('This will permanently delete all listings from your database. This action cannot be undone. You will need to run a "Full Re-sync" on your extractions to get the data back.', 'bridge-mls-extractor'); ?></p>
                <form method="post" action="admin-post.php">
                    <input type="hidden" name="action" value="bme_clear_all_data">
                    <?php wp_nonce_field('bme_clear_all_data_nonce'); ?>
                    <?php submit_button(__('Clear All Data', 'bridge-mls-extractor'), 'delete', 'submit', true, ['onclick' => "return confirm('" . __('Are you absolutely sure you want to delete ALL listing data? This cannot be undone.', 'bridge-mls-extractor') . "');"]); ?>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render the activity log page.
     */
    public function render_log_page() {
        $log_table = new BME_Logs_List_Table();
        $log_table->prepare_items();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Extraction Activity Log', 'bridge-mls-extractor'); ?></h1>
            <p><?php _e('This log shows the history of all extraction runs.', 'bridge-mls-extractor'); ?></p>
            <?php $log_table->display(); ?>
        </div>
        <?php
    }

    /**
     * Register settings, sections, and fields for the settings page.
     */
    public function register_settings() {
        register_setting(
            'bme_settings_group', 
            'bme_api_credentials',
            [$this, 'sanitize_api_credentials']
        );

        add_settings_section(
            'bme_api_section',
            __('API Credentials', 'bridge-mls-extractor'),
            null,
            'bme-settings'
        );

        add_settings_field(
            'bme_api_server_token',
            __('API Server Token', 'bridge-mls-extractor'),
            [$this, 'render_api_token_field'],
            'bme-settings',
            'bme_api_section'
        );

        add_settings_field(
            'bme_api_endpoint_url',
            __('API Endpoint URL', 'bridge-mls-extractor'),
            [$this, 'render_api_url_field'],
            'bme-settings',
            'bme_api_section'
        );
    }

    /**
     * Sanitize the API credentials before saving.
     */
    public function sanitize_api_credentials($input) {
        $new_input = [];
        if (isset($input['server_token'])) {
            $new_input['server_token'] = sanitize_text_field($input['server_token']);
        }
        if (isset($input['endpoint_url'])) {
            $new_input['endpoint_url'] = esc_url_raw($input['endpoint_url']);
        }
        return $new_input;
    }

    public function render_api_token_field() {
        $options = get_option('bme_api_credentials');
        $token = isset($options['server_token']) ? $options['server_token'] : '';
        echo '<input type="text" name="bme_api_credentials[server_token]" value="' . esc_attr($token) . '" class="regular-text">';
        echo '<p class="description">' . __('Enter your Bridge API Server Token.', 'bridge-mls-extractor') . '</p>';
    }

    public function render_api_url_field() {
        $options = get_option('bme_api_credentials');
        $url = isset($options['endpoint_url']) ? $options['endpoint_url'] : 'https://api.bridgedataoutput.com/api/v2/OData/mlspin/Property';
        echo '<input type="url" name="bme_api_credentials[endpoint_url]" value="' . esc_attr($url) . '" class="large-text">';
        echo '<p class="description">' . __('The full OData endpoint URL for the Property resource (e.g., https://api.bridgedataoutput.com/api/v2/OData/YOUR_DATASET/Property).', 'bridge-mls-extractor') . '</p>';
    }

    /**
     * Add the meta box to the 'bme_extraction' CPT edit screen.
     */
    public function add_settings_meta_box() {
        add_meta_box(
            'bme_settings_metabox',
            __('Extraction Settings', 'bridge-mls-extractor'),
            [$this, 'render_meta_box_content'],
            'bme_extraction',
            'normal',
            'high'
        );
    }

    /**
     * Render the content of the settings meta box.
     */
    public function render_meta_box_content($post) {
        wp_nonce_field('bme_save_meta_box_data', 'bme_meta_box_nonce');

        $correct_fields = [
            'ListingKey', 'ListingId', 'ModificationTimestamp', 'StandardStatus', 'PropertyType', 'PropertySubType',
            'ListPrice', 'ClosePrice', 'StreetNumber', 'StreetName', 'StreetNumberNumeric', 'UnitNumber', 'City',
            'StateOrProvince', 'PostalCode', 'CountyOrParish', 'Country', 'BedroomsTotal', 'BathroomsTotalInteger',
            'BathroomsFull', 'BathroomsHalf', 'LivingArea', 'LotSizeAcres', 'LotSizeSquareFeet', 'YearBuilt',
            'Latitude', 'Longitude', 'PublicRemarks', 'ListAgentMlsId', 'BuyerAgentMlsId', 'ListOfficeMlsId',
            'BuyerOfficeMlsId', 'CloseDate', 'PurchaseContractDate', 'StatusChangeTimestamp', 'Media', 'PhotosCount',
            'AssociationFee', 'AssociationFeeFrequency', 'BuildingName', 'StructureType', 'ArchitecturalStyle',
            'ConstructionMaterials', 'GarageSpaces', 'ParkingTotal', 'ParkingFeatures', 'MLSAreaMajor', 'MLSAreaMinor',
            'TaxAnnualAmount', 'TaxYear', 'WaterfrontYN', 'PoolFeatures', 'Heating', 'Cooling', 'ElementarySchool',
            'MiddleOrJuniorSchool', 'HighSchool'
        ];
        $default_fields = implode(',', $correct_fields);

        // Get saved values for the form fields
        $saved_statuses = get_post_meta($post->ID, '_bme_statuses', true) ?: [];
        $saved_property_types = get_post_meta($post->ID, '_bme_property_types', true) ?: [];
        $saved_states = get_post_meta($post->ID, '_bme_states', true) ?: [];
        $list_agent_id = get_post_meta($post->ID, '_bme_list_agent_id', true);
        $buyer_agent_id = get_post_meta($post->ID, '_bme_buyer_agent_id', true);
        $closed_lookback = get_post_meta($post->ID, '_bme_closed_lookback_months', true) ?: 12;
        $schedule = get_post_meta($post->ID, '_bme_schedule', true) ?: 'none';
        $select_fields = get_post_meta($post->ID, '_bme_select_fields', true) ?: $default_fields;
        $cities = get_post_meta($post->ID, '_bme_cities', true);

        ?>
        <style>.bme-form-table td{padding:5px 10px 15px 0;}.bme-form-table .description{margin-top:4px;}.bme-form-table .checkbox-group label{display:block;margin-bottom:5px;}</style>
        <table class="form-table bme-form-table">
            <tr>
                <th><label for="bme_select_fields"><?php _e('Fields to Extract', 'bridge-mls-extractor'); ?></label></th>
                <td>
                    <textarea name="bme_select_fields" id="bme_select_fields" rows="8" class="large-text"><?php echo esc_textarea($select_fields); ?></textarea>
                    <p class="description">
                        <?php _e('<strong>Important:</strong> Comma-separated list of fields. Reduces API response size and processing time.', 'bridge-mls-extractor'); ?>
                        <a href="#" id="bme-restore-defaults" style="margin-left:10px;"><?php _e('Restore Defaults', 'bridge-mls-extractor'); ?></a>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="bme_schedule"><?php _e('Schedule', 'bridge-mls-extractor'); ?></label></th>
                <td>
                    <select name="bme_schedule" id="bme_schedule">
                        <?php
                        $schedules = array_merge(['none' => ['display' => __('Disabled', 'bridge-mls-extractor')]], wp_get_schedules());
                        foreach ($schedules as $key => $details) {
                            if (in_array($key, ['none', 'every_15_minutes', 'every_30_minutes', 'hourly', 'twicedaily', 'daily'])) {
                                echo "<option value='{$key}' " . selected($schedule, $key, false) . ">{$details['display']}</option>";
                            }
                        }
                        ?>
                    </select>
                    <p class="description"><?php _e('How often this extraction should run automatically.', 'bridge-mls-extractor'); ?></p>
                </td>
            </tr>
             <tr>
                <th><label><?php _e('Listing Statuses', 'bridge-mls-extractor'); ?></label></th>
                <td class="checkbox-group" id="bme-status-checkboxes">
                    <?php
                    $status_options = ['Active', 'Active Under Contract', 'Pending', 'Closed', 'Expired', 'Withdrawn', 'Canceled'];
                    foreach ($status_options as $option) {
                        echo "<label><input type='checkbox' name='bme_statuses[]' value='" . esc_attr($option) . "' " . checked(in_array($option, $saved_statuses), true, false) . "> " . esc_html($option) . "</label>";
                    }
                    ?>
                </td>
            </tr>
            <tr id="bme-closed-lookback-row" style="display: none;">
                <th><label for="bme_closed_lookback_months"><?php _e('Closed Listings Lookback', 'bridge-mls-extractor'); ?></label></th>
                <td>
                    <input type="number" name="bme_closed_lookback_months" id="bme_closed_lookback_months" value="<?php echo esc_attr($closed_lookback); ?>" class="small-text" min="1" step="1"> months
                    <p class="description"><?php _e('Required when "Closed" status is selected. How many months back to search for closed listings.', 'bridge-mls-extractor'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label><?php _e('Property Types', 'bridge-mls-extractor'); ?></label></th>
                <td class="checkbox-group">
                    <?php
                    $property_type_options = ['Residential', 'Residential Lease', 'Residential Income', 'Land', 'Commercial Sale', 'Commercial Lease', 'Business Opportunity', 'Farm'];
                    foreach ($property_type_options as $option) {
                         echo "<label><input type='checkbox' name='bme_property_types[]' value='" . esc_attr($option) . "' " . checked(in_array($option, $saved_property_types), true, false) . "> " . esc_html($option) . "</label>";
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th><label for="bme_cities"><?php _e('City/Cities', 'bridge-mls-extractor'); ?></label></th>
                <td>
                    <textarea name="bme_cities" rows="3" class="large-text" placeholder="Boston, Cambridge, Somerville"><?php echo esc_textarea($cities); ?></textarea>
                    <p class="description"><?php _e('Comma-separated list. Leave blank for all cities.', 'bridge-mls-extractor'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label><?php _e('State/Province', 'bridge-mls-extractor'); ?></label></th>
                <td class="checkbox-group">
                    <?php
                    $state_options = ['MA', 'NH', 'RI', 'VT', 'CT', 'ME'];
                    foreach ($state_options as $option) {
                        echo "<label><input type='checkbox' name='bme_states[]' value='{$option}' " . checked(in_array($option, $saved_states), true, false) . "> {$option}</label>";
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th><label for="bme_list_agent_id"><?php _e('List Agent MlsId', 'bridge-mls-extractor'); ?></label></th>
                <td>
                    <input type="text" name="bme_list_agent_id" id="bme_list_agent_id" value="<?php echo esc_attr($list_agent_id); ?>" class="regular-text">
                </td>
            </tr>
            <tr id="bme-buyer-agent-row" style="display: none;">
                <th><label for="bme_buyer_agent_id"><?php _e('Buyer Agent MlsId', 'bridge-mls-extractor'); ?></label></th>
                <td>
                    <input type="text" name="bme_buyer_agent_id" id="bme_buyer_agent_id" value="<?php echo esc_attr($buyer_agent_id); ?>" class="regular-text">
                    <p class="description"><?php _e('Optional. Applies to Active Under Contract, Pending, or Closed statuses.', 'bridge-mls-extractor'); ?></p>
                </td>
            </tr>
        </table>
        <script>
            jQuery(document).ready(function($) {
                $('#bme-restore-defaults').on('click', function(e) {
                    e.preventDefault();
                    if (confirm('<?php _e('Are you sure you want to restore the default field list?', 'bridge-mls-extractor'); ?>')) {
                        $('textarea[name="bme_select_fields"]').val('<?php echo esc_js($default_fields); ?>');
                    }
                });

                function toggleConditionalFields() {
                    var statuses = [];
                    $('#bme-status-checkboxes input:checked').each(function() {
                        statuses.push($(this).val());
                    });

                    // Buyer Agent Field visibility
                    if (statuses.includes('Active Under Contract') || statuses.includes('Pending') || statuses.includes('Closed')) {
                        $('#bme-buyer-agent-row').show();
                    } else {
                        $('#bme-buyer-agent-row').hide();
                    }

                    // Closed Lookback Field visibility
                    if (statuses.includes('Closed')) {
                        $('#bme-closed-lookback-row').show();
                    } else {
                        $('#bme-closed-lookback-row').hide();
                    }
                }

                // Run on page load
                toggleConditionalFields();

                // Run when any status checkbox is changed
                $('#bme-status-checkboxes input').on('change', function() {
                    toggleConditionalFields();
                });
            });
        </script>
        <?php
    }

    /**
     * Save the meta box data when the post is saved.
     */
    public function save_settings_meta_box($post_id) {
        if (!isset($_POST['bme_meta_box_nonce']) || !wp_verify_nonce($_POST['bme_meta_box_nonce'], 'bme_save_meta_box_data')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save new fields
        $statuses = isset($_POST['bme_statuses']) && is_array($_POST['bme_statuses']) ? array_map('sanitize_text_field', $_POST['bme_statuses']) : [];
        update_post_meta($post_id, '_bme_statuses', $statuses);

        update_post_meta($post_id, '_bme_buyer_agent_id', sanitize_text_field($_POST['bme_buyer_agent_id']));
        update_post_meta($post_id, '_bme_closed_lookback_months', absint($_POST['bme_closed_lookback_months']));
        
        // Save existing fields
        update_post_meta($post_id, '_bme_cities', sanitize_textarea_field($_POST['bme_cities']));
        update_post_meta($post_id, '_bme_list_agent_id', sanitize_text_field($_POST['bme_list_agent_id']));
        update_post_meta($post_id, '_bme_schedule', sanitize_text_field($_POST['bme_schedule']));
        update_post_meta($post_id, '_bme_select_fields', sanitize_textarea_field($_POST['bme_select_fields']));

        $states = isset($_POST['bme_states']) && is_array($_POST['bme_states']) ? array_map('sanitize_text_field', $_POST['bme_states']) : [];
        update_post_meta($post_id, '_bme_states', $states);

        $types = isset($_POST['bme_property_types']) && is_array($_POST['bme_property_types']) ? array_map('sanitize_text_field', $_POST['bme_property_types']) : [];
        update_post_meta($post_id, '_bme_property_types', $types);
    }

    /**
     * Define custom columns for the 'bme_extraction' CPT list table.
     */
    public function set_custom_edit_columns($columns) {
        $new_columns = [
            'cb' => $columns['cb'],
            'title' => $columns['title'],
            'schedule' => __('Schedule', 'bridge-mls-extractor'),
            'last_run' => __('Last Run Status', 'bridge-mls-extractor'),
            'actions' => __('Actions', 'bridge-mls-extractor'),
            'date' => $columns['date'],
        ];
        return $new_columns;
    }

    /**
     * Render content for the custom columns.
     */
    public function custom_column_content($column, $post_id) {
        switch ($column) {
            case 'schedule':
                $schedule = get_post_meta($post_id, '_bme_schedule', true) ?: 'none';
                $schedules = wp_get_schedules();
                echo $schedule !== 'none' && isset($schedules[$schedule]) ? $schedules[$schedule]['display'] : __('Disabled', 'bridge-mls-extractor');
                break;

            case 'last_run':
                $status = get_post_meta($post_id, '_bme_last_run_status', true);
                $time = get_post_meta($post_id, '_bme_last_run_time', true);
                if ($status) {
                    $color = $status === 'Success' ? 'green' : 'red';
                    echo "<strong><span style='color:{$color};'>{$status}</span></strong><br><small>" . ($time ? date('Y-m-d H:i:s', $time) . ' (UTC)' : 'Never') . '</small>';
                } else {
                    echo __('Never', 'bridge-mls-extractor');
                }
                break;

            case 'actions':
                $run_url = wp_nonce_url(admin_url('admin-post.php?action=bme_run_now&post_id=' . $post_id), 'bme_run_now_' . $post_id);
                $resync_url = wp_nonce_url(admin_url('admin-post.php?action=bme_run_resync&post_id=' . $post_id), 'bme_run_resync_' . $post_id);
                $clear_url = wp_nonce_url(admin_url('admin-post.php?action=bme_clear_data&post_id=' . $post_id), 'bme_clear_data_' . $post_id);

                echo '<a href="' . esc_url($run_url) . '" class="button">' . __('Run Now', 'bridge-mls-extractor') . '</a>';
                echo '<a href="' . esc_url($resync_url) . '" class="button" style="margin-left: 5px;" onclick="return confirm(\'' . __('This will delete data for this extraction and re-download all matching listings. Are you sure?', 'bridge-mls-extractor') . '\');">' . __('Full Re-sync', 'bridge-mls-extractor') . '</a>';
                echo '<a href="' . esc_url($clear_url) . '" class="button button-link-delete" style="margin-left: 5px;" onclick="return confirm(\'' . __('Are you sure you want to delete all data from this extraction?', 'bridge-mls-extractor') . '\');">' . __('Clear Data', 'bridge-mls-extractor') . '</a>';
                break;
        }
    }

    /**
     * Display admin notices for feedback on actions.
     */
    public function display_admin_notices() {
        if (!isset($_GET['post_type']) || $_GET['post_type'] !== 'bme_extraction' || !isset($_GET['message'])) {
             if (isset($_GET['page']) && $_GET['page'] == 'bme-settings' && isset($_GET['message']) && $_GET['message'] == 'cleared') {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('All listing data has been cleared.', 'bridge-mls-extractor') . '</p></div>';
            }
            return;
        }

        $messages = [
            '100' => __('Extraction completed successfully.', 'bridge-mls-extractor'),
            '101' => __('Full Re-sync completed successfully.', 'bridge-mls-extractor'),
            '102' => __('Data for the specified extraction has been cleared.', 'bridge-mls-extractor'),
            '200' => __('API credentials seem to be missing or invalid. Please check the settings page.', 'bridge-mls-extractor'),
        ];

        $message_code = absint($_GET['message']);
        if (isset($messages[$message_code])) {
            $class = $message_code >= 200 ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . $class . ' is-dismissible"><p>' . $messages[$message_code] . '</p></div>';
        }
    }
}
