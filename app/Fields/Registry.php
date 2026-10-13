<?php

namespace App\Fields;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The theme's fields: every post field a template reads and every theme
 * option, with what is needed to read them back exactly as Carbon Fields
 * 3.6.11 does (type, default, choices, checkbox value, complex groups).
 *
 * The list mirrors the Carbon Fields definitions the theme used until 3.36
 * (kept for the tests in tests/fields/legacy/); tests/fields/compare.php checks
 * it against them. The six edit-screen boxes tied to templates that no longer exist
 * (Home row/story blocks, Hero Featured Post, Post Feed …) are left out: no
 * template reads them, and an unknown field reads as '' as in Carbon Fields.
 *
 * Like Carbon Fields, the list is built once per request, so request-dependent
 * defaults (translations, the two default*() below) are those of the moment of
 * first use.
 *
 * Field entries: type (text, textarea, rich_text, color, image, checkbox,
 * radio, select, complex, association) plus, where they apply, default,
 * options (choice keys), value (the stored value of a ticked checkbox) and
 * groups (complex: group name => sub-fields; '_' is the unnamed group) and
 * types (association: the object types it links to).
 */
class Registry
{
    private static $post;
    private static $options;

    /** Fields of the post edit-screen boxes, by name. */
    public static function post()
    {
        if (self::$post === null) {
            $check = ['type' => 'checkbox', 'value' => 'true', 'default' => false];
            self::$post = [
                // Header and Page Builder Options
                'remove_header' => $check,
                'featured_image_hero_layout' => ['type' => 'radio', 'options' => ['false', 'header-block-featured', 'page-header'], 'default' => self::defaultHeroLayout()],
                'remove_spacing' => $check,
                'head_type' => ['type' => 'select', 'options' => ['default', 'remove', 'overlay_gradient', 'overlay']],
                // Header Banner
                'kicker' => ['type' => 'text'],
                'display_title' => ['type' => 'text'],
                'long_header_subtitle' => ['type' => 'text'],
                'page_sub_title' => ['type' => 'text'],
                'header_background_image' => ['type' => 'image'],
                // Hero
                'hero_type' => ['type' => 'radio', 'options' => ['false', 'default', 'full', 'full_without_gradient', 'full_overlay', 'column', 'carousel', 'carousel_without_text_overlay']],
                'hero_overlay' => ['type' => 'radio', 'options' => ['on', 'off'], 'default' => 'on'],
                'hero_image' => ['type' => 'image'],
                'hero_title' => ['type' => 'textarea'],
                'hero_kicker' => ['type' => 'text'],
                'hero_link_url' => ['type' => 'text'],
                'hero_link_button' => ['type' => 'text'],
                'hero_image_extended' => $check,
                'hero_scroll_hint' => $check,
                'hero_column' => ['type' => 'complex', 'groups' => ['_' => [
                    'hero_image_column' => ['type' => 'image'],
                    'hero_title_column' => ['type' => 'textarea'],
                    'hero_kicker_column' => ['type' => 'text'],
                    'hero_link_url' => ['type' => 'text'],
                ]]],
                'hero_carousel' => ['type' => 'complex', 'groups' => ['_' => [
                    'slide_image' => ['type' => 'image'],
                    'slide_heading' => ['type' => 'text'],
                    'slide_dek' => ['type' => 'textarea'],
                    'slide_url' => ['type' => 'text'],
                    'slide_cta' => ['type' => 'text'],
                ]]],
                // Entry Sidebar / Featured Image
                'hide_sidebar' => $check,
                'hide_featured_image' => $check,
                // Related Pages & Posts (pages)
                'related' => ['type' => 'radio', 'options' => ['false', 'related_top_level', 'related_all', 'related_custom']],
                'related_custom_value' => ['type' => 'association', 'types' => [['type' => 'post', 'post_type' => 'page'], ['type' => 'post', 'post_type' => 'post']]],
                'related_grid' => $check,
                'related_grid_3up' => $check,
                'related_image' => $check,
                'related_image_crop' => ['type' => 'radio', 'options' => ['square', 'landscape', 'portrait', 'circle'], 'default' => self::defaultRelatedImageCrop()],
            ];
        }
        return self::$post;
    }

    /** Fields of Appearance → ALPS Theme Settings, by name. */
    public static function options()
    {
        if (self::$options === null) {
            self::$options = self::buildOptions();
        }
        return self::$options;
    }

    /** One field definition, or null when the theme has no such field. */
    public static function field($scope, $name)
    {
        $fields = $scope === 'option' ? self::options() : self::post();
        return isset($fields[$name]) ? $fields[$name] : null;
    }

    /**
     * Default of "Display larger banner" (featured_image_hero_layout). It
     * depends on the request: posts that used an old hero layout, and new
     * posts and pages, get a banner.
     */
    public static function defaultHeroLayout()
    {
        $default = false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin context used to determine a field default.
        $id = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;
        if ($id) {
            $value = get_post_meta($id, '_featured_image_hero_layout', true);
            if ($value == 'hero_layout_1_3' || $value == 'hero_layout_3_3') {
                $default = 'header-block-featured';
            }
        } else {
            $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            if (strpos($uri, 'post-new.php')) {
                $default = 'header-block-featured';
            }
            if (strpos($uri, 'post_type=page')) {
                $default = 'page-header';
            }
        }
        return $default;
    }

