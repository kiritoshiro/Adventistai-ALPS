<?php

namespace App\Fields;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The post and page fields in the block editor: sidebar panels
 * (resources/scripts/field-panels.js) in place of the Carbon Fields boxes,
 * with the same fields, labels, help texts and conditions.
 *
 * The panels edit one REST field, `alps_fields` (edit context only), on every
 * post type that uses the block editor. It returns the values as Store reads
 * them and saves changed fields through Store::setPost(), which writes the
 * rows carbon_set_post_meta() wrote, so nothing is migrated and older theme
 * versions read the same values.
 *
 * Carbon Fields saved every field shown on the edit screen, including two
 * defaults that depend on the edit screen ("Display larger banner" is the
 * taller banner for a new page and the fitted one for other new entries;
 * "Related Page Image Cropping" follows the old grid/round settings).
 * The panels show those defaults, and saving a post writes them when nothing
 * is stored yet, as before.
 *
 * Post types edited on a classic screen (attachments, any type without the
 * block editor) have no fields to edit any more; values stored there are
 * still read.
 */
class PostPanels
{
    const REST_FIELD = 'alps_fields';
    const PAGE_ONLY = ['related', 'related_custom_value', 'related_grid', 'related_grid_3up', 'related_image', 'related_image_crop'];
    const ROWS = ['hero_column' => [3, 3], 'hero_carousel' => [1, 9]];

    /** Text fields printed as link addresses: only allowed protocols (no javascript:). */
    const URL_FIELDS = ['hero_link_url', 'slide_url'];

    /** Status of each post before the current REST update, by ID. */
    private static $statusBefore = [];

    public static function register()
    {
        add_action('rest_api_init', [__CLASS__, 'registerRestField']);
        add_action('enqueue_block_editor_assets', [__CLASS__, 'config'], 5);
    }

    /** Post types that show the fields: those with an edit screen, except attachments and WordPress's own. */
    public static function postTypes()
    {
        $types = [];
        foreach (get_post_types(['show_ui' => true]) as $type) {
            if ($type !== 'attachment' && strpos($type, 'wp_') !== 0) {
                $types[] = $type;
            }
        }
        return $types;
    }

    /** Of those, the ones edited in the block editor. */
    public static function blockEditorTypes()
    {
        if (! function_exists('use_block_editor_for_post_type')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }
        return array_values(array_filter(self::postTypes(), 'use_block_editor_for_post_type'));
    }

    /** Whether a field belongs on a post type (Related Pages & Posts is for pages). */
    public static function appliesTo($name, $type)
    {
        return ! in_array($name, self::PAGE_ONLY, true) || $type === 'page';
    }

    public static function registerRestField()
    {
        foreach (self::blockEditorTypes() as $type) {
            $object = get_post_type_object($type);
            if (! $object || empty($object->show_in_rest)) {
                continue;
            }
            register_rest_field($type, self::REST_FIELD, [
                'get_callback' => [__CLASS__, 'restGet'],
                'update_callback' => [__CLASS__, 'restUpdate'],
                'schema' => [
                    'description' => __('ALPS theme fields of this entry.', 'alps'),
                    'type' => 'object',
                    'context' => ['edit'],
                    'additionalProperties' => true,
                ],
            ]);
            add_filter("rest_pre_insert_{$type}", [__CLASS__, 'rememberStatus'], 10, 2);
            add_action("rest_after_insert_{$type}", [__CLASS__, 'saveEditDefaults'], 10, 2);
        }
    }

    /** The values the panels edit. */
    public static function restGet($post)
    {
        $post = get_post(is_array($post) ? $post['id'] : $post);
        return $post ? (object) self::values($post, true) : null;
    }

    /**
     * Field values in the panels' shape: checkboxes true/false, radios and
     * selects their key, images the attachment ID as a string, rows without
     * `_type`, associations [{type, subtype, id}]. With $edit, the two
     * edit-screen defaults replace the stored-nothing defaults.
     */
    public static function values(\WP_Post $post, $edit = false)
    {
        $values = [];
        foreach (Registry::post() as $name => $field) {
            if (! self::appliesTo($name, $post->post_type)) {
                continue;
            }
            $value = Store::post($post->ID, $name);
            if ($edit && ! self::stored($post->ID, $name)) {
                $default = self::editDefault($name, $post);
                if ($default !== null) {
                    $value = self::format($field, $default);
                }
            }
            $values[$name] = self::shape($field, $value);
        }
        return $values;
    }

