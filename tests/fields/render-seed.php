<?php
/**
 * Content for render-compare.py: pages and posts that use every field the
 * templates read (each hero style, header options, related pages, sidebar
 * and featured-image switches, a Related Stories widget) and theme options
 * for the header, footer and Sabbath column. Saved through Carbon Fields'
 * own API. Writes the URLs to check to fixtures/render-urls.json.
 *
 * Throwaway WordPress only (WordPress Playground): it creates content and
 * overwrites theme options. Also defines alps_render_options($set), which
 * render-compare.py calls again to switch to the second set of options.
 */

if (! defined('ABSPATH')) {
    exit;
}

function alps_render_options($set)
{
    $images = get_option('alps_render_images');
    $common = [
        'logo' => $images[0],
        'sabbath_icon' => $images[1],
        'sabbath_background' => $images[2],
        'footer_logo_icon' => $images[1],
        'footer_copyright' => 'Render test copyright',
        'footer_address' => [['footer_address_street' => 'Street 1', 'footer_address_city' => 'Kaunas', 'footer_address_state' => '', 'footer_address_zip' => 'LT-1', 'footer_address_country' => 'Lietuva', 'footer_phone' => '+370 600 00000']],
        'footer_description' => [['_type' => 'translatable_footer_description', 'footer_description_en' => 'Footer text EN', 'footer_description_de' => 'Footer text DE']],
        'site_branding_statement' => 'Site statement',
        'global_branding_statement' => 'Global statement',
        'posts_page_title' => 'Render posts title',
        'archive_page_title' => 'Render archive title',
    ];
    $sets = [
        1 => [
            'theme_color' => 'denim',
            'grid_lines' => 'true',
            'square_buttons' => 'true',
            'logo_recolor' => 'true',
            'posts_label' => 'true',
            'posts_grid' => 'true',
            'posts_grid_3up' => 'true',
            'posts_image' => 'true',
            'sabbath_scroll' => 'true',
            'sabbath_hide' => '',
            'dark_theme' => '',
            'is_wide_logo' => '',
            'index_hide_sidebar' => '',
            'footer_logo_type' => 'square',
        ],
        2 => [
            'theme_color' => 'not-a-colour',
            'dark_theme' => 'true',
            'is_wide_logo' => 'true',
            'logo_recolor' => '',
            'posts_label' => '',
            'posts_grid' => '',
            'posts_image' => 'true',
            'posts_image_round' => 'true',
            'sabbath_hide' => 'true',
            'sabbath_hide_screens' => 'hide-sabbath--until-medium',
            'index_hide_sidebar' => 'true',
            'archive_hide_sidebar' => 'true',
            'is_related_stories_image_hidden' => 'true',
            'footer_logo_type' => 'circle',
            'footer_address' => [],
            'footer_description' => [],
        ],
    ];
    foreach (array_merge($common, $sets[$set]) as $name => $value) {
        // Same rows either way; Carbon Fields no longer defines theme options.
        if (method_exists('App\Fields\Store', 'setOption')) {
            \App\Fields\Store::setOption($name, $value);
        } else {
            carbon_set_theme_option($name, $value);
        }
    }
}

