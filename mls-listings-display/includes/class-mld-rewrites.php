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
        add_filter( 'template_include', [ $this, 'template_include' ] );
    }

    /**
     * Add rewrite rules for the single property page.
     */
    public function add_rewrite_rules() {
        add_rewrite_rule(
            '^property/([^/]+)/?$',
            'index.php?mls_number=$matches[1]',
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
        if ( get_query_var( 'mls_number' ) ) {
            // Enqueue assets specifically for this template
            wp_enqueue_style( 'mld-single-property-css', MLD_PLUGIN_URL . 'assets/css/single-property.css', [], MLD_VERSION );
            wp_enqueue_script( 'mld-single-property-js', MLD_PLUGIN_URL . 'assets/js/single-property.js', [], MLD_VERSION, true );
            
            // Pass data to the script
            $listing = MLD_Query::get_listing_details(get_query_var('mls_number'));
            $photos = MLD_Utils::decode_json($listing['Media'] ?? '[]') ?: [];
            $js_data = ['photos' => array_column($photos, 'MediaURL')];
            wp_localize_script('mld-single-property-js', 'mldSinglePropertyData', $js_data);

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
