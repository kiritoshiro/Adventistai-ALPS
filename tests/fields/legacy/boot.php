<?php
/**
 * Test-only: loads Carbon Fields (a Composer dev dependency since the theme
 * stopped using it) with the definitions the theme had, so
 * tests/fields/compare.php can compare the theme's own code with Carbon
 * Fields itself. run.py compare calls alps_legacy_boot() from a Playground
 * mu-plugin; the theme never loads this file. The widgets
 * (cf-widget.php) are loaded by compare.php.
 */

if (! defined('ABSPATH')) {
    exit;
}

/** Returns false when Carbon Fields is not installed (composer install --no-dev). */
function alps_legacy_boot()
{
    $functions = get_template_directory() . '/vendor/htmlburger/carbon-fields/core/functions.php';
    if (! is_readable($functions)) {
        return false;
    }
    require_once $functions;
    require_once __DIR__ . '/cf-theme-options.php';
    require_once __DIR__ . '/cf-post-options.php';
    \Carbon_Fields\Carbon_Fields::boot();
    return true;
}
