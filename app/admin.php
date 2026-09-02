<?php

namespace App;

/**
 * Theme customizer
 */
add_action('customize_register', function (\WP_Customize_Manager $wp_customize) {
    // Add postMessage support
    $wp_customize->get_setting('blogname')->transport = 'postMessage';
    $wp_customize->selective_refresh->add_partial('blogname', [
        'selector' => '.brand',
        'render_callback' => function () {
            bloginfo('name');
        }
    ]);
});

/**
 * Customizer JS
 */
add_action('customize_preview_init', function () {
    wp_enqueue_script('sage/customizer.js', asset_path('scripts/customizer.js'), ['customize-preview'], null, true);
});
/**
 * Keep post-list titles readable in the intermediate responsive range.
 *
 * WordPress does not switch its list tables to the mobile layout until 782px.
 * Browser zoom can therefore leave the desktop table in a narrow content area,
 * where plugin-added columns squeeze the title down to one character per line.
 */
add_action('admin_enqueue_scripts', function ($hook_suffix) {
    if ($hook_suffix !== 'edit.php') {
        return;
    }

    $relative = '/assets/css/admin-post-list.css';
    $file = get_template_directory() . $relative;

    if (is_readable($file)) {
        wp_enqueue_style(
            'adventistai-admin-post-list',
            get_template_directory_uri() . $relative,
            [],
            (string) filemtime($file)
        );
    }
});
