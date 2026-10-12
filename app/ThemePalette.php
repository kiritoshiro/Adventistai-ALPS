<?php

namespace App;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The block editor and global-styles colour palette follows Appearance → ALPS
 * Theme Settings → Theme Color: the palette of that colour in colors.json
 * replaces the one in theme.json.
 *
 * This used to be done by rewriting theme.json on every request (which needs a
 * writable theme folder and was undone by each theme update until the next
 * request). The palette is now supplied through WordPress's
 * wp_theme_json_data_theme filter; theme.json itself is never written.
 */
class ThemePalette
{
    private static $colors;

    public static function register()
    {
        add_filter('wp_theme_json_data_theme', [__CLASS__, 'filter']);
    }

    /** @param \WP_Theme_JSON_Data $data */
    public static function filter($data)
    {
        $palette = self::palette();
        if ($palette) {
            $data->update_with([
                'version' => \WP_Theme_JSON::LATEST_SCHEMA,
                'settings' => ['color' => ['palette' => $palette]],
            ]);
        }
        return $data;
    }

    /** The palette of the selected theme colour, or null to keep theme.json's. */
    public static function palette()
    {
        // Before init the theme options are not available (as before, when the
        // file was rewritten while Carbon Fields registered its fields).
        $color = did_action('init') ? \App\Fields\Store::option('theme_color') : null;
        if (! is_string($color) || $color === '') {
            return null;
        }
        if (self::$colors === null) {
            $file = get_template_directory() . '/colors.json';
            $colors = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
            self::$colors = is_array($colors) ? $colors : [];
        }
        return ! empty(self::$colors[$color]) && is_array(self::$colors[$color]) ? self::$colors[$color] : null;
    }
}