if (! empty($alps_render_seed)) {
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $images = [];
    foreach ([[200, 40, 40], [40, 160, 60], [40, 60, 200]] as $i => $rgb) {
        $file = wp_upload_dir()['basedir'] . "/render-$i.jpg";
        wp_mkdir_p(dirname($file));
        $gd = imagecreatetruecolor(1200, 675);
        imagefill($gd, 0, 0, imagecolorallocate($gd, $rgb[0], $rgb[1], $rgb[2]));
        imagejpeg($gd, $file, 80);
        // No sub-sizes: the theme converts uploads to AVIF, which Playground's GD cannot encode.
        $id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => "Render $i", 'post_status' => 'inherit'], $file);
        wp_update_attachment_metadata($id, ['width' => 1200, 'height' => 675, 'file' => basename($file), 'sizes' => []]);
        update_post_meta($id, '_wp_attachment_image_alt', "Alt $i");
        $images[] = $id;
    }
    update_option('alps_render_images', $images);

    $date = strtotime('2026-01-05 10:00:00');
    $make = function ($type, $slug, $title, $fields = [], $extra = []) use (&$date) {
        $date += 3600;
        $id = wp_insert_post($extra + [
            'post_type' => $type,
            'post_status' => 'publish',
            'post_name' => $slug,
            'post_title' => $title,
            'post_date' => gmdate('Y-m-d H:i:s', $date),
            'post_excerpt' => "Excerpt of $title",
            'post_content' => "<!-- wp:paragraph --><p>Body of $title.</p><!-- /wp:paragraph -->",
        ]);
        foreach ($fields as $name => $value) {
            // Same rows either way; the theme no longer loads Carbon Fields.
            if (method_exists('App\Fields\Store', 'setPost')) {
                \App\Fields\Store::setPost($id, $name, $value);
            } else {
                carbon_set_post_meta($id, $name, $value);
            }
        }
        return $id;
    };

    $category = wp_insert_term('Render news', 'category')['term_id'];
    $slides = function ($n) use ($images) {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['slide_image' => $images[$i % 3], 'slide_heading' => "Slide $i", 'slide_dek' => "Dek $i", 'slide_url' => "https://example.org/slide-$i", 'slide_cta' => "Go $i"];
        }
        return $rows;
    };
    $columns = function ($n) use ($images) {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['hero_image_column' => $images[$i % 3], 'hero_title_column' => "Column $i", 'hero_kicker_column' => "Kicker $i", 'hero_link_url' => "https://example.org/col-$i"];
        }
        return $rows;
    };

    $pages = [];
    $pages['plain'] = $make('page', 'plain', 'Plain page');
    $pages['hero-default'] = $make('page', 'hero-default', 'Hero default', ['hero_type' => 'default', 'hero_image' => $images[0], 'hero_title' => "Hero title\nsecond line", 'hero_kicker' => 'Hero kicker', 'hero_link_url' => 'https://example.org/hero', 'hero_link_button' => 'Read', 'hero_overlay' => 'off', 'hero_scroll_hint' => 'true', 'hero_image_extended' => 'true']);
    $pages['hero-full'] = $make('page', 'hero-full', 'Hero full', ['hero_type' => 'full', 'hero_image' => $images[1], 'hero_title' => 'Full title', 'hero_scroll_hint' => 'true']);
    $pages['hero-full-overlay'] = $make('page', 'hero-full-overlay', 'Hero full overlay', ['hero_type' => 'full_overlay', 'hero_image' => $images[2], 'hero_title' => 'Overlay title', 'hero_kicker' => 'Overlay kicker']);
    $pages['hero-column'] = $make('page', 'hero-column', 'Hero columns', ['hero_type' => 'column', 'hero_column' => $columns(3)]);
    $pages['hero-column-one'] = $make('page', 'hero-column-one', 'Hero one column', ['hero_type' => 'column', 'hero_column' => $columns(1)]);
    $pages['hero-carousel'] = $make('page', 'hero-carousel', 'Hero carousel', ['hero_type' => 'carousel', 'hero_carousel' => $slides(3), 'hero_scroll_hint' => 'true']);
    $pages['hero-carousel-one'] = $make('page', 'hero-carousel-one', 'Hero carousel one', ['hero_type' => 'carousel_without_text_overlay', 'hero_carousel' => $slides(1)]);
    $pages['header-options'] = $make('page', 'header-options', 'Header options', ['head_type' => 'overlay_gradient', 'featured_image_hero_layout' => 'page-header', 'kicker' => 'Banner kicker', 'display_title' => 'Display title', 'long_header_subtitle' => 'Long subtitle', 'page_sub_title' => 'Sub title', 'header_background_image' => $images[0], 'remove_spacing' => 'true']);
    set_post_thumbnail($pages['header-options'], $images[1]);
    $pages['header-overlay'] = $make('page', 'header-overlay', 'Header overlay', ['head_type' => 'overlay', 'featured_image_hero_layout' => 'header-block-featured']);
    set_post_thumbnail($pages['header-overlay'], $images[2]);
    $pages['remove-header'] = $make('page', 'remove-header', 'Removed header', ['remove_header' => 'true', 'head_type' => 'remove', 'hide_sidebar' => 'true', 'hide_featured_image' => 'true']);
    $pages['related-all'] = $make('page', 'related-all', 'Related all', ['related' => 'related_all', 'related_grid' => 'true', 'related_image' => 'true', 'related_image_crop' => 'landscape']);
    $make('page', 'child-a', 'Child A', [], ['post_parent' => $pages['related-all']]);
    $make('page', 'child-b', 'Child B', [], ['post_parent' => $pages['related-all']]);
    $pages['related-top'] = $make('page', 'related-top', 'Related top level', ['related' => 'related_top_level']);
    $pages['related-custom'] = $make('page', 'related-custom', 'Related custom', ['related' => 'related_custom', 'related_custom_value' => [['type' => 'post', 'subtype' => 'page', 'id' => $pages['plain']], ['type' => 'post', 'subtype' => 'page', 'id' => $pages['hero-full']]], 'related_grid' => 'true', 'related_grid_3up' => 'true', 'related_image' => 'true', 'related_image_crop' => 'circle']);
    $pages['related-custom-one'] = $make('page', 'related-custom-one', 'Related custom one', ['related' => 'related_custom', 'related_custom_value' => [['type' => 'post', 'subtype' => 'page', 'id' => $pages['plain']]]]);

    $posts = [];
    $posts['plain'] = $make('post', 'post-plain', 'Plain post', [], ['post_category' => [$category]]);
    set_post_thumbnail($posts['plain'], $images[0]);
    $posts['options'] = $make('post', 'post-options', 'Post with options', ['kicker' => 'Post kicker', 'long_header_subtitle' => 'Post subtitle', 'hide_sidebar' => 'true', 'hide_featured_image' => 'true'], ['post_category' => [$category]]);
    set_post_thumbnail($posts['options'], $images[1]);
    $posts['hero'] = $make('post', 'post-hero', 'Post with hero', ['hero_type' => 'carousel', 'hero_carousel' => $slides(2), 'featured_image_hero_layout' => 'header-block-featured'], ['post_category' => [$category]]);

    // The theme's widgets in both sidebars, saved the way Carbon Fields saved
    // them. Related Stories reads an option; each template shows once a page.
    update_option('widget_carbon_fields_alps_widget_related_storied', [2 => ['_related_stories_title' => 'Related stories'], '_multiwidget' => 1]);
    update_option('widget_carbon_fields_alps_widget_text_with_link', [2 => ['_text_link_title' => 'Widget title', '_text_link_content' => 'Widget text', '_text_link_url' => 'https://example.org/widget', '_text_link_url_text' => 'Widget link'], '_multiwidget' => 1]);
    update_option('widget_carbon_fields_alps_widget_post_feed', [2 => ['_post_feed_category' => $category, '_post_feed_title' => 'Widget feed', '_post_feed_url' => '', '_post_feed_count' => '2', '_post_feed_offset' => '', '_post_feed_featured' => 'true', '_post_feed_layout' => ''], '_multiwidget' => 1]);
    update_option('widget_carbon_fields_alps_widget_author', [2 => ['_text_link_author' => 'Written by'], '_multiwidget' => 1]);
    $sidebars = get_option('sidebars_widgets', []);
    $sidebars['sidebar-posts'] = ['carbon_fields_alps_widget_related_storied-2', 'carbon_fields_alps_widget_author-2', 'carbon_fields_alps_widget_text_with_link-2'];
    $sidebars['sidebar-page'] = ['carbon_fields_alps_widget_related_storied-2', 'carbon_fields_alps_widget_text_with_link-2', 'carbon_fields_alps_widget_post_feed-2'];
    update_option('sidebars_widgets', $sidebars);

    update_option('permalink_structure', '');
    alps_render_options(1);

    $urls = ['/', '/?s=Render', '/?s=Hero&post_type=page', '/?cat=' . $category, '/?m=2026', '/?p=999999'];
    foreach ($pages as $id) {
        $urls[] = '/?page_id=' . $id;
    }
    foreach ($posts as $id) {
        $urls[] = '/?p=' . $id;
    }
    file_put_contents(__DIR__ . '/fixtures/render-urls.json', json_encode($urls));
}
