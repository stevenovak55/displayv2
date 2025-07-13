<?php
/**
 * Handles rewrite rules and template redirects.
 *
 * @package MLS_Listings_Display
 */
class MLD_Rewrites {

    public function __construct() {
        add_action( 'init', [ $this, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        // Switched from template_redirect to the more reliable template_include filter.
        add_filter( 'template_include', [ $this, 'template_include' ] );
    }

    /**
     * Add rewrite rules for the single property page.
     */
    public function add_rewrite_rules() {
        add_rewrite_rule(
            '^property/([^/]+)/?$',
            'index.php?mls_number=$matches[1]', // Simplified rule, no need for post_type=page
            'top'
        );
    }

    /**
     * Add custom query variables.
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'mls_number';
        return $vars;
    }

    /**
     * Load the single property template if the query var is set.
     */
    public function template_include( $template ) {
        // Check if our custom query variable is set.
        if ( get_query_var( 'mls_number' ) ) {
            // Enqueue the specific stylesheet for this template.
            wp_enqueue_style( 'mld-single-property-css', MLD_PLUGIN_URL . 'assets/css/single-property.css', [], MLD_VERSION );
            
            $new_template = MLD_PLUGIN_PATH . 'templates/single-property.php';
            if ( file_exists( $new_template ) ) {
                return $new_template;
            }
        }
        return $template;
    }

    /**
     * Flush rewrite rules on activation.
     */
    public static function activate() {
        $rewrites = new self();
        $rewrites->add_rewrite_rules();
        flush_rewrite_rules();
    }

    /**
     * Flush rewrite rules on deactivation.
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }
}
