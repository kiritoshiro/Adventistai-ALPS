<?php

namespace App;

use App\Fields\Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * ALPS Theme Settings → POSTS OPTIONS → Sidebar position (3.37.0), and the
 * page header lined up with the article.
 *
 * "Right" (the default) keeps the sidebar on the right; "Left" puts the widget
 * sidebar of posts, pages and archives on the left on wide screens, so the
 * article sits between the sidebar and the Sabbath column instead of
 * everything heavy being on the right. Only the visual order changes: the
 * article stays first in the HTML, so phones, screen readers and search
 * engines still get it before the widgets.
 *
 * With either position, on wide screens and pages with a sidebar, the page
 * header's title starts where the article text starts and the featured image
 * sits over the sidebar column (assets/css/sidebar-layout.css, embedded on
 * every page).
 *
 * "Left side column" (3.38.0) turns the sidebar into a full-height column at
 * the left edge of the window, below the logo bar and the header band, like
 * the Sabbath column on the right; the article is centred between the two.
 * The header band keeps its full width and usual layout.
 *
 * "Left side column, scaled like the Sabbath column" (3.40.0) is that column
 * at a seventh of the window, as wide as the Sabbath column, from 701px up;
 * its widgets are zoomed with it, so they look the same at every browser
 * zoom, only larger or smaller.
 */
class SidebarPosition
{
    const CSS = '/assets/css/sidebar-layout.css';
    const RAIL_CSS = '/assets/css/sidebar-rail.css';
    const RAIL_SCALED_CSS = '/assets/css/sidebar-rail-scaled.css';

    /** Whether the page being rendered shows the widget sidebar. */
    private static $shown = false;

    public static function register()
    {
        add_filter('body_class', [__CLASS__, 'bodyClass']);
        // After the responsive zoom fixes (priority 1000), which are the last layout rules.
        add_action('wp_enqueue_scripts', [__CLASS__, 'styles'], 1001);
    }

    /** right (default), left, rail or rail-scaled. */
    public static function position()
    {
        return (string) Store::option('sidebar_position');
    }

    public static function left()
    {
        return self::position() === 'left';
    }

    /** Either form of the left side column. */
    public static function rail()
    {
        return in_array(self::position(), ['rail', 'rail-scaled'], true);
    }

    /**
     * Called by the content templates where they print the sidebar. Blade
     * renders the page's sections before the layout that prints <body>, so
     * the body class below already knows, and the header's layout is set from
     * the first byte instead of changing once the sidebar markup (after the
     * article) arrives.
     */
    public static function markShown()
    {
        self::$shown = true;
    }

    public static function bodyClass(array $classes)
    {
        $position = self::position();
        if ($position === 'left') {
            $classes[] = 'alps-sidebar-left';
        }
        if (self::rail()) {
            $classes[] = 'alps-sidebar-rail';
        }
        if (self::$shown) {
            // The side column has its own rules; the header keeps its usual layout there.
            $classes[] = self::rail() ? 'alps-has-rail' : 'alps-has-sidebar';
        }
        return $classes;
    }

    public static function styles()
    {
        if (! wp_style_is('adventistai-overrides')) {
            return;
        }
        $css = embedded_css(get_template_directory() . self::CSS);
        if (self::rail()) {
            // Above 1000px, where the article and the sidebar sit side by side;
            // the scaled column from 701px, like the Sabbath column.
            $scaled = self::position() === 'rail-scaled';
            $rail = embedded_css(get_template_directory() . self::RAIL_CSS);
            if ($scaled) {
                $rail .= embedded_css(get_template_directory() . self::RAIL_SCALED_CSS);
            }
            if ($rail !== '') {
                $css .= '@media (min-width:' . ($scaled ? 701 : 1001) . 'px){' . $rail . '}';
            }
        }
        if ($css !== '') {
            wp_add_inline_style('adventistai-overrides', $css);
        }
    }
}