    /**
     * The default the edit screen showed for the two request-dependent fields
     * (null for the others), as setDefaultHeader()/setDefaultRelatedImageCrop()
     * worked it out on post-new.php and post.php?post=<id>.
     */
    public static function editDefault($name, \WP_Post $post, $is_new = null)
    {
        $is_new = $is_new === null ? $post->post_status === 'auto-draft' : $is_new;
        if ($name === 'featured_image_hero_layout') {
            if ($is_new) {
                return $post->post_type === 'page' ? 'page-header' : 'header-block-featured';
            }
            // setDefaultHeader() also checked for an old "hero_layout_1_3/3_3"
            // value, but a default only applies when nothing is stored.
            return false;
        }
        if ($name === 'related_image_crop') {
            $default = 'square';
            if (! empty(get_post_meta($post->ID, '_related_image_round', true))) {
                $default = 'circle';
            }
            if (! empty(get_post_meta($post->ID, '_related_grid', true))) {
                $default = 'landscape';
            }
            return $default;
        }
        return null;
    }

    /** Save the fields the request changed. */
    public static function restUpdate($value, $post)
    {
        if (! is_array($value) && ! is_object($value)) {
            return true;
        }
        $post = get_post($post instanceof \WP_Post ? $post->ID : $post);
        if (! $post || ! current_user_can('edit_post', $post->ID)) {
            return new \WP_Error('rest_cannot_edit', __('Sorry, you are not allowed to edit this post.', 'alps'), ['status' => rest_authorization_required_code()]);
        }
        $current = self::values($post);
        foreach ((array) $value as $name => $submitted) {
            $field = Registry::field('post', $name);
            if (! $field || ! self::appliesTo($name, $post->post_type)) {
                continue;
            }
            $clean = self::sanitize($name, $field, $submitted);
            if ($clean !== $current[$name]) {
                Store::setPost($post->ID, $name, self::storable($field, $clean));
            }
        }
        return true;
    }

    public static function rememberStatus($prepared, $request)
    {
        $id = isset($request['id']) ? (int) $request['id'] : 0;
        $post = $id ? get_post($id) : null;
        if ($post) {
            self::$statusBefore[$id] = $post->post_status;
        }
        return $prepared;
    }

    /**
     * After a post is saved without panel changes to them, store the two
     * edit-screen defaults if nothing is stored yet and they differ from what
     * the front end would read (what saving the Carbon Fields boxes did).
     */
    public static function saveEditDefaults($post, $request)
    {
        if (! $post instanceof \WP_Post || $post->post_status === 'auto-draft') {
            return;
        }
        $submitted = isset($request[self::REST_FIELD]) ? (array) $request[self::REST_FIELD] : [];
        $is_new = isset(self::$statusBefore[$post->ID]) ? self::$statusBefore[$post->ID] === 'auto-draft' : false;
        foreach (['featured_image_hero_layout', 'related_image_crop'] as $name) {
            if (array_key_exists($name, $submitted) || ! self::appliesTo($name, $post->post_type) || self::stored($post->ID, $name)) {
                continue;
            }
            $field = Registry::field('post', $name);
            $default = self::format($field, self::editDefault($name, $post, $is_new));
            if ($default !== Store::post($post->ID, $name)) {
                Store::setPost($post->ID, $name, $default);
            }
        }
    }

    /** Whether any row of a field is stored for a post. */
    private static function stored($id, $name)
    {
        foreach (array_keys((array) get_post_meta($id)) as $key) {
            if ($key === '_' . $name || strpos($key, '_' . $name . '|') === 0) {
                return true;
            }
        }
        return false;
    }

    /** A raw default read the way Store reads a radio (unknown → first choice). */
    private static function format(array $field, $value)
    {
        if (in_array($field['type'], ['radio', 'select'], true)) {
            $index = array_search(strval($value), $field['options'], true);
            return $index !== false ? $field['options'][$index] : $field['options'][0];
        }
        return $value;
    }

    /** A Store value in the panels' shape. */
    private static function shape(array $field, $value)
    {
        switch ($field['type']) {
            case 'checkbox':
                return (bool) $value;
            case 'image':
                return $value ? (string) $value : '';
            case 'complex':
                $rows = [];
                foreach (is_array($value) ? $value : [] as $row) {
                    unset($row['_type']);
                    foreach ($field['groups']['_'] as $sub => $sub_field) {
                        $row[$sub] = self::shape($sub_field, isset($row[$sub]) ? $row[$sub] : '');
                    }
                    $rows[] = $row;
                }
                return $rows;
            case 'association':
                $entries = [];
                foreach (is_array($value) ? $value : [] as $entry) {
                    $entries[] = ['type' => (string) $entry['type'], 'subtype' => (string) $entry['subtype'], 'id' => (int) $entry['id']];
                }
                return $entries;
            default:
                return is_scalar($value) ? (string) $value : '';
        }
    }

