<?php

namespace App;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps scripts from blocking the first paint.
 *
 * jQuery is printed in <head> as a blocking script by WordPress, and some
 * plugin scripts are printed in the footer without a loading strategy; the
 * browser stops parsing at each of them. On the front page for visitors,
 * jQuery moves to the footer with the `defer` strategy. WordPress decides at
 * print time whether it may really be deferred: a head script that needs
 * jQuery pulls it back into <head>, and a blocking footer script that needs
 * it keeps it blocking (wp_scripts' eligible loading strategy). The front
 * page prints no embedded script that uses jQuery, which is what makes this
 * safe there; other pages keep the default.
 */
class ScriptLoading
{
    /**
     * Footer scripts of other plugins that only add delegated event
     * listeners, so running them after the HTML is parsed changes nothing.
     * knygos-front (Knygos showcase popups) listens on document for clicks
     * and Escape.
     */
    const DEFER = ['knygos-front'];

    public static function register()
    {
        if (is_admin()) {
            return;
        }
        add_action('wp_enqueue_scripts', [__CLASS__, 'apply'], PHP_INT_MAX);
        // Plugins that enqueue while rendering content (shortcodes) do it
        // after the pass above; footer scripts are printed at priority 10.
        add_action('wp_print_footer_scripts', [__CLASS__, 'apply'], 1);
    }

    /**
     * Whether jQuery is deferred on this request. Filterable through
     * `adventistai_defer_jquery`.
     */
    public static function deferJquery(): bool
    {
        return (bool) apply_filters('adventistai_defer_jquery', is_front_page() && ! is_user_logged_in());
    }

    public static function apply()
    {
        $scripts = wp_scripts();
        foreach (self::DEFER as $handle) {
            if (isset($scripts->registered[$handle]) && ! $scripts->get_data($handle, 'strategy')) {
                $scripts->add_data($handle, 'strategy', 'defer');
            }
        }

        if (! self::deferJquery()) {
            return;
        }
        foreach (['jquery', 'jquery-core', 'jquery-migrate'] as $handle) {
            if (! isset($scripts->registered[$handle]) || in_array($handle, $scripts->done, true)) {
                continue;
            }
            $scripts->add_data($handle, 'group', 1);
            if ('jquery' !== $handle) {
                $scripts->add_data($handle, 'strategy', 'defer');
            }
        }
        // An async script could run before the deferred jQuery it needs.
        if (isset($scripts->registered['alps-main']) && 'async' === $scripts->get_data('alps-main', 'strategy')) {
            $scripts->add_data('alps-main', 'strategy', 'defer');
        }
    }
}
