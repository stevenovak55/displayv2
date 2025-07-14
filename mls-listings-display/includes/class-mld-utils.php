<?php
/**
 * Utility functions for the MLS Listings Display plugin.
 *
 * @package MLS_Listings_Display
 */
class MLD_Utils {

    /**
     * Safely decodes a JSON string from the database.
     * @param string|null $json The JSON string.
     * @return array|null The decoded array or null.
     */
    public static function decode_json($json) {
        if (empty($json) || !is_string($json)) return null;
        $decoded = json_decode($json, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
    }

    /**
     * Formats a value for display, handling arrays, booleans, and empty values.
     * @param mixed $value The value to format.
     * @param string $na_string The string to return for empty values.
     * @return string The formatted, HTML-safe string.
     */
    public static function format_display_value($value, $na_string = 'N/A') {
        if (is_string($value) && (strpos(trim($value), '[') === 0 || strpos(trim($value), '{') === 0)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (is_array($value)) {
            $filtered = array_filter($value, fn($item) => $item !== null && trim((string)$item) !== '');
            return empty($filtered) ? $na_string : esc_html(implode(', ', $filtered));
        }

        if (is_bool($value)) return $value ? 'Yes' : 'No';
        if ($value === null || trim((string)$value) === '' || trim((string)$value) === '[]') return $na_string;
        if (is_numeric($value)) {
            if ($value == 1) return 'Yes';
            if ($value == 0) return 'No';
        }
        if (is_string($value)) {
            $lower_value = strtolower(trim($value));
            if ($lower_value === 'yes') return 'Yes';
            if ($lower_value === 'no') return 'No';
        }

        return esc_html(trim((string)$value));
    }

    /**
     * Renders a grid item if the value is not empty or 'N/A'.
     * @param string $label The label for the grid item.
     * @param mixed $value The value to display.
     */
    public static function render_grid_item($label, $value) {
        $pretty_label = $label;
        $is_yn_field = (substr($pretty_label, -2) === 'YN');

        if ($is_yn_field) {
            $pretty_label = substr($pretty_label, 0, -2);
        }
        $pretty_label = ucwords(str_replace('_', ' ', preg_replace('/(?<!^)[A-Z]/', ' $0', $pretty_label)));
        if ($is_yn_field) {
            $pretty_label = 'Has ' . $pretty_label;
        }
        
        $formatted_value = self::format_display_value($value);

        if ($formatted_value !== 'N/A' && $formatted_value !== '') {
            echo '<div class="mld-grid-item"><strong>' . esc_html($pretty_label) . '</strong><span>' . $formatted_value . '</span></div>';
        }
    }
}