    /** A submitted value, cleaned, in the panels' shape. */
    public static function sanitize($name, array $field, $value)
    {
        switch ($field['type']) {
            case 'checkbox':
                return $value === true || $value === $field['value'] || $value === 'true' || $value === 1 || $value === '1';
            case 'radio':
            case 'select':
                return is_string($value) && in_array($value, $field['options'], true) ? $value : $field['options'][0];
            case 'image':
                return is_scalar($value) && absint($value) ? (string) absint($value) : '';
            case 'complex':
                list($min, $max) = isset(self::ROWS[$name]) ? self::ROWS[$name] : [0, 100];
                $rows = [];
                foreach (is_array($value) ? array_values($value) : [] as $row) {
                    if (count($rows) >= $max) {
                        break;
                    }
                    $row = is_array($row) ? $row : (array) $row;
                    $clean = [];
                    foreach ($field['groups']['_'] as $sub => $sub_field) {
                        $clean[$sub] = self::sanitize($sub, $sub_field, isset($row[$sub]) ? $row[$sub] : '');
                    }
                    $rows[] = $clean;
                }
                return $rows;
            case 'association':
                $entries = [];
                $allowed = [];
                foreach ($field['types'] as $type) {
                    $allowed[] = $type['post_type'];
                }
                foreach (is_array($value) ? $value : [] as $entry) {
                    $entry = (array) $entry;
                    $id = isset($entry['id']) ? absint($entry['id']) : 0;
                    $subtype = $id ? get_post_type($id) : false;
                    if ($id && in_array($subtype, $allowed, true)) {
                        $entries[] = ['type' => 'post', 'subtype' => $subtype, 'id' => $id];
                    }
                }
                return $entries;
            default:
                $value = is_scalar($value) ? (string) $value : '';
                $value = current_user_can('unfiltered_html') ? $value : wp_kses_post($value);
                if (in_array($name, self::URL_FIELDS, true) && wp_kses_bad_protocol($value, wp_allowed_protocols()) !== $value) {
                    return '';
                }
                return $value;
        }
    }

    /** A panel value in the form Store::setPost() takes. */
    private static function storable(array $field, $value)
    {
        if ($field['type'] === 'complex') {
            return array_map(function ($row) {
                return ['_type' => '_'] + $row;
            }, $value);
        }
        return $value;
    }

