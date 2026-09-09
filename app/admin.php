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


/**
 * Detect MEC files that make the plugin silently load theme-owned templates.
 *
 * Release builds reject these paths. This runtime notice additionally catches
 * stale files left behind by manual uploads or an old child theme.
 */
add_action('admin_notices', function () {
    if (!current_user_can('update_themes') || !defined('MECEXEC')) {
        return;
    }

    $relative_paths = [
        'webnus/modern-events-calendar',
        'webnus/modern-events-calendar-lite',
        'single-mec-events.php',
        'archive-mec-events.php',
        'taxonomy-mec-category.php',
        'header-mec.php',
        'footer-mec.php',
    ];
    $theme_roots = array_unique([
        get_template_directory(),
        get_stylesheet_directory(),
    ]);
    $found = [];

    foreach ($theme_roots as $theme_root) {
        foreach ($relative_paths as $relative_path) {
            $absolute_path = trailingslashit($theme_root) . $relative_path;
            if (file_exists($absolute_path)) {
                $found[] = wp_basename($theme_root) . '/' . $relative_path;
            }
        }
    }

    if (!$found) {
        return;
    }

    echo '<div class="notice notice-error"><p>';
    echo '<strong>' . esc_html__('Adventistai ALPS: aptiktas MEC temos perrašymas.', 'alps') . '</strong> ';
    echo esc_html__('Šie failai apeina Modern Events Calendar įskiepio failus ir gali palikti seną veikimą po įskiepio atnaujinimo:', 'alps');
    echo ' <code>' . esc_html(implode(', ', array_unique($found))) . '</code>. ';
    echo esc_html__('Pašalinkite juos iš aktyvios temos; MEC turi būti valdomas tik įskiepio.', 'alps');
    echo '</p></div>';
});
