<?php

namespace App\Fields;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Appearance → ALPS Theme Settings (themes.php?page=alps-theme-options), the
 * page Carbon Fields used to provide, with the same tabs, fields, labels and
 * stored options. Values are read through Store::option() and saved through
 * Store::setOption(), which writes the rows Carbon Fields wrote, so older theme
 * versions read the same settings.
 *
 * Without JavaScript all tabs show one below the other; theme-settings.js
 * turns them into tabs, opens the media library for image fields, adds and
 * removes footer description rows, and hides "Hide the sabbath column at
 * certain screen widths" while the column is shown.
 */
class SettingsPage
{
    const SLUG = 'alps-theme-options';
    const ACTION = 'alps_save_theme_settings';
    const CAPABILITY = 'manage_options';

    public static function register()
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'save']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function menu()
    {
        add_theme_page(__('ALPS Theme Settings', 'alps'), __('ALPS Theme Settings', 'alps'), self::CAPABILITY, self::SLUG, [__CLASS__, 'render']);
    }

    public static function url($args = [])
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('themes.php'));
    }

    /** Tabs => items: ['heading', title, help], ['html', html], ['field', name, label, help, width, attributes]. */
    public static function layout()
    {
        $languages = apply_filters('wpml_active_languages', null);
        $logos = [];
        if (empty($languages)) {
            $logos[] = ['field', 'logo', __('Logo', 'alps'), '', 55];
            $logos[] = ['field', 'logo_inverted', __('Optional Inverted Logo', 'alps'), '', 55];
        } else {
            foreach ($languages as $lang) {
                // phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Same dynamic strings as the Carbon Fields page had.
                $logos[] = ['field', 'logo_' . $lang['code'], __('Logo (' . $lang['translated_name'] . ')', 'alps'), '', 25];
                $logos[] = ['field', 'logo_inverted_' . $lang['code'], __('Optional Inverted Logo (' . $lang['translated_name'] . ')', 'alps'), '', 25];
                // phpcs:enable
            }
        }
        $wpml = [];
        if (\App\Core\ALPSLanguages::WPMLPluginIsActive()) {
            $wpml[] = ['html', __('<h3>WPML Language Selector Settings</h3>', 'alps')];
            $wpml[] = ['field', 'project_alps_languages_hide_selector', __('Hide WPML languages', 'alps'), __('Hiding default WPML languages selector on page.', 'alps')];
        }

        return [
            'global' => [__('GLOBAL', 'alps'), array_merge([
                ['heading', __('Logo', 'alps'), __('IMPORTANT NOTE: By default the theme will recolor your logo based on the selected color palette. On standard headers the logo will be shown in the theme’s primary color. On overlay headers, the logo will be shown in white. If you wish to preserve the colors of your logo, deselect the checkbox below and, optionally, upload an inverted version of your logo for use on overlay headers.', 'alps')],
                ['field', 'logo_recolor', __('Allow theme to control logo color', 'alps')],
                ['field', 'is_wide_logo', __('Wide Logo', 'alps'), __('Select if you would like to use a wider than normal logo.', 'alps'), 100],
            ], $logos, [
                ['heading', __('Theme Color Options / Grid', 'alps')],
                ['field', 'theme_color', __('Theme Color', 'alps'), '', 33],
                ['field', 'dark_theme', __('Dark Theme', 'alps'), __('Select if you would like the theme to be dark.', 'alps'), 33],
                ['field', 'grid_lines', __('Grid Lines', 'alps'), __('Select if you would like show the grid lines.', 'alps'), 33],
                ['field', 'square_buttons', __('Square Buttons', 'alps'), __('Select if you would like square buttons', 'alps'), 33],
                ['html', __('<h3>Global Site Statements</h3><p style="font-size:16px">Both of these statements show up in the navigation drawer.</p>', 'alps')],
                ['field', 'site_branding_statement', __('Site Branding Statement', 'alps'), __('Found in the navigation drawer', 'alps'), 50],
                ['field', 'global_branding_statement', __('Global Branding Statement', 'alps'), __('Found in the navigation drawer', 'alps'), 50],
            ], $wpml)],
            'posts' => [__('POSTS OPTIONS', 'alps'), [
                ['heading', __('Home Page Content Display', 'alps')],
                ['field', 'posts_page_title', __('Home Page Title', 'alps'), __('Sets a custom home page title for sites displaying the latest posts.', 'alps'), 33],
                ['field', 'index_hide_sidebar', __('Hide The Sidebar', 'alps'), __('Hides the sidebar on the home/archive page if it is active.', 'alps'), 33],
                ['heading', __('Posts Content Display', 'alps')],
                ['field', 'archive_page_title', __('Archives Page Title', 'alps'), __('Sets a custom title for the archive page for sites with a custom posts page.', 'alps'), 100],
                ['field', 'is_related_stories_image_hidden', __('Hide The Related Stories Image', 'alps'), __('Hides the image in the Related Stories block in sidebar.', 'alps'), 33],
                ['field', 'archive_hide_sidebar', __('Hide The Sidebar', 'alps'), __('Hides the sidebar on the archive page if it is active.', 'alps'), 33],
                ['field', 'posts_label', __('Category Posts Feed Label', 'alps'), __('Select to display the label "Category" on category posts feed.', 'alps'), 33],
                ['heading', __('Posts Grid and Image Display', 'alps')],
                ['field', 'posts_grid', __('Posts Feed Grid', 'alps'), __('Select to display the posts side-by-side.', 'alps'), 50],
                ['field', 'posts_grid_3up', __('Posts Feed Grid (3up)', 'alps'), __('Select to display the posts 3up at the largest breakpoint. The sidebar must be hidden for the pages to display 3up.', 'alps'), 50],
                ['field', 'posts_image', __('Posts Feed Image', 'alps'), __('Select to display the feature image for the posts.', 'alps'), 50],
                ['field', 'posts_image_round', __('Posts Round Images', 'alps'), __('Make the post feed images round.', 'alps'), 50],
                ['heading', __('Sidebar', 'alps')],
                ['field', 'sidebar_position', __('Sidebar position', 'alps'), __('Where the widget sidebar sits beside posts, pages and archives on wide screens. Left balances the page against the Sabbath column on the right; Left side column puts it at the left edge of the window, below the header band, like the Sabbath column on the right; scaled, it is a seventh of the window wide like the Sabbath column and its widgets grow and shrink with it at any zoom. On phones the sidebar always comes after the content.', 'alps'), 50],
            ]],
            'sabbath' => [__('SABBATH COLUMN', 'alps'), [
                ['field', 'sabbath_icon', __('Sabbath Icon', 'alps'), '', 50],
                ['field', 'sabbath_background', __('Sabbath Background Image', 'alps'), '', 50],
                ['field', 'sabbath_scroll', __('Hide Sabbath Icon Until Scroll', 'alps'), __('Select to hide the sabbath icon till you scroll.', 'alps'), 33],
                ['field', 'sabbath_hide', __('Hide the sabbath column', 'alps'), __('Select to hide the sabbath column.', 'alps'), 33],
                ['field', 'sabbath_hide_screens', __('Hide the sabbath column at certain screen widths', 'alps'), '', 33, ['data-shown-with' => 'sabbath_hide']],
            ]],
            'sabbath_timer' => [__('SABBATH TIMER', 'alps'), [
                ['field', 'sabbath_timer_hide', __('Hide the Sabbath timer', 'alps'), __('Select to hide the Sabbath countdown and verse at the top of the front page.', 'alps'), 100],
                ['field', 'sabbath_timer_verses', __('Sabbath verses', 'alps'), __('Shown at the top of the front page during the Sabbath, one picked at random on each visit. Without entries the timer shows Exodus 20:8.', 'alps')],
            ]],
            'footer' => [__('FOOTER CONTENT', 'alps'), [
                ['field', 'footer_description', __('Footer Description', 'alps')],
                ['field', 'footer_copyright', __('Footer Copyright', 'alps')],
                ['field', 'footer_address', __('Footer Address', 'alps')],
                ['field', 'footer_logo_icon', __('Footer Logo Icon', 'alps'), __('Upload a logo icon for the footer. * Will only display if the sabbath column is hidden.', 'alps')],
                ['field', 'footer_logo_type', __('Fallback Footer Logo Icon', 'alps'), '', 33],
            ]],
            'images' => [__('IMAGES', 'alps'), [
                ['html', \App\UploadImages::settingsHtml()],
                ['field', 'alps_image_avif_quality', __('AVIF quality (20–95)', 'alps'), __('Empty: 82, the WordPress default.', 'alps'), 33, ['type' => 'number', 'min' => 20, 'max' => 95, 'placeholder' => '82']],
                ['field', 'alps_image_full_max', __('Full image: longest side (px)', 'alps'), __('320–1920. Empty: 1920.', 'alps'), 33, ['type' => 'number', 'min' => 320, 'max' => 1920, 'placeholder' => '1920']],
                ['field', 'alps_image_small_max', __('Small image: longest side (px)', 'alps'), __('64–768, smaller than the full image. Empty: 768.', 'alps'), 33, ['type' => 'number', 'min' => 64, 'max' => 768, 'placeholder' => '768']],
                ['html', \App\RetainedOriginalsPage::settingsHtml()],
                ['field', 'alps_image_keep_originals', __('Keep the uploaded originals', 'alps'), __('Unticked: they are deleted a few minutes after the upload, and those kept so far by the next clean-up.', 'alps'), 50],
                ['field', 'alps_image_original_days', __('Keep them for (days)', 'alps'), __('1–365. Empty: 7. Also applies to the originals kept so far.', 'alps'), 50, ['type' => 'number', 'min' => 1, 'max' => 365, 'placeholder' => '7', 'data-shown-with' => 'alps_image_keep_originals']],
            ]],
            'authors' => [__('AUTHORS', 'alps'), [
                ['html', \App\Authors\AdminPage::settingsHtml()],
                ['field', 'alps_authors_enabled', __('Use author profiles', 'alps'), __('Posts and pages are credited to authors from a list (Posts → Authors) instead of user accounts. Run the migration first (wp alps authors migrate).', 'alps'), 100],
                ['field', 'alps_authors_base', __('Author page address', 'alps'), __('Author pages are at /<this>/<author>/. Empty: autorius.', 'alps'), 50, ['placeholder' => 'autorius', 'data-shown-with' => 'alps_authors_enabled']],
                ['field', 'alps_authors_default', __('Default author', 'alps'), __('Credited when a post is published without an author and its account has no linked author.', 'alps'), 50, ['options' => \App\Authors\AdminPage::defaultChoices(), 'data-shown-with' => 'alps_authors_enabled']],
                ['field', 'alps_authors_byline', __('Show the authors under post titles', 'alps'), __('A byline linked to the author pages.', 'alps'), 50, ['data-shown-with' => 'alps_authors_enabled']],
                ['field', 'alps_authors_hide_users', __('Hide user accounts', 'alps'), __('Visitors who are not logged in get no user list from the REST API (/wp-json/wp/v2/users) and no users sitemap; old /author/ addresses never show a login.', 'alps'), 50, ['data-shown-with' => 'alps_authors_enabled']],
            ]],
            'cache' => [__('CACHE', 'alps'), [
                ['html', \App\ThemeCache::settingsHtml()],
            ]],
        ];
    }

    /** Labels of the choices of radio and select fields. */
    public static function choices($name)
    {
        $choices = [
            'theme_color' => [
                'bluejay' => __('Bluejay', 'alps'), 'campfire' => __('Campfire', 'alps'), 'cave' => __('Cave', 'alps'),
                'denim' => __('Denim', 'alps'), 'earth' => __('Earth', 'alps'), 'emperor' => __('Emperor', 'alps'),
                'forest' => __('Forest', 'alps'), 'grapevine' => __('Grapevine', 'alps'), 'iris' => __('Iris', 'alps'),
                'lily' => __('Lily', 'alps'), 'ming' => __('Ming', 'alps'), 'night' => __('Night', 'alps'),
                'scarlett' => __('Scarlett', 'alps'), 'treefrog' => __('Treefrog', 'alps'), 'velvet' => __('Velvet', 'alps'),
                'winter' => __('Winter', 'alps'), 'nad-amethyst' => __('NAD - Amethyst', 'alps'), 'nad-branch' => __('NAD - Branch', 'alps'),
                'nad-denim' => __('NAD - Denim', 'alps'), 'nad-miracle' => __('NAD - Miracle', 'alps'), 'nad-nile' => __('NAD - Nile', 'alps'),
                'nad-spark' => __('NAD - Spark', 'alps'), 'nad-vine' => __('NAD - Vine', 'alps'),
            ],
            'sabbath_hide_screens' => [
                'hide-sabbath--all' => __('Hide the sabbath column for all screen widths.', 'alps'),
                'hide-sabbath--until-small' => __('Hide the sabbath column at small screens.', 'alps'),
                'hide-sabbath--until-medium' => __('Hide the sabbath column at medium screens.', 'alps'),
                'hide-sabbath--until-large' => __('Hide the sabbath column at large screens.', 'alps'),
            ],
            'footer_logo_type' => ['square' => __('Square', 'alps'), 'circle' => __('Circle', 'alps')],
            'sidebar_position' => ['right' => __('Right', 'alps'), 'left' => __('Left', 'alps'), 'rail' => __('Left side column', 'alps'), 'rail-scaled' => __('Left side column, scaled like the Sabbath column', 'alps')],
        ];
        return isset($choices[$name]) ? $choices[$name] : [];
    }

    /** Labels of complex sub-fields. */
    public static function subLabels()
    {
        return [
            'footer_description_en' => __('Footer Description English', 'alps'),
            'footer_description_de' => __('Fußzeilenbeschreibung Deutsch', 'alps'),
            'footer_description_fr' => __('Description du Pied de Page Français', 'alps'),
            'footer_description_pt-pt' => __('Rodapé Descrição Português', 'alps'),
            'footer_description_es' => __('Descripción del Pie de Página en Español', 'alps'),
            'footer_description_ko' => __('바닥 글 설명 한국어', 'alps'),
            'footer_description_ru' => __('Описание нижнего колонтитула (Русский)', 'alps'),
            'footer_description_zh-hant' => __('页脚说明中文', 'alps'),
            'footer_address_street' => __('Street Address', 'alps'),
            'footer_address_city' => __('City', 'alps'),
            'footer_address_state' => __('State', 'alps'),
            'footer_address_zip' => __('Postal Code', 'alps'),
            'footer_address_country' => __('Country', 'alps'),
            'footer_phone' => __('Phone Number', 'alps'),
            'sabbath_verse_text' => __('Verse', 'alps'),
            'sabbath_verse_reference' => __('Reference', 'alps'),
        ];
    }

    public static function assets($hook)
    {
        if ($hook !== 'appearance_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_media();
        $dir = get_template_directory();
        $uri = get_template_directory_uri();
        wp_enqueue_style('alps-theme-settings', $uri . '/assets/css/theme-settings.css', [], (string) filemtime($dir . '/assets/css/theme-settings.css'));
        wp_enqueue_script('alps-theme-settings', $uri . '/assets/js/theme-settings.js', ['media-editor'], (string) filemtime($dir . '/assets/js/theme-settings.js'), true);
        wp_localize_script('alps-theme-settings', 'alpsThemeSettings', [
            'choose' => __('Select image', 'alps'),
            'use' => __('Use this image', 'alps'),
        ]);
    }

    public static function render()
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the view to show.
        if (isset($_GET['view']) && sanitize_key(wp_unslash($_GET['view'])) === \App\RetainedOriginalsPage::VIEW) {
            \App\RetainedOriginalsPage::render();
            return;
        }
        $layout = self::layout();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the tab to show.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'global';
        if (! isset($layout[$tab])) {
            $tab = 'global';
        }
        echo '<div class="wrap alps-settings">';
        echo '<h1>' . esc_html__('ALPS Theme Settings', 'alps') . '</h1>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'alps') . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="alps-settings__form">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '" class="alps-settings__tab-input">';
        wp_nonce_field(self::ACTION);
        echo '<nav class="nav-tab-wrapper alps-settings__tabs">';
        foreach ($layout as $key => $section) {
            printf('<a href="%s" class="nav-tab%s" data-tab="%s">%s</a>', esc_url(self::url(['tab' => $key])), $key === $tab ? ' nav-tab-active' : '', esc_attr($key), esc_html($section[0]));
        }
        echo '</nav>';
        foreach ($layout as $key => $section) {
            printf('<section class="alps-settings__panel" id="alps-tab-%s" data-tab="%s"%s>', esc_attr($key), esc_attr($key), $key === $tab ? ' data-active' : '');
            echo '<h2 class="alps-settings__panel-title">' . esc_html($section[0]) . '</h2><div class="alps-settings__grid">';
            foreach ($section[1] as $item) {
                self::renderItem($item);
            }
            echo '</div></section>';
        }
        submit_button(__('Save Changes', 'alps'));
        echo '</form></div>';
    }

    private static function renderItem(array $item)
    {
        switch ($item[0]) {
            case 'heading':
                echo '<div class="alps-settings__item alps-settings__item--100 alps-settings__heading"><h3>' . esc_html($item[1]) . '</h3>';
                if (! empty($item[2])) {
                    echo '<p class="description">' . esc_html($item[2]) . '</p>';
                }
                echo '</div>';
                return;
            case 'html':
                echo '<div class="alps-settings__item alps-settings__item--100">' . wp_kses_post($item[1]) . '</div>';
                return;
        }
        $name = $item[1];
        $field = Registry::field('option', $name);
        if (! $field) {
            return;
        }
        $label = $item[2];
        $help = isset($item[3]) ? $item[3] : '';
        $width = isset($item[4]) ? (int) $item[4] : 100;
        $attrs = isset($item[5]) ? $item[5] : [];
        $value = Store::option($name);
        $id = 'alps-' . $name;
        $shown_with = isset($attrs['data-shown-with']) ? $attrs['data-shown-with'] : '';
        unset($attrs['data-shown-with']);

        printf('<div class="alps-settings__item alps-settings__item--%s alps-settings__item--%s"%s>', esc_attr((string) $width), esc_attr($field['type']), $shown_with ? ' data-shown-with="alps-' . esc_attr($shown_with) . '"' : '');
        if ($field['type'] === 'checkbox') {
            printf('<label class="alps-settings__check"><input type="hidden" name="alps[%1$s]" value=""><input type="checkbox" id="%2$s" name="alps[%1$s]" value="%3$s"%4$s> %5$s</label>', esc_attr($name), esc_attr($id), esc_attr($field['value']), checked($value, true, false), esc_html($label));
        } elseif ($field['type'] === 'complex') {
            echo '<fieldset class="alps-settings__complex"><legend class="alps-settings__label">' . esc_html($label) . '</legend>';
            self::renderComplex($name, $field, is_array($value) ? $value : []);
            echo '</fieldset>';
        } else {
            printf('<label class="alps-settings__label" for="%s">%s</label>', esc_attr($id), esc_html($label));
            self::renderInput($name, 'alps[' . $name . ']', $id, $field, $value, $attrs);
        }
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</div>';
    }

    private static function renderInput($name, $input_name, $id, array $field, $value, array $attrs = [])
    {
        switch ($field['type']) {
            case 'textarea':
                printf('<textarea class="large-text" rows="2" id="%s" name="%s">%s</textarea>', esc_attr($id), esc_attr($input_name), esc_textarea((string) $value));
                return;
            case 'select':
                printf('<select id="%s" name="%s">', esc_attr($id), esc_attr($input_name));
                foreach (self::choices($name) as $key => $text) {
                    printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($value, $key, false), esc_html($text));
                }
                echo '</select>';
                return;
            case 'radio':
                echo '<div id="' . esc_attr($id) . '" class="alps-settings__radios">';
                foreach (self::choices($name) as $key => $text) {
                    printf('<label><input type="radio" name="%s" value="%s"%s> %s</label>', esc_attr($input_name), esc_attr($key), checked($value, $key, false), esc_html($text));
                }
                echo '</div>';
                return;
            case 'image':
                $image_id = absint($value);
                $preview = $image_id ? wp_get_attachment_image($image_id, 'thumbnail', false, ['class' => 'alps-settings__preview-img']) : '';
                printf('<div class="alps-settings__image" data-image><input type="hidden" id="%s" name="%s" value="%s" data-image-input>', esc_attr($id), esc_attr($input_name), esc_attr($image_id ? (string) $image_id : ''));
                echo '<div class="alps-settings__preview" data-image-preview>' . wp_kses_post($preview) . '</div>';
                printf('<button type="button" class="button" data-image-choose>%s</button> <button type="button" class="button-link button-link-delete" data-image-remove%s>%s</button></div>', esc_html__('Select image', 'alps'), $image_id ? '' : ' hidden', esc_html__('Remove', 'alps'));
                return;
            default:
                if (isset($attrs['options'])) {
                    // A text field picked from a list made at runtime (e.g. the authors).
                    printf('<select id="%s" name="%s">', esc_attr($id), esc_attr($input_name));
                    foreach ($attrs['options'] as $key => $text) {
                        printf('<option value="%s"%s>%s</option>', esc_attr((string) $key), selected((string) $value, (string) $key, false), esc_html($text));
                    }
                    echo '</select>';
                    return;
                }
                printf('<input class="regular-text" id="%s" name="%s" value="%s"', esc_attr($id), esc_attr($input_name), esc_attr((string) $value));
                foreach ($attrs + ['type' => 'text'] as $attr => $attr_value) {
                    echo ' ' . esc_attr($attr) . '="' . esc_attr((string) $attr_value) . '"';
                }
                echo '>';
        }
    }

    private static function renderComplex($name, array $field, array $rows)
    {
        $group = (string) array_key_first($field['groups']);
        $subs = $field['groups'][$group];
        $single = $name === 'footer_address';
        if ($name === 'footer_description' && ! $rows && Store::legacyFooterDescription() !== '') {
            // The footer shows an older plain description: offer it as the
            // first entry, so saving keeps it in this field.
            $rows = [['footer_description_en' => Store::legacyFooterDescription()]];
        }
        if ($single && ! $rows) {
            // Carbon Fields showed this one row (min 1, max 1) even when empty.
            $rows = [[]];
        }
        echo '<div class="alps-settings__rows" data-rows="' . esc_attr($name) . '">';
        $index = 0;
        foreach ($rows as $row) {
            self::renderRow($name, $group, $subs, $index++, $row, ! $single);
        }
        echo '</div>';
        if (! $single) {
            echo '<p class="alps-settings__empty"' . ($rows ? ' hidden' : '') . '>' . esc_html__('There are no entries yet.', 'alps') . '</p>';
            echo '<template data-row-template="' . esc_attr($name) . '">';
            self::renderRow($name, $group, $subs, '__i__', [], true);
            echo '</template>';
            echo '<p><button type="button" class="button" data-row-add="' . esc_attr($name) . '" data-next="' . esc_attr((string) $index) . '">' . esc_html__('Add entry', 'alps') . '</button></p>';
        }
    }

    private static function renderRow($name, $group, array $subs, $index, array $row, $removable)
    {
        $labels = self::subLabels();
        echo '<div class="alps-settings__row" data-row>';
        printf('<input type="hidden" name="alps[%s][%s][_type]" value="%s">', esc_attr($name), esc_attr((string) $index), esc_attr($group));
        foreach ($subs as $sub => $sub_field) {
            // A new row starts with the defaults, as in Carbon Fields.
            $sub_value = array_key_exists($sub, $row) ? $row[$sub] : (isset($sub_field['default']) ? $sub_field['default'] : '');
            $id = 'alps-' . $name . '-' . $index . '-' . $sub;
            echo '<div class="alps-settings__sub">';
            printf('<label class="alps-settings__label" for="%s">%s</label>', esc_attr($id), esc_html(isset($labels[$sub]) ? $labels[$sub] : $sub));
            self::renderInput($sub, 'alps[' . $name . '][' . $index . '][' . $sub . ']', $id, $sub_field, $sub_value);
            echo '</div>';
        }
        if ($removable) {
            echo '<p><button type="button" class="button-link button-link-delete" data-row-remove>' . esc_html__('Remove entry', 'alps') . '</button></p>';
        }
        echo '</div>';
    }

    public static function save()
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Sorry, you are not allowed to manage these options.', 'alps'), 403);
        }
        check_admin_referer(self::ACTION);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitised per field below.
        $input = isset($_POST['alps']) && is_array($_POST['alps']) ? wp_unslash($_POST['alps']) : [];
        // Store::setOption() deletes and re-adds each row: a clean-up running now would
        // read the defaults, so it waits (OriginalImageRetention::sweep()).
        set_transient(\App\OriginalImageRetention::SAVING, 1, 2 * MINUTE_IN_SECONDS);
        foreach (self::layout() as $section) {
            foreach ($section[1] as $item) {
                if ($item[0] !== 'field') {
                    continue;
                }
                $field = Registry::field('option', $item[1]);
                if ($field) {
                    Store::setOption($item[1], self::sanitize($field, isset($input[$item[1]]) ? $input[$item[1]] : null));
                }
            }
        }
        // E.g. OriginalImageRetention recomputes due dates when its settings changed.
        do_action('alps_theme_settings_saved');
        delete_transient(\App\OriginalImageRetention::SAVING);
        $tab = isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : 'global';
        wp_safe_redirect(self::url(['tab' => $tab, 'updated' => 1]));
        exit;
    }

    /** A submitted value in the form Store::setOption() takes. */
    public static function sanitize(array $field, $value)
    {
        switch ($field['type']) {
            case 'checkbox':
                return $value === $field['value'] ? $field['value'] : '';
            case 'radio':
            case 'select':
                return is_string($value) && in_array($value, $field['options'], true) ? $value : '';
            case 'image':
                return is_scalar($value) && absint($value) ? (string) absint($value) : '';
            case 'complex':
                $rows = [];
                foreach (is_array($value) ? $value : [] as $row) {
                    if (! is_array($row) || ! isset($row['_type']) || ! isset($field['groups'][$row['_type']])) {
                        continue;
                    }
                    $clean = ['_type' => (string) $row['_type']];
                    foreach ($field['groups'][$row['_type']] as $sub => $sub_field) {
                        $clean[$sub] = self::sanitize($sub_field, isset($row[$sub]) ? $row[$sub] : null);
                    }
                    $rows[] = $clean;
                }
                return $rows;
            default:
                $value = is_scalar($value) ? (string) $value : '';
                // Carbon Fields stored text as typed; users without
                // unfiltered_html (e.g. multisite admins) get safe HTML only.
                return current_user_can('unfiltered_html') ? $value : wp_kses_post($value);
        }
    }
}