    /** Panels, fields, labels and conditions for field-panels.js. */
    public static function layout()
    {
        $hero_simple = ['default', 'full', 'full_overlay'];
        return [
            [
                'name' => 'alps-header-options',
                'title' => __('ALPS: Header and Page Builder Options', 'alps'),
                'help' => __('Use these settings to alter header display and change content spacing if you intend to use a page builder.', 'alps'),
                'fields' => [
                    ['name' => 'remove_header', 'label' => __('Remove Page Header', 'alps'), 'help' => __('Hides the page header for this entry.', 'alps')],
                    ['name' => 'featured_image_hero_layout', 'label' => __('Display larger banner', 'alps'), 'help' => __('Show the whole featured or custom image with the title on a blurred copy of the image. Choose the taller banner for more image height.', 'alps'), 'choices' => [
                        'false' => __('Do not show larger banner.', 'alps'),
                        'header-block-featured' => __('Show fitted image with a blurred title background.', 'alps'),
                        'page-header' => __('Show taller fitted image with a blurred title background.', 'alps'),
                    ]],
                    ['name' => 'remove_spacing', 'label' => __('Remove Default Spacing', 'alps'), 'help' => __('Removes classes that create default margins and padding for content area.', 'alps')],
                    ['name' => 'head_type', 'label' => __('Template Head Type', 'alps'), 'help' => __('Select the template header option.', 'alps'), 'choices' => [
                        'default' => __('Default', 'alps'),
                        'remove' => __('Remove Head', 'alps'),
                        'overlay_gradient' => __('Overlay Head With Background Gradient', 'alps'),
                        'overlay' => __('Overlay Head Without Gradient', 'alps'),
                    ]],
                ],
            ],
            [
                'name' => 'alps-header-banner',
                'title' => __('ALPS: Header Banner', 'alps'),
                'help' => wp_strip_all_tags(__('IMPORTANT: Setting an image and title below will override the post title and feature image. <br><span style="color: red;">Banner image will look best if you crop the source image to an aspect ratio of 4:3 before uploading it.</span>', 'alps')),
                'fields' => [
                    ['name' => 'kicker', 'label' => __('Header Kicker', 'alps')],
                    ['name' => 'display_title', 'label' => __('Header Title', 'alps')],
                    ['name' => 'long_header_subtitle', 'label' => __('Header Subtitle', 'alps')],
                    ['name' => 'page_sub_title', 'label' => __('Page Subtitle', 'alps')],
                    ['name' => 'header_background_image', 'label' => __('Custom Header Image (will override feature image)', 'alps')],
                ],
            ],
            [
                'name' => 'alps-hero',
                'title' => __('ALPS: Hero', 'alps'),
                'help' => __('IMPORTANT: Setting a hero style below will completely override anything you have entered for your ALPS Header Banner or your Featured Image.', 'alps'),
                'fields' => [
                    ['name' => 'hero_type', 'label' => __('Hero Style', 'alps'), 'help' => __('Select the hero configuration.', 'alps'), 'choices' => [
                        'false' => __('None', 'alps'),
                        'default' => __('Half screen image with text overlay (Min/Max Images: 1)', 'alps'),
                        'full' => __('Full screen image with gradient (Min/Max Images: 1)', 'alps'),
                        'full_without_gradient' => __('Full screen image without gradient (Min/Max Images: 1)', 'alps'),
                        'full_overlay' => __('Full screen image with text and header overlay (Min/Max Images: 1)', 'alps'),
                        'column' => __('Three column image format with text overlays (Min/Max Images: 3)', 'alps'),
                        'carousel' => __('Half screen image gallery with text overlay (Max Images: 9)', 'alps'),
                        'carousel_without_text_overlay' => __('Half screen image gallery without text overlay (Max Images: 9)', 'alps'),
                    ]],
                    ['name' => 'hero_overlay', 'label' => __('Hero Overlay', 'alps'), 'help' => __('Control the dark fade overlay on hero images.', 'alps'), 'choices' => [
                        'on' => __('Hero fade overlay On', 'alps'),
                        'off' => __('Hero fade overlay Off', 'alps'),
                    ]],
                    ['name' => 'hero_image', 'label' => __('Hero Image', 'alps'), 'showIf' => ['hero_type', ['default', 'full', 'full_without_gradient', 'full_overlay']]],
                    ['name' => 'hero_title', 'label' => __('Hero Title', 'alps'), 'help' => __('The title of the hero image.', 'alps'), 'showIf' => ['hero_type', $hero_simple]],
                    ['name' => 'hero_kicker', 'label' => __('Hero Kicker', 'alps'), 'help' => __('Displays below the title in the hero image.', 'alps'), 'showIf' => ['hero_type', $hero_simple]],
                    ['name' => 'hero_link_url', 'label' => __('Hero Link URL', 'alps'), 'help' => __('Enter an URL to link the hero image.', 'alps'), 'showIf' => ['hero_type', $hero_simple]],
                    ['name' => 'hero_link_button', 'label' => __('Hero Link Button', 'alps'), 'help' => __('Enter text here to show on the hero button. Filling this out sets the link on the button and not the hero image.', 'alps'), 'showIf' => ['hero_type', ['full', 'full_overlay']]],
                    ['name' => 'hero_image_extended', 'label' => __('Hero Image Extended', 'alps'), 'help' => __('Check to extend the hero image over the sabbath column.', 'alps'), 'showIf' => ['hero_type', ['default', 'full']]],
                    ['name' => 'hero_scroll_hint', 'label' => __('Hero Scroll Hint', 'alps'), 'help' => __('Check to extend the hero image over the sabbath column.', 'alps'), 'showIf' => ['hero_type', ['full']]],
                    ['name' => 'hero_column', 'label' => __('Hero Column (3 Columns)', 'alps'), 'showIf' => ['hero_type', ['column']], 'rows' => self::ROWS['hero_column'], 'rowLabel' => __('Column', 'alps'), 'subs' => [
                        ['name' => 'hero_image_column', 'label' => __('Hero Image', 'alps')],
                        ['name' => 'hero_title_column', 'label' => __('Hero Title', 'alps'), 'help' => __('The title of the hero image.', 'alps')],
                        ['name' => 'hero_kicker_column', 'label' => __('Hero Kicker', 'alps'), 'help' => __('Displays below the title in the hero image.', 'alps')],
                        ['name' => 'hero_link_url', 'label' => __('Hero Link URL', 'alps'), 'help' => __('Enter an URL to link the hero image.', 'alps')],
                    ]],
                    ['name' => 'hero_carousel', 'label' => __('Hero Carousel', 'alps'), 'showIf' => ['hero_type', ['carousel', 'carousel_without_text_overlay']], 'rows' => self::ROWS['hero_carousel'], 'rowLabel' => __('Slide', 'alps'), 'subs' => [
                        ['name' => 'slide_image', 'label' => __('Hero Image', 'alps')],
                        ['name' => 'slide_heading', 'label' => __('Hero Title', 'alps'), 'help' => __('The title of the hero image slide.', 'alps')],
                        ['name' => 'slide_dek', 'label' => __('Hero Description', 'alps'), 'help' => __('Displays below the sub title in the hero image slide.', 'alps')],
                        ['name' => 'slide_url', 'label' => __('Hero Link URL', 'alps'), 'help' => __('Enter an URL to link the hero image slide.', 'alps')],
                        ['name' => 'slide_cta', 'label' => __('Hero Link CTA', 'alps'), 'help' => __('Displays title in the hero link.', 'alps')],
                    ]],
                ],
            ],
            [
                'name' => 'alps-entry-sidebar',
                'title' => __('ALPS: Entry Sidebar / Featured Image', 'alps'),
                'fields' => [
                    ['name' => 'hide_sidebar', 'label' => __('Hide Sidebar', 'alps'), 'help' => __('Hides the sidebar for this entry if it is active.', 'alps')],
                    ['name' => 'hide_featured_image', 'label' => __('Hide Featured Image', 'alps'), 'help' => __('Hides the featured image on the page/post header for this entry.', 'alps')],
                ],
            ],
            [
                'name' => 'alps-related',
                'title' => __('ALPS: Related Pages & Posts', 'alps'),
                'postTypes' => ['page'],
                'fields' => [
                    ['name' => 'related', 'label' => __('Related Pages Format', 'alps'), 'help' => __('Select the format of the related pages.', 'alps'), 'choices' => [
                        'false' => __('None', 'alps'),
                        'related_top_level' => __('Show first level child pages only', 'alps'),
                        'related_all' => __('Show child and grandchild pages', 'alps'),
                        'related_custom' => __('Show custom pages', 'alps'),
                    ]],
                    ['name' => 'related_custom_value', 'label' => __('Assign Pages', 'alps'), 'showIf' => ['related', ['related_custom']], 'postTypes' => ['page', 'post']],
                    ['name' => 'related_grid', 'label' => __('Related Pages Grid', 'alps'), 'help' => __('Select to display the related pages side-by-side.', 'alps')],
                    ['name' => 'related_grid_3up', 'label' => __('Related Pages Grid (3up)', 'alps'), 'help' => __('Select to display the related pages in 3 columns on large screens. This will only display if the Sabbath column is hidden.', 'alps'), 'showIf' => ['related_grid', [true]]],
                    ['name' => 'related_image', 'label' => __('Related Pages Image', 'alps'), 'help' => __('Select to display the feature image for the related pages.', 'alps')],
                    ['name' => 'related_image_crop', 'label' => __('Related Page Image Cropping', 'alps'), 'showIf' => ['related_image', [true]], 'choices' => [
                        'square' => __('Square', 'alps'),
                        'landscape' => __('Landscape', 'alps'),
                        'portrait' => __('Portrait', 'alps'),
                        'circle' => __('Circle', 'alps'),
                    ]],
                ],
            ],
        ];
    }

    /** Hands the layout and field types to field-panels.js on block-editor screens of these post types. */
    public static function config()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || empty($screen->post_type) || ! in_array($screen->post_type, self::blockEditorTypes(), true)) {
            return;
        }
        $types = [];
        foreach (Registry::post() as $name => $field) {
            $types[$name] = $field['type'];
            if (isset($field['groups']['_'])) {
                foreach ($field['groups']['_'] as $sub => $sub_field) {
                    $types[$name . '.' . $sub] = $sub_field['type'];
                }
            }
        }
        wp_register_script('alps-field-panels-config', false, [], null, false);
        wp_enqueue_script('alps-field-panels-config');
        wp_add_inline_script('alps-field-panels-config', 'window.alpsFieldPanels = ' . wp_json_encode([
            'restField' => self::REST_FIELD,
            'panels' => self::layout(),
            'types' => $types,
        ]) . ';', 'before');
    }
}