    /**
     * Default of "Related Page Image Cropping": landscape for grids, circle
     * where the old round-image setting was on.
     */
    public static function defaultRelatedImageCrop()
    {
        $default = 'square';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin context used to determine a field default.
        $id = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;
        if ($id) {
            $grid = get_post_meta($id, '_related_grid', true);
            $oldCircle = get_post_meta($id, '_related_image_round', true);
            if (!empty($oldCircle)) {
                $default = 'circle';
            }
            if (!empty($grid)) {
                $default = 'landscape';
            }
        }
        return $default;
    }

    private static function buildOptions()
    {
        $check = ['type' => 'checkbox', 'value' => 'true', 'default' => false];
        $fields = [
            // GLOBAL
            'logo_recolor' => ['type' => 'checkbox', 'value' => 'true', 'default' => 'true'],
            'is_wide_logo' => $check,
        ];
        // One logo pair per WPML language, otherwise a single pair.
        $languages = apply_filters('wpml_active_languages', null);
        if (empty($languages)) {
            $fields['logo'] = ['type' => 'image'];
            $fields['logo_inverted'] = ['type' => 'image'];
        } else {
            foreach ($languages as $lang) {
                $fields['logo_' . $lang['code']] = ['type' => 'image'];
                $fields['logo_inverted_' . $lang['code']] = ['type' => 'image'];
            }
        }
        $fields += [
            'theme_color' => ['type' => 'select', 'options' => ['bluejay', 'campfire', 'cave', 'denim', 'earth', 'emperor', 'forest', 'grapevine', 'iris', 'lily', 'ming', 'night', 'scarlett', 'treefrog', 'velvet', 'winter', 'nad-amethyst', 'nad-branch', 'nad-denim', 'nad-miracle', 'nad-nile', 'nad-spark', 'nad-vine']],
            'dark_theme' => $check,
            'grid_lines' => $check,
            'square_buttons' => $check,
            'site_branding_statement' => ['type' => 'textarea', 'default' => __('An official website of the Seventh-day Adventist Church.', 'alps')],
            'global_branding_statement' => ['type' => 'textarea', 'default' => __('Seventh-day Adventists are devoted to helping people understand the Bible to find freedom, healing, and hope in Jesus.', 'alps')],
        ];
        if (\App\Core\ALPSLanguages::WPMLPluginIsActive()) {
            $fields['project_alps_languages_hide_selector'] = ['type' => 'checkbox', 'value' => 'false', 'default' => false];
        }
        $fields += [
            // POSTS OPTIONS
            'posts_page_title' => ['type' => 'text'],
            'index_hide_sidebar' => $check,
            'archive_page_title' => ['type' => 'text'],
            'is_related_stories_image_hidden' => $check,
            'archive_hide_sidebar' => $check,
            'posts_label' => $check,
            'posts_grid' => $check,
            'posts_grid_3up' => $check,
            'posts_image' => $check,
            'posts_image_round' => $check,
            // SABBATH COLUMN
            'sabbath_icon' => ['type' => 'image'],
            'sabbath_background' => ['type' => 'image'],
            'sabbath_scroll' => $check,
            'sabbath_hide' => $check,
            'sabbath_hide_screens' => ['type' => 'radio', 'options' => ['hide-sabbath--all', 'hide-sabbath--until-small', 'hide-sabbath--until-medium', 'hide-sabbath--until-large']],
            // FOOTER CONTENT
            'footer_description' => ['type' => 'complex', 'groups' => ['translatable_footer_description' => [
                'footer_description_en' => ['type' => 'text', 'default' => __('An official website of the Seventh-day Adventist Church.', 'alps')],
                'footer_description_de' => ['type' => 'text', 'default' => __('Eine offizielle Website der Kirche der Siebenten-Tags-Adventisten.', 'alps')],
                'footer_description_fr' => ['type' => 'text', 'default' => __('Un site officiel de l\'Église adventiste du Septième jour.', 'alps')],
                'footer_description_pt-pt' => ['type' => 'text', 'default' => __('Um site oficial da Igreja Adventista do Sétimo Dia.', 'alps')],
                'footer_description_es' => ['type' => 'text', 'default' => __('Sitio web oficial de la Iglesia Adventista del Séptimo Día.', 'alps')],
                'footer_description_ko' => ['type' => 'text', 'default' => __('제칠 일 안식일 예수 재림 교회의 공식 웹 사이트.', 'alps')],
                'footer_description_ru' => ['type' => 'text', 'default' => __('Официальный веб-сайт Церкви адвентистов седьмого дня.', 'alps')],
                'footer_description_zh-hant' => ['type' => 'text', 'default' => __('基督复临安息日会的官方网站。', 'alps')],
            ]]],
            'footer_copyright' => ['type' => 'text', 'default' => __('Seventh-day Adventist Church', 'alps')],
            'footer_address' => ['type' => 'complex', 'groups' => ['_' => [
                'footer_address_street' => ['type' => 'text'],
                'footer_address_city' => ['type' => 'text'],
                'footer_address_state' => ['type' => 'text'],
                'footer_address_zip' => ['type' => 'text'],
                'footer_address_country' => ['type' => 'text'],
                'footer_phone' => ['type' => 'text'],
            ]]],
            'footer_logo_icon' => ['type' => 'image'],
            'footer_logo_type' => ['type' => 'radio', 'options' => ['square', 'circle']],
            // IMAGES
            'alps_image_avif_quality' => ['type' => 'text'],
            'alps_image_full_max' => ['type' => 'text'],
            'alps_image_small_max' => ['type' => 'text'],
        ];
        return $fields;
    }
}
