<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class BME_Cron
 *
 * Handles the scheduling and execution of cron jobs for automatic extractions.
 */
class BME_Cron {

    const MASTER_CRON_HOOK = 'bme_master_cron_hook';

    public function __construct() {
        // No-op
    }

    /**
     * Register cron-related hooks.
     */
    public function init_hooks() {
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        add_action(self::MASTER_CRON_HOOK, [$this, 'run_scheduled_extractions']);

        // Schedule the master cron if it's not already scheduled
        if (!wp_next_scheduled(self::MASTER_CRON_HOOK)) {
            wp_schedule_event(time(), 'every_15_minutes', self::MASTER_CRON_HOOK);
        }
    }

    /**
     * Add custom cron schedules.
     *
     * @param array $schedules
     * @return array
     */
    public function add_cron_schedules($schedules) {
        $schedules['every_15_minutes'] = [
            'interval' => 900,
            'display'  => __('Every 15 Minutes', 'bridge-mls-extractor')
        ];
        $schedules['every_30_minutes'] = [
            'interval' => 1800,
            'display'  => __('Every 30 Minutes', 'bridge-mls-extractor')
        ];
        return $schedules;
    }

    /**
     * The main cron job function that iterates through all extraction profiles
     * and runs them if they are due.
     */
    public function run_scheduled_extractions() {
        $extractions = get_posts([
            'post_type'      => 'bme_extraction',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => '_bme_schedule',
                    'value'   => 'none',
                    'compare' => '!='
                ]
            ]
        ]);

        if (empty($extractions)) {
            return;
        }
        
        $extraction_handler = new BME_Extraction();

        foreach ($extractions as $extraction) {
            $schedule = get_post_meta($extraction->ID, '_bme_schedule', true);
            $last_run = get_post_meta($extraction->ID, '_bme_last_run_time', true) ?: 0;
            $schedules = wp_get_schedules();

            if (isset($schedules[$schedule])) {
                $interval = $schedules[$schedule]['interval'];
                if (time() > ($last_run + $interval)) {
                    // It's time to run this extraction
                    $extraction_handler->run_single_extraction($extraction->ID, false);
                }
            }
        }
    }
}
