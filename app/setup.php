<?php

/**
 * Theme setup.
 */

namespace App;

use function Roots\bundle;

/**
 * Register the theme assets.
 *
 * @return void
 */
add_action('wp_enqueue_scripts', function () {
    bundle('app')->enqueue();
}, 100);

/**
 * Final responsive zoom fixes.
 * Printed straight after site-overrides.css (as an embedded style) so the responsive
 * corrections remain authoritative without modifying the accumulated override
 * stylesheet itself. Embedded because the file is small and a separate
 * stylesheet would be one more render-blocking request.
 */
add_action('wp_enqueue_scripts', function () {
    $file = get_template_directory() . '/assets/css/responsive-zoom-fixes.css';

    if (wp_style_is('adventistai-overrides') && is_readable($file)) {
        wp_add_inline_style('adventistai-overrides', (string) file_get_contents($file));
    }
}, 1000);

/**
 * ALPS pattern-library stylesheet and script.
 *
 * It used to be printed straight from head.blade.php after wp_head(), so it
 * came after every enqueued stylesheet. Enqueuing it last keeps that cascade
 * position while letting WordPress manage, version and preload it. The Sabbath
 * timer is front-page only and small, so its rules are embedded instead
 * of costing another render-blocking request.
 */
add_action('wp_enqueue_scripts', function () {
    $alps = Core\ALPSVersions::get();
    $color = get_alps_option('theme_color');
    $url = $alps['styles']['main'];
    if ($color && isset($alps['styles']['themes'][$color])) {
        $url = $alps['styles']['themes'][$color];
    }

    wp_enqueue_style('alps-main', $url, [], theme_asset_version($url));

    // The pattern-library script reads the global jQuery as soon as it runs,
    // so declare it as a dependency instead of relying on a plugin to load it.
    $script = $alps['scripts']['main'];
    wp_enqueue_script('alps-main', $script, ['jquery'], theme_asset_version($script), [
        'in_footer' => true,
        'strategy' => 'async',
    ]);

    $timer = get_template_directory() . '/assets/css/sabbath-timer.css';
    if (is_front_page() && is_readable($timer)) {
        wp_add_inline_style('alps-main', (string) file_get_contents($timer));
    }
}, 9999);

/**
 * Preload the fonts used above the fold before any stylesheet is requested.
 * Regular and Bold Noto Sans cover the navigation and headings (550-700
 * weights resolve to the Bold face); Source Serif covers the hero text.
 */
add_action('wp_head', function () {
    $fonts = [
        '/assets/fonts/noto-sans/NotoSans-Regular.woff2',
        '/assets/fonts/noto-sans/NotoSans-Bold.woff2',
        '/assets/fonts/source-serif/SourceSerif4-Variable.woff2',
    ];

    foreach ($fonts as $font) {
        printf(
            '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
            esc_url(get_template_directory_uri() . $font)
        );
    }
}, 2);

/**
 * WordPress's emoji polyfill only matters for browsers without native emoji,
 * which no supported browser lacks. Drop its detection script and styles.
 */
