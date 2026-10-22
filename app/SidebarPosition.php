<?php

namespace App;

use App\Fields\Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * ALPS Theme Settings → POSTS OPTIONS → Sidebar position (3.37.0).
 *
 * "Right" (the default) keeps the ALPS layout. "Left" puts the widget
 * sidebar of posts, pages and archives on the left on wide screens, so the
 * article sits between the sidebar and the Sabbath column instead of
 * everything heavy being on the right. Only the visual order changes
 * (assets/css/sidebar-left.css, embedded only when "Left" is chosen): the
 * article stays first in the HTML, so phones, screen readers and search
 * engines still get it before the widgets.
 */
class SidebarPosition
{
    const CSS = '/assets/css/sidebar-left.css';

    public static function register()
    {
        add_filter('body_class', [__CLASS__, 'bodyClass']);
        // After the responsive zoom fixes (priority 1000), which are the last layout rules.
        add_action('wp_enqueue_scripts', [__CLASS__, 'styles'], 1001);
    }

    public static function left()
    {
        return Store::option('sidebar_position') === 'left';
    }

    public static function bodyClass(array $classes)
    {
        if (self::left()) {
            $classes[] = 'alps-sidebar-left';
        }
        return $classes;
    }

    public static function styles()
    {
        if (! self::left() || ! wp_style_is('adventistai-overrides')) {
            return;
        }
        $css = embedded_css(get_template_directory() . self::CSS);
        if ($css !== '') {
            wp_add_inline_style('adventistai-overrides', $css);
        }
    }
}
