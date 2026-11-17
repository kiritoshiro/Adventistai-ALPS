<?php

/**
 * WordPress and theme stubs for tests/campaign/run.php. The settings live in
 * $GLOBALS['options'] (stored rows: a missing name reads as the field's
 * default, as App\Fields\Store does), the request in $GLOBALS['page']
 * (front page or not, latest posts or not, the post's meta and fields).
 */

namespace App\Fields {
    class Store
    {
        public static function option($name)
        {
            if (array_key_exists($name, $GLOBALS['options'])) {
                return $GLOBALS['options'][$name];
            }
            $field = Registry::field('option', $name);
            if (!$field) {
                return null;
            }
            $default = $field['default'] ?? '';
            return $field['type'] === 'checkbox' ? (bool) $default : $default;
        }

        public static function thePost($name)
        {
            return $GLOBALS['page']['fields'][$name] ?? '';
        }
    }
}

namespace {
    const ABSPATH = __DIR__ . '/';
    const THEME_URI = 'https://example.test/wp-content/themes/adventistai';

    function __($text, $domain = '') { return $text; }
    function apply_filters($hook, $value) { return $value; }
    function do_action($hook) {}
    function is_plugin_active($plugin) { return false; }
    function absint($value) { return abs((int) $value); }
    function wp_strip_all_tags($text) { return trim(strip_tags((string) $text)); }
    function sanitize_text_field($text) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $text))); }
    function current_user_can($capability) { return true; }
    function wp_kses_post($text) { return (string) $text; }
    function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }

    /**
     * WordPress's esc_url() in its two contexts (wp-includes/formatting.php,
     * 6.x), without the IPv6 branch: 'db' (esc_url_raw) cleans and checks the
     * protocol; 'display' (esc_url) also normalises entities and encodes & and '.
     */
    function wp_like_esc_url($url, $protocols, $context)
    {
        $url = str_replace(' ', '%20', ltrim((string) $url));
        $url = preg_replace('|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\\x80-\\xff]|i', '', $url);
        if ($url === '') {
            return '';
        }
        if (0 !== stripos($url, 'mailto:')) {
            do {
                $before = $url;
                $url = str_replace(['%0d', '%0a', '%0D', '%0A'], '', $url);
            } while ($url !== $before);
        }
        $url = str_replace(';//', '://', $url);
        if (!str_contains($url, ':') && !in_array($url[0], ['/', '#', '?'], true) && !preg_match('/^[a-z0-9-]+?\.php/i', $url)) {
            $url = 'http://' . $url;
        }
        if ($context === 'display') {
            // wp_kses_normalize_entities(), as far as numeric references go: kept.
            $url = preg_replace('/&(?!#\d+;|#x[0-9a-f]+;|[a-z]+;)/i', '&amp;', $url);
            $url = str_replace(['&amp;', "'"], ['&#038;', '&#039;'], $url);
        }
        if ($url[0] !== '/') {
            $scheme = strtolower((string) strstr($url, ':', true));
            if (str_contains($url, ':') && !in_array($scheme, $protocols ?: ['http', 'https', 'mailto'], true)) {
                return '';
            }
        }
        return $url;
    }
    function esc_url_raw($url, $protocols = null) { return wp_like_esc_url($url, $protocols, 'db'); }
    function esc_url($url, $protocols = null) { return wp_like_esc_url($url, $protocols, 'display'); }

    function get_template_directory_uri() { return THEME_URI; }
    function get_template_directory() { return dirname(__DIR__, 3); }

    // The request.
    function is_front_page() { return $GLOBALS['page']['front']; }
    function is_home() { return $GLOBALS['page']['home'] ?? false; }
    function is_archive() { return false; }
    function is_singular($type = null) { return false; }
    function is_page_template($template) { return false; }
    function is_active_sidebar($id) { return false; }
    function get_option($name) { return $GLOBALS['page']['wp_options'][$name] ?? 0; }
    function get_alps_option($name) { return null; }
    function get_alps_field($name) { return null; }
    function get_post_meta($id, $key, $single = false) { return $GLOBALS['page']['meta'][$key] ?? ''; }
    function get_the_title($id) { return $GLOBALS['page']['title']; }
    function get_post_thumbnail_id($id) { return 0; }
    function get_post($id) { return (object) ['ID' => 5, 'post_title' => $GLOBALS['page']['title'], 'post_excerpt' => '']; }
    function get_the_date($format, $id) { return '2026-11-17'; }
    function get_the_category($id) { return []; }
    function get_the_post_thumbnail_caption($id) { return ''; }
    function have_posts() { return $GLOBALS['loop']-- > 0; }
    function the_post() { return ''; }
    function the_content() { echo '<p>Turinys.</p>'; }
    function post_class($classes) { echo 'class="' . $classes . '"'; }

    // Media Library: attachment 41 is an image, 42 a PDF, 77 the hero image.
    function wp_attachment_is_image($id) { return in_array((int) $id, [41, 77], true); }
    function wp_get_attachment_image_src($id, $size)
    {
        return match ((int) $id) {
            41 => ['https://example.test/uploads/ruduo.jpg', 1600, 1200, false],
            77 => ["https://example.test/uploads/hero-$size.jpg", 1200, 600, false],
            default => false,
        };
    }
    function wp_get_attachment_image_srcset($id, $size) { return (int) $id === 41 ? 'https://example.test/uploads/ruduo-800.jpg 800w, https://example.test/uploads/ruduo.jpg 1600w' : false; }
}