add_action('init', function () {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('embed_head', 'print_emoji_detection_script');
    remove_action('enqueue_embed_scripts', 'wp_enqueue_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
});

/**
 * File modification time for a URL inside this theme, used as the asset
 * version so caches can be long-lived. Returns null for other URLs.
 */
function theme_asset_version(string $url): ?string
{
    $base = get_template_directory_uri();
    if (strpos($url, $base) !== 0) {
        return null;
    }

    $file = get_template_directory() . str_replace(['..', "\0"], '', substr($url, strlen($base)));
    return is_readable($file) ? (string) filemtime($file) : null;
}

/**
 * Register the theme's editor styles.
 *
 * Styles go through enqueue_block_assets so WordPress injects them into the
 * editor iframe. Scripts must not: see below.
 *
 * @return void
 */
add_action('enqueue_block_assets', function () {
    if (is_admin()) {
        bundle('editor')->enqueueCss();
    }
}, 100);

/**
 * Register the theme's editor scripts.
 *
 * registerBlockType() has to run in the outer editor frame, where the block
 * registry and inserter live. Since WordPress iframed the canvas in 6.3,
 * enqueue_block_assets is the wrong hook for that, so block registration is
 * enqueued here instead and styles are left on the hook that reaches the
 * iframe. enqueueJs() emits the webpack runtime itself.
 *
 * @return void
 */
add_action('enqueue_block_editor_assets', function () {
    bundle('editor')->enqueueJs();
}, 100);

/**
 * Register the initial theme setup.
 *
 * @return void
 */
add_action('after_setup_theme', function () {
    /**
     * Enable features from the Soil plugin if activated.
     * @link https://roots.io/plugins/soil/
     */
    add_theme_support('soil', [
        'clean-up',
        'nav-walker',
        'nice-search',
        'relative-urls',
    ]);

    /**
     * Disable full-site editing support.
     *
     * @link https://wptavern.com/gutenberg-10-5-embeds-pdfs-adds-verse-block-color-options-and-introduces-new-patterns
     */
    remove_theme_support('block-templates');

    /**
     * Register the navigation menus.
     * @link https://developer.wordpress.org/reference/functions/register_nav_menus/
     */
    register_nav_menus([
        'primary_navigation' => __('Primary Navigation', 'alps'),
        'secondary_navigation' => __('Secondary Navigation', 'alps'),
        'learn_more_navigation' => __('Learn More', 'alps'),
        'footer_primary_navigation' => __('Footer Primary Navigation', 'alps'),
        'footer_secondary_navigation' => __('Footer Secondary Navigation', 'alps')
    ]);

    /**
     * Disable the default block patterns.
     * @link https://developer.wordpress.org/block-editor/developers/themes/theme-support/#disabling-the-default-block-patterns
     */
    remove_theme_support('core-block-patterns');

    /**
     * Enable plugins to manage the document title.
     * @link https://developer.wordpress.org/reference/functions/add_theme_support/#title-tag
     */
    add_theme_support('title-tag');

    /**
     * Enable post thumbnail support.
     * @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
     */
    add_theme_support('post-thumbnails');

    /**
     * Enable responsive embed support.
     * @link https://wordpress.org/gutenberg/handbook/designers-developers/developers/themes/theme-support/#responsive-embedded-content
     */
    add_theme_support('responsive-embeds');

    /**
     * Enable HTML5 markup support.
     * @link https://developer.wordpress.org/reference/functions/add_theme_support/#html5
     */
    add_theme_support('html5', [
        'caption',
        'comment-form',
        'comment-list',
        'gallery',
        'search-form',
        'script',
        'style',
    ]);

    /**
     * Enable selective refresh for widgets in customizer.
     * @link https://developer.wordpress.org/themes/advanced-topics/customizer-api/#theme-support-in-sidebars
     */
    add_theme_support('customize-selective-refresh-widgets');
    load_theme_textdomain('alps', get_theme_file_path('/resources/lang'));

    /**
     * adventistai.lt: guarantee Lithuanian theme strings on the public site
     * even when the WordPress site language is not set to lt_LT.
     */
    if (!is_admin() && strpos(determine_locale(), 'lt') !== 0) {
        $alps_lt_mo = get_theme_file_path('/resources/lang/lt_LT.mo');
        if (is_readable($alps_lt_mo)) {
            load_textdomain('alps', $alps_lt_mo);
        }
    }

}, 20);

/**
 * Ensure the built-in search and drawer controls are editable as part of the
 * Secondary Navigation menu. This migration runs once for existing installs;
 * afterwards administrators can rename, reorder, or remove the items normally.
 */
add_action('init', function () {
    $migration_version = '1';

    if (get_option('adventistai_secondary_navigation_actions_version') === $migration_version) {
        return;
    }

    $locations = get_nav_menu_locations();
    if (empty($locations['secondary_navigation'])) {
        return;
    }

    $menu = wp_get_nav_menu_object($locations['secondary_navigation']);
    if (!$menu) {
        return;
    }

    $items = wp_get_nav_menu_items($menu->term_id);
    $items = is_array($items) ? $items : [];
    $actions = [
        'search' => [
            'title' => __('Search', 'alps'),
            'class' => 'alps-search-toggle',
        ],
        'menu' => [
            'title' => __('Menu', 'alps'),
            'class' => 'alps-menu-toggle',
        ],
    ];
    $synced = true;

    foreach ($actions as $fragment => $action) {
        $already_present = false;

        foreach ($items as $item) {
            $classes = array_filter((array) $item->classes);
            $item_fragment = strtolower((string) wp_parse_url($item->url, PHP_URL_FRAGMENT));

            if ($item_fragment === $fragment || in_array($action['class'], $classes, true)) {
                $already_present = true;
                break;
            }
        }

        if ($already_present) {
            continue;
        }

        $result = wp_update_nav_menu_item($menu->term_id, 0, [
            'menu-item-title' => $action['title'],
            'menu-item-url' => '#' . $fragment,
            'menu-item-classes' => $action['class'],
            'menu-item-type' => 'custom',
            'menu-item-status' => 'publish',
        ]);

        if (is_wp_error($result)) {
            $synced = false;
        }
    }

    if ($synced) {
        update_option('adventistai_secondary_navigation_actions_version', $migration_version, false);
    }
}, 20);

/**
 * Register the theme sidebars.
 *
 * @return void
 */

add_action('widgets_init', function () {
    $config = [
        'before_widget' => '<section class="c-widget c-%1$s c-%2$s o-link-wrapper--underline u-spacing u-background-color--gray--light u-padding u-theme--border-color--darker u-border--left can-be--dark-dark">',
        'after_widget'  => '</section>',
        'before_title'  => '<div class="c-block__heading-title u-theme--color--darker">',
        'after_title'   => '</div>'
    ];
    register_sidebar([
        'name'          => __('Page Top', 'alps'),
        'id'            => 'section-page-top'
    ] + $config);
    register_sidebar([
        'name'          => __('Page Bottom', 'alps'),
        'id'            => 'section-page-bottom'
    ] + $config);
    register_sidebar([
        'name'          => __('Page Sidebar', 'alps'),
        'id'            => 'sidebar-page'
    ] + $config);
    register_sidebar([
        'name'          => __('Posts Sidebar', 'alps'),
        'id'            => 'sidebar-posts'
    ] + $config);
    register_sidebar([
        'name'          => __('Post Footer Region', 'alps'),
        'id'            => 'footer-region-post'
    ] + $config);
    register_sidebar([
        'name'          => __('Footer Region', 'alps'),
        'id'            => 'footer-region'
    ] + $config);
    register_sidebar([
        'name'          => __('Category Top', 'alps'),
        'id'            => 'category-top'
    ] + $config);
    register_sidebar([
        'name'          => __('Category Bottom', 'alps'),
        'id'            => 'category-bottom'
    ] + $config);
});

/**
 * Custom image styles.
 */
// Featured crop.
add_image_size('featured__hero--s', 500, 400, array('center', 'center'));
add_image_size('featured__hero--m', 800, 500, array('center', 'center'));
add_image_size('featured__hero--l', 1600, 500, array('center', 'center'));
add_image_size('featured__hero--xl', 2100, 600, array('center', 'center'));

// 16:9 crop.
add_image_size('horiz__16x9--s', 500, 280, array('center', 'center'));
add_image_size('horiz__16x9--m', 800, 450, array('center', 'center'));
add_image_size('horiz__16x9--l', 1100, 620, array('center', 'center'));

// 4:3 crop.
add_image_size('horiz__4x3--s', 500, 375, array('center', 'center'));
add_image_size('horiz__4x3--m', 700, 600, array('center', 'center'));
add_image_size('horiz__4x3--l', 900, 700, array('center', 'center'));

// 3:4 crop for portraits.
add_image_size('vert__3x4--s', 450, 600, array('center', 'center'));
add_image_size('vert__3x4--m', 600, 800, array('center', 'center'));

// Flexible height
add_image_size('flex-height--s', 350, 9999);
add_image_size('flex-height--m', 700, 9999);
add_image_size('flex-height--l', 1100, 9999);
add_image_size('flex-height--xl', 1600, 9999);

// Square
add_image_size('thumbnail--s', 400, 400, array('center', 'center'));
add_image_size('thumbnail--m', 800, 800, array('center', 'center'));

// Makes image size available in dashboard for Gutenberg blocks.
add_action('admin_init', function() {
  $custom_sizes['horiz__16x9--m'] = 'Medium 16:9 (800x450)';
  add_filter(
    'image_size_names_choose',
    function( $sizes ) use ( $custom_sizes ) {
      return array_merge( $sizes, $custom_sizes );
    }
  );
});
