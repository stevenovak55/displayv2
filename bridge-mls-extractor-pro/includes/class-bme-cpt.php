<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class BME_CPT
 *
 * Registers the Custom Post Types used by the plugin.
 */
class BME_CPT {

    /**
     * Register CPTs.
     */
    public function register() {
        $this->register_extraction_cpt();
        $this->register_log_cpt();
    }

    /**
     * Register the 'bme_extraction' Custom Post Type.
     */
    private function register_extraction_cpt() {
        $labels = [
            'name'                  => _x('Extractions', 'Post Type General Name', 'bridge-mls-extractor'),
            'singular_name'         => _x('Extraction', 'Post Type Singular Name', 'bridge-mls-extractor'),
            'menu_name'             => __('MLS Extractions', 'bridge-mls-extractor'),
            'name_admin_bar'        => __('Extraction', 'bridge-mls-extractor'),
            'archives'              => __('Extraction Archives', 'bridge-mls-extractor'),
            'attributes'            => __('Extraction Attributes', 'bridge-mls-extractor'),
            'parent_item_colon'     => __('Parent Extraction:', 'bridge-mls-extractor'),
            'all_items'             => __('All Extractions', 'bridge-mls-extractor'),
            'add_new_item'          => __('Add New Extraction', 'bridge-mls-extractor'),
            'add_new'               => __('Add New', 'bridge-mls-extractor'),
            'new_item'              => __('New Extraction', 'bridge-mls-extractor'),
            'edit_item'             => __('Edit Extraction', 'bridge-mls-extractor'),
            'update_item'           => __('Update Extraction', 'bridge-mls-extractor'),
            'view_item'             => __('View Extraction', 'bridge-mls-extractor'),
            'view_items'            => __('View Extractions', 'bridge-mls-extractor'),
            'search_items'          => __('Search Extraction', 'bridge-mls-extractor'),
            'not_found'             => __('Not found', 'bridge-mls-extractor'),
            'not_found_in_trash'    => __('Not found in Trash', 'bridge-mls-extractor'),
            'featured_image'        => __('Featured Image', 'bridge-mls-extractor'),
            'set_featured_image'    => __('Set featured image', 'bridge-mls-extractor'),
            'remove_featured_image' => __('Remove featured image', 'bridge-mls-extractor'),
            'use_featured_image'    => __('Use as featured image', 'bridge-mls-extractor'),
            'insert_into_item'      => __('Insert into extraction', 'bridge-mls-extractor'),
            'uploaded_to_this_item' => __('Uploaded to this extraction', 'bridge-mls-extractor'),
            'items_list'            => __('Extractions list', 'bridge-mls-extractor'),
            'items_list_navigation' => __('Extractions list navigation', 'bridge-mls-extractor'),
            'filter_items_list'     => __('Filter extractions list', 'bridge-mls-extractor'),
        ];
        $args = [
            'label'                 => __('Extraction', 'bridge-mls-extractor'),
            'description'           => __('Extraction Profiles for MLS Data', 'bridge-mls-extractor'),
            'labels'                => $labels,
            'supports'              => ['title'],
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            'show_in_menu'          => false, // We will create a custom menu
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'post',
            'rewrite'               => false,
        ];
        register_post_type('bme_extraction', $args);
    }

    /**
     * Register the 'bme_log' Custom Post Type.
     */
    private function register_log_cpt() {
        $labels = [
            'name'          => _x('Extraction Logs', 'Post Type General Name', 'bridge-mls-extractor'),
            'singular_name' => _x('Log', 'Post Type Singular Name', 'bridge-mls-extractor'),
        ];
        $args = [
            'label'               => __('Log', 'bridge-mls-extractor'),
            'description'         => __('Logs for MLS Data Extractions', 'bridge-mls-extractor'),
            'labels'              => $labels,
            'supports'            => ['title', 'editor'],
            'hierarchical'        => false,
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'can_export'          => false,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'capability_type'     => 'post',
            'rewrite'             => false,
        ];
        register_post_type('bme_log', $args);
    }
}
