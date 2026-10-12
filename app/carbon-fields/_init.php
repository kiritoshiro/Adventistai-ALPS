<?php
require_once('cf-global.php');
require_once('cf-post-options.php');

// LOAD CF FROM COMPOSER ADDED VENDOR DIR
add_action('after_setup_theme', 'crb_load');
function crb_load()
{
    require_once __DIR__ . '/../../vendor/autoload.php';
    \Carbon_Fields\Carbon_Fields::boot();
}

// RELEASE ZIPS SHIP ONLY THE MINIFIED CF BUILDS. WHEN SCRIPT_DEBUG ASKS FOR
// A READABLE BUILD THAT IS NOT THERE, SERVE ITS MINIFIED TWIN INSTEAD.
function crb_minified_asset_fallback($src)
{
    if (! defined('SCRIPT_DEBUG') || ! SCRIPT_DEBUG || ! is_string($src)) {
        return $src;
    }

    $base = get_template_directory_uri() . '/public/carbon-fields/build/';
    if (strpos($src, $base) !== 0) {
        return $src;
    }

    $path = strtok(substr($src, strlen($base)), '?#');
    if (! preg_match('#^(classic|gutenberg)/([a-z-]+)\.(js|css)$#', (string) $path, $m)) {
        return $src;
    }

    $dir = get_template_directory() . '/public/carbon-fields/build/';
    $min = "{$m[1]}/{$m[2]}.min.{$m[3]}";
    if (is_readable($dir . $path) || ! is_readable($dir . $min)) {
        return $src;
    }

    return $base . $min . substr($src, strlen($base) + strlen($path));
}

add_filter('script_loader_src', 'crb_minified_asset_fallback');
add_filter('style_loader_src', 'crb_minified_asset_fallback');

add_filter('use_widgets_block_editor', '__return_false');
add_filter('gutenberg_use_widgets_block_editor', '__return_false');

// ADD CF ADMIN STYLESHEET
function cf_admin_style()
{
    wp_enqueue_style('cf-admin-styles', get_template_directory_uri() . '/app/carbon-fields/cf-admin.css');
}

add_action('admin_enqueue_scripts', 'cf_admin_style');


// The theme's widgets keep Carbon Fields' "carbon_fields_" IDs so saved
// widgets carry over (app/Widgets/AlpsWidget.php). Carbon Fields' widget
// script takes such widgets for its own and fails on them, and no Carbon
// Fields fields are left on these screens, so its UI is not loaded there.
add_action('current_screen', function ($screen) {
    if ($screen && in_array($screen->id, ['widgets', 'customize'], true)) {
        $loader = \Carbon_Fields\Carbon_Fields::resolve('loader');
        remove_action('admin_print_footer_scripts', [$loader, 'enqueue_assets'], 9);
        remove_action('admin_print_footer_scripts', [$loader, 'initialize_ui'], 9999);
    }
});
