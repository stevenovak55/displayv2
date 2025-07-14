<?php
/**
 * Main plugin class.
 *
 * @package MLS_Listings_Display
 */
class MLD_Main {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->load_dependencies();
        $this->init_classes();
    }

    /**
     * Load the required dependencies for this plugin.
     * Note: class-mld-rewrites.php is loaded in the main plugin file.
     */
    private function load_dependencies() {
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-utils.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-query.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-ajax.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-shortcodes.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-admin.php';
    }

    /**
     * Initialize the classes.
     */
    private function init_classes() {
        new MLD_Shortcodes();
        new MLD_Ajax();
        new MLD_Rewrites();
        new MLD_Admin();
    }
}
