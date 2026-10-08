<?php

namespace App;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Prints small stylesheets inside the page instead of linking them.
 *
 * Plugins and blocks each add a 1–11 KB stylesheet, and in this theme they
 * all end up in <head> (wp_footer runs before the head is printed), so every
 * one of them is a separate request that blocks the first paint. WordPress
 * already inlines styles that carry `path` data (wp_maybe_inline_styles(),
 * on wp_head and wp_footer at priority 1, smallest first, up to
 * `styles_inline_size_limit` bytes in total, rewriting relative url()s).
 * This adds that data to every queued local stylesheet of at most MAX_FILE
 * bytes. Larger files (the theme's main CSS, the calendar's) stay linked and
 * cacheable. The cascade order does not change: the <style> is printed where
 * the <link> would have been.
 */
class InlineStyles
{
    /** Largest stylesheet that is inlined, in bytes. */
    const MAX_FILE = 12000;

    /** Total inlined per page, in bytes (WordPress' default is 20000). */
    const LIMIT = 90000;

    public static function register()
    {
        if (is_admin()) {
            return;
        }
        add_action('wp_head', [__CLASS__, 'markSmallStyles'], 0);
        // WordPress runs wp_enqueue_scripts on wp_head priority 1, after the
        // pass above and just before wp_maybe_inline_styles (also priority 1),
        // so stylesheets enqueued there (the Bible, calendar, cookie banner and
        // other plugin ones) were never marked. Mark them once all are queued.
        add_action('wp_enqueue_scripts', [__CLASS__, 'markSmallStyles'], PHP_INT_MAX);
        add_action('wp_footer', [__CLASS__, 'markSmallStyles'], 0);
        // Plugins that decide in wp_footer whether the page needs them (the
        // calendar, the Bible popups) enqueue after the two passes above, so
        // their small stylesheets stayed linked. One more pass right before
        // WordPress prints those late styles (_wp_footer_scripts, priority 10).
        add_action('wp_print_footer_scripts', [__CLASS__, 'inlineLateStyles'], 5);
        add_filter('styles_inline_size_limit', [__CLASS__, 'limit']);
    }

    public static function inlineLateStyles()
    {
        self::markSmallStyles();
        if (function_exists('wp_maybe_inline_styles')) {
            // Already inlined styles have no src any more and are skipped.
            wp_maybe_inline_styles();
        }
    }

    public static function limit($limit)
    {
        return max((int) $limit, self::LIMIT);
    }

    /**
     * Absolute path of a stylesheet URL under wp-content or wp-includes on
     * this site; '' for anything else.
     *
     * @param mixed $src Registered style source.
     * @return string
     */
    public static function localPath($src)
    {
        if (! is_string($src) || '' === $src) {
            return '';
        }
        $url = strtok($src, '?#');
        $bases = [
            content_url('/') => WP_CONTENT_DIR . '/',
            includes_url('/') => ABSPATH . WPINC . '/',
        ];
        foreach ($bases as $base => $dir) {
            $relative = null;
            if (0 === strpos($url, $base)) {
                $relative = substr($url, strlen($base));
            } elseif (0 === strpos($url, (string) wp_parse_url($base, PHP_URL_PATH)) && '/' === $url[0]) {
                $relative = substr($url, strlen((string) wp_parse_url($base, PHP_URL_PATH)));
            }
            if (null === $relative || '.css' !== substr($relative, -4)) {
                continue;
            }
            $path = realpath($dir . rawurldecode($relative));
            $root = realpath($dir);
            if ($path && $root && 0 === strpos($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) {
                return $path;
            }
        }
        return '';
    }

    public static function markSmallStyles()
    {
        $styles = wp_styles();
        foreach ($styles->queue as $handle) {
            $style = isset($styles->registered[$handle]) ? $styles->registered[$handle] : null;
            if (! $style || ! $style->src || $styles->get_data($handle, 'path') || in_array($handle, $styles->done, true)) {
                continue;
            }
            $media = isset($style->args) && is_string($style->args) ? $style->args : 'all';
            if (! in_array($media, ['all', 'screen', ''], true)) {
                continue;
            }
            $path = self::localPath($style->src);
            $size = '' !== $path ? (int) filesize($path) : 0;
            if ($size > 0 && $size <= self::MAX_FILE) {
                wp_style_add_data($handle, 'path', $path);
            }
        }
    }
}
