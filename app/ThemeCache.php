<?php

namespace App;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Acorn's compiled Blade views, in wp-content/cache/acorn/framework/views/
 * (Acorn's default storage path). After a
 * theme update the old compiled views could stay in use, so the cache is
 * cleared automatically whenever the theme version changes, and on request
 * from the admin bar or Appearance → ALPS Theme Settings → Cache. Everything
 * removed is rebuilt on the next page view.
 */
class ThemeCache
{
    const OPTION = 'adventistai_theme_cache_version';
    const ACTION = 'adventistai_clear_theme_cache';
    const CAPABILITY = 'switch_themes';

    public static function register()
    {
        add_action('after_setup_theme', [__CLASS__, 'clearAfterUpdate'], 1);
        add_action('admin_bar_menu', [__CLASS__, 'adminBar'], 100);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handleRequest']);
        add_action('admin_notices', [__CLASS__, 'notice']);
    }

    /** Acorn's storage directory; its default when Acorn is not booted. */
    public static function storagePath()
    {
        try {
            if (function_exists('app') && app()->bound('path.storage')) {
                return rtrim((string) app()->storagePath(), '/\\');
            }
        } catch (\Throwable $e) {
            // Fall through to Acorn's default.
        }
        return rtrim(WP_CONTENT_DIR, '/\\') . '/cache/acorn';
    }

    /**
     * Deletes the compiled views: .php files directly in framework/views.
     * The package and service manifests in framework/cache are left alone:
     * Acorn requires services.php once it exists, and a test showed the theme
     * failing to boot ("You need to install Acorn") after it was deleted.
     *
     * @return int Number of files removed.
     */
    public static function clear()
    {
        $storage = self::storagePath();
        $files = glob($storage . '/framework/views/*.php') ?: [];
        $removed = 0;
        foreach ($files as $file) {
            if (is_file($file) && ! is_link($file)) {
                wp_delete_file($file);
                $removed += is_file($file) ? 0 : 1;
            }
        }
        return $removed;
    }

    /** Clears the cache once after the theme version changes (update or upload). */
    public static function clearAfterUpdate()
    {
        $version = (string) wp_get_theme(get_template())->get('Version');
        if ('' === $version || get_option(self::OPTION) === $version) {
            return;
        }
        self::clear();
        update_option(self::OPTION, $version, true);
    }

    private static function clearUrl()
    {
        return wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION), self::ACTION);
    }

    /** @param \WP_Admin_Bar $bar */
    public static function adminBar($bar)
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }
        $bar->add_node([
            'id'    => 'adventistai-clear-theme-cache',
            'title' => esc_html__('Clear theme cache', 'alps'),
            'href'  => self::clearUrl(),
            'meta'  => ['title' => esc_attr__('Deletes the theme\'s compiled templates; they are rebuilt on the next page view.', 'alps')],
        ]);
    }

    public static function handleRequest()
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to clear the theme cache.', 'alps'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);
        $removed = self::clear();
        $back = wp_get_referer();
        if (! $back || false === strpos($back, '/wp-admin/')) {
            $back = admin_url('themes.php?page=alps-theme-options');
        }
        wp_safe_redirect(add_query_arg('adventistai_cache_cleared', $removed, $back));
        exit;
    }

    public static function notice()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: a count after the redirect.
        if (! isset($_GET['adventistai_cache_cleared']) || ! current_user_can(self::CAPABILITY)) {
            return;
        }
        $count = absint(wp_unslash($_GET['adventistai_cache_cleared'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        /* translators: %d: number of cache files deleted */
        $message = sprintf(_n('Theme cache cleared (%d file).', 'Theme cache cleared (%d files).', $count, 'alps'), $count);
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    /** Content of the Cache tab in ALPS Theme Settings. */
    public static function settingsHtml()
    {
        return '<p>' . esc_html__('The theme keeps compiled templates in a cache. It is cleared automatically after every theme update; clear it by hand if pages still show an old layout or an error after an update.', 'alps') . '</p>'
            . '<p><code>' . esc_html(self::storagePath() . '/framework/views') . '</code></p>'
            . '<p><a class="button button-secondary" href="' . esc_url(self::clearUrl()) . '">' . esc_html__('Clear theme cache', 'alps') . '</a></p>';
    }
}
