<?php

namespace App\Authors;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Posts → Authors (edit.php?page=alps-authors): the list of authors, add,
 * edit, delete (giving their posts to another author) and merge. Editors and
 * administrators (edit_others_posts) manage it. While author profiles are off
 * only administrators see the page, to prepare the list.
 *
 * Forms post to admin-post.php with a nonce, like ALPS Theme Settings.
 * The post list gets an "All authors" filter; the Authors column is the
 * taxonomy's admin column.
 */
class AdminPage
{
    const SLUG = 'alps-authors';
    const CAPABILITY = 'edit_others_posts';
    const PER_PAGE = 50;

    public static function register()
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_alps_author_save', [__CLASS__, 'save']);
        add_action('admin_post_alps_author_delete', [__CLASS__, 'delete']);
        add_action('admin_post_alps_author_merge', [__CLASS__, 'merge']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('restrict_manage_posts', [__CLASS__, 'filter'], 10, 2);
    }

    public static function capability()
    {
        return Taxonomy::enabled() ? self::CAPABILITY : 'manage_options';
    }

    public static function menu()
    {
        add_posts_page(__('Authors', 'alps'), __('Authors', 'alps'), self::capability(), self::SLUG, [__CLASS__, 'render']);
    }

    public static function url($args = [])
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('edit.php'));
    }

    /** The intro of ALPS Theme Settings → AUTHORS. */
    public static function settingsHtml()
    {
        return '<p>' . esc_html__('Authors are a list of names managed under Posts → Authors; the editor picks a post’s authors from it. User accounts can then be deleted without changing who a post is credited to. See docs/authors.md in the theme.', 'alps') . '</p>'
            . '<p><a class="button" href="' . esc_url(self::url()) . '">' . esc_html__('Open the author list', 'alps') . '</a></p>';
    }

    /** Choices of the "Default author" setting. */
    public static function defaultChoices()
    {
        $choices = ['' => __('— None —', 'alps')];
        if (! taxonomy_exists(Taxonomy::NAME)) {
            return $choices;
        }
        $terms = get_terms(['taxonomy' => Taxonomy::NAME, 'hide_empty' => false, 'orderby' => 'name', 'number' => 1000]);
        foreach (is_array($terms) ? $terms : [] as $term) {
            $choices[(string) $term->term_id] = $term->name;
        }
        return $choices;
    }

    public static function assets($hook)
    {
        if ($hook !== 'posts_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_media();
        $dir = get_template_directory();
        $uri = get_template_directory_uri();
        wp_enqueue_script('alps-authors-admin', $uri . '/assets/js/authors-admin.js', ['media-editor'], (string) filemtime($dir . '/assets/js/authors-admin.js'), true);
        wp_localize_script('alps-authors-admin', 'alpsAuthorsAdmin', [
            'choose' => __('Select photo', 'alps'),
            'use' => __('Use this photo', 'alps'),
        ]);
    }

    /** "All authors" on the post lists of post types with authors. */
    public static function filter($post_type, $which = 'top')
    {
        if (! Taxonomy::enabled() || $which !== 'top' || ! in_array($post_type, Taxonomy::postTypes(), true)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A list filter.
        $selected = isset($_GET[Taxonomy::NAME]) ? sanitize_title(wp_unslash($_GET[Taxonomy::NAME])) : '';
        echo '<label class="screen-reader-text" for="alps-author-filter">' . esc_html__('Filter by author', 'alps') . '</label>';
        wp_dropdown_categories([
            'taxonomy' => Taxonomy::NAME,
            'name' => Taxonomy::NAME,
            'id' => 'alps-author-filter',
            'value_field' => 'slug',
            'show_option_all' => __('All authors', 'alps'),
            'hide_empty' => false,
            'hierarchical' => false,
            'orderby' => 'name',
            'selected' => $selected,
        ]);
    }

    public static function render()
    {
        if (! current_user_can(self::capability())) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- These only pick what to show.
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $term_id = isset($_GET['author']) ? absint(wp_unslash($_GET['author'])) : 0;
        // phpcs:enable
        $term = $term_id ? get_term($term_id, Taxonomy::NAME) : null;
        $term = $term instanceof \WP_Term ? $term : null;

        echo '<div class="wrap alps-authors">';
        self::notices();
        if ($action === 'new' || ($action === 'edit' && $term)) {
            self::renderForm($term);
        } elseif ($action === 'delete' && $term) {
            self::renderDelete($term);
        } elseif ($action === 'merge') {
            self::renderMerge();
        } else {
            self::renderList();
        }
        echo '</div>';
    }

    private static function notices()
    {
        if (! Taxonomy::enabled()) {
            printf(
                '<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
                esc_html__('Author profiles are off: this list is not used on the site yet, and only administrators see this page.', 'alps'),
                esc_url(\App\Fields\SettingsPage::url(['tab' => 'authors'])),
                esc_html__('ALPS Theme Settings → AUTHORS', 'alps')
            );
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
        $message = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
        // phpcs:enable
        $messages = [
            'added' => __('Author added.', 'alps'),
            'updated' => __('Author saved.', 'alps'),
            'deleted' => __('Author deleted.', 'alps'),
            'merged' => __('Authors merged.', 'alps'),
        ];
        if (isset($messages[$message])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$message]) . '</p></div>';
        }
        if ($error !== '') {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }
    }

    private static function renderList()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Search and paging only.
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
        // phpcs:enable
        $query = ['taxonomy' => Taxonomy::NAME, 'hide_empty' => false, 'search' => $search];
        $total = (int) wp_count_terms($query);
        $terms = get_terms($query + ['orderby' => 'name', 'number' => self::PER_PAGE, 'offset' => ($paged - 1) * self::PER_PAGE]);
        $terms = is_array($terms) ? $terms : [];

        echo '<h1 class="wp-heading-inline">' . esc_html__('Authors', 'alps') . '</h1> ';
        echo '<a href="' . esc_url(self::url(['action' => 'new'])) . '" class="page-title-action">' . esc_html__('Add author', 'alps') . '</a> ';
        echo '<a href="' . esc_url(self::url(['action' => 'merge'])) . '" class="page-title-action">' . esc_html__('Merge authors', 'alps') . '</a>';
        echo '<hr class="wp-header-end">';
        echo '<p>' . esc_html__('Posts and pages are credited to these names, not to user accounts. Choose a post’s authors in the editor’s Author panel.', 'alps') . '</p>';

        $holder = Accounts::holder();
        if ($holder) {
            /* translators: 1: number of posts, 2: account name */
            echo '<p class="description">' . esc_html(sprintf(_n('%1$d post is held by the locked “%2$s” account (deleted accounts’ posts). Their authors are unchanged.', '%1$d posts are held by the locked “%2$s” account (deleted accounts’ posts). Their authors are unchanged.', Accounts::holderPostCount(), 'alps'), Accounts::holderPostCount(), $holder->display_name)) . '</p>';
        }

        echo '<form method="get" class="search-form"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
        echo '<p class="search-box"><label class="screen-reader-text" for="alps-author-search">' . esc_html__('Search authors', 'alps') . '</label>';
        echo '<input type="search" id="alps-author-search" name="s" value="' . esc_attr($search) . '"> ';
        submit_button(__('Search authors', 'alps'), '', '', false);
        echo '</p></form>';

        echo '<table class="wp-list-table widefat fixed striped alps-authors__table"><thead><tr>';
        echo '<th scope="col" style="width:64px">' . esc_html__('Photo', 'alps') . '</th>';
        echo '<th scope="col">' . esc_html__('Name', 'alps') . '</th>';
        echo '<th scope="col">' . esc_html__('URL slug', 'alps') . '</th>';
        echo '<th scope="col" style="width:90px">' . esc_html__('Posts', 'alps') . '</th>';
        echo '<th scope="col">' . esc_html__('Linked account', 'alps') . '</th>';
        echo '</tr></thead><tbody>';
        if (! $terms) {
            echo '<tr><td colspan="5">' . esc_html__('No authors found.', 'alps') . '</td></tr>';
        }
        foreach ($terms as $term) {
            $image = (int) get_term_meta($term->term_id, Taxonomy::IMAGE, true);
            $user = Taxonomy::linkedUser($term);
            $posts = count(Taxonomy::postIds($term->term_id));
            echo '<tr data-author="' . esc_attr((string) $term->term_id) . '">';
            echo '<td>' . ($image ? wp_get_attachment_image($image, [48, 48], false, ['style' => 'width:48px;height:48px;object-fit:cover;border-radius:50%']) : '') . '</td>';
            echo '<td><strong><a class="row-title" href="' . esc_url(self::url(['action' => 'edit', 'author' => $term->term_id])) . '">' . esc_html($term->name) . '</a></strong>';
            echo '<div class="row-actions">';
            echo '<span class="edit"><a href="' . esc_url(self::url(['action' => 'edit', 'author' => $term->term_id])) . '">' . esc_html__('Edit', 'alps') . '</a> | </span>';
            if (Taxonomy::enabled()) {
                $link = get_term_link($term);
                if (! is_wp_error($link)) {
                    echo '<span class="view"><a href="' . esc_url($link) . '">' . esc_html__('View', 'alps') . '</a> | </span>';
                }
            }
            echo '<span class="delete"><a class="submitdelete" href="' . esc_url(self::url(['action' => 'delete', 'author' => $term->term_id])) . '">' . esc_html__('Delete', 'alps') . '</a></span>';
            echo '</div></td>';
            echo '<td>' . esc_html($term->slug) . '</td>';
            if ($posts && Taxonomy::enabled()) {
                echo '<td><a href="' . esc_url(add_query_arg([Taxonomy::NAME => $term->slug, 'post_type' => 'post'], admin_url('edit.php'))) . '">' . esc_html(number_format_i18n($posts)) . '</a></td>';
            } else {
                echo '<td>' . esc_html(number_format_i18n($posts)) . '</td>';
            }
            echo '<td>' . ($user ? esc_html($user->display_name . ' (' . $user->user_login . ')') : '—') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages > 1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">' . wp_kses_post(paginate_links([
                'base' => add_query_arg('paged', '%#%', self::url($search !== '' ? ['s' => $search] : [])),
                'format' => '',
                'current' => $paged,
                'total' => $pages,
            ])) . '</div></div>';
        }
    }

    private static function renderForm($term)
    {
        $values = [
            'name' => $term ? $term->name : '',
            'slug' => $term ? $term->slug : '',
            'description' => $term ? $term->description : '',
            'image' => $term ? (int) get_term_meta($term->term_id, Taxonomy::IMAGE, true) : 0,
            'url' => $term ? (string) get_term_meta($term->term_id, Taxonomy::URL, true) : '',
            'facebook' => $term ? (string) get_term_meta($term->term_id, Taxonomy::FACEBOOK, true) : '',
            'user' => $term ? (int) get_term_meta($term->term_id, Taxonomy::USER, true) : 0,
        ];
        echo '<h1>' . esc_html($term ? __('Edit author', 'alps') : __('Add author', 'alps')) . '</h1>';
        echo '<p><a href="' . esc_url(self::url()) . '">' . esc_html__('← Back to authors', 'alps') . '</a></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="alps-authors__form">';
        echo '<input type="hidden" name="action" value="alps_author_save">';
        echo '<input type="hidden" name="author" value="' . esc_attr($term ? (string) $term->term_id : '0') . '">';
        wp_nonce_field('alps_author_save');
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="alps-author-name">' . esc_html__('Name', 'alps') . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="alps-author-name" name="name" value="' . esc_attr($values['name']) . '" required></td></tr>';

        echo '<tr><th scope="row"><label for="alps-author-slug">' . esc_html__('URL slug', 'alps') . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="alps-author-slug" name="slug" value="' . esc_attr($values['slug']) . '">';
        /* translators: %s: example address */
        echo '<p class="description">' . esc_html(sprintf(__('Empty: made from the name. The author page is %s.', 'alps'), home_url('/' . Taxonomy::base() . '/' . ($values['slug'] !== '' ? $values['slug'] : '…') . '/'))) . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="alps-author-description">' . esc_html__('Bio', 'alps') . '</label></th>';
        echo '<td><textarea class="large-text" rows="5" id="alps-author-description" name="description">' . esc_textarea($values['description']) . '</textarea></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Photo', 'alps') . '</th><td><div class="alps-authors__image" data-image>';
        echo '<input type="hidden" name="image" value="' . esc_attr($values['image'] ? (string) $values['image'] : '') . '" data-image-input>';
        echo '<div data-image-preview>' . ($values['image'] ? wp_get_attachment_image($values['image'], 'thumbnail') : '') . '</div>';
        echo '<button type="button" class="button" data-image-choose>' . esc_html__('Select photo', 'alps') . '</button> ';
        echo '<button type="button" class="button-link button-link-delete" data-image-remove' . ($values['image'] ? '' : ' hidden') . '>' . esc_html__('Remove', 'alps') . '</button>';
        echo '</div></td></tr>';

        echo '<tr><th scope="row"><label for="alps-author-url">' . esc_html__('Website', 'alps') . '</label></th>';
        echo '<td><input type="url" class="regular-text" id="alps-author-url" name="url" value="' . esc_attr($values['url']) . '"></td></tr>';

        echo '<tr><th scope="row"><label for="alps-author-facebook">' . esc_html__('Facebook', 'alps') . '</label></th>';
        echo '<td><input type="url" class="regular-text" id="alps-author-facebook" name="facebook" value="' . esc_attr($values['facebook']) . '"></td></tr>';

        echo '<tr><th scope="row"><label for="alps-author-user">' . esc_html__('Linked account', 'alps') . '</label></th><td>';
        echo '<select id="alps-author-user" name="user"><option value="0">' . esc_html__('— None —', 'alps') . '</option>';
        foreach (get_users(['orderby' => 'display_name', 'fields' => ['ID', 'display_name', 'user_login']]) as $user) {
            if (Accounts::isLocked((int) $user->ID)) {
                continue;
            }
            printf('<option value="%d"%s>%s</option>', (int) $user->ID, selected($values['user'], (int) $user->ID, false), esc_html($user->display_name . ' (' . $user->user_login . ')'));
        }
        echo '</select><p class="description">' . esc_html__('Optional. New posts by this account start with this author chosen. The account’s name is never shown on the site.', 'alps') . '</p></td></tr>';

        echo '</tbody></table>';
        submit_button($term ? __('Save author', 'alps') : __('Add author', 'alps'));
        echo '</form>';
    }

    private static function renderDelete(\WP_Term $term)
    {
        $posts = count(Taxonomy::postIds($term->term_id));
        /* translators: %s: author name */
        echo '<h1>' . esc_html(sprintf(__('Delete author “%s”', 'alps'), $term->name)) . '</h1>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="alps_author_delete">';
        echo '<input type="hidden" name="author" value="' . esc_attr((string) $term->term_id) . '">';
        wp_nonce_field('alps_author_delete');
        if ($posts) {
            /* translators: %d: number of posts */
            echo '<p>' . esc_html(sprintf(_n('This author is credited on %d post. Choose who is credited instead:', 'This author is credited on %d posts. Choose who is credited instead:', $posts, 'alps'), $posts)) . '</p>';
            echo '<p><label class="screen-reader-text" for="alps-author-reassign">' . esc_html__('Credit the posts to', 'alps') . '</label>';
            self::authorSelect('reassign', 'alps-author-reassign', 0, [$term->term_id], true);
            echo '</p>';
        } else {
            echo '<p>' . esc_html__('This author is not credited on any post.', 'alps') . '</p>';
        }
        submit_button(__('Delete author', 'alps'), 'delete');
        echo '<p><a href="' . esc_url(self::url()) . '">' . esc_html__('Cancel', 'alps') . '</a></p>';
        echo '</form>';
    }

    private static function renderMerge()
    {
        echo '<h1>' . esc_html__('Merge authors', 'alps') . '</h1>';
        echo '<p>' . esc_html__('For duplicates: the posts of the first author are credited to the second, then the first is deleted.', 'alps') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="alps_author_merge">';
        wp_nonce_field('alps_author_merge');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="alps-author-merge-from">' . esc_html__('Remove this author', 'alps') . '</label></th><td>';
        self::authorSelect('from', 'alps-author-merge-from', 0, [], true);
        echo '</td></tr><tr><th scope="row"><label for="alps-author-merge-to">' . esc_html__('Keep this author', 'alps') . '</label></th><td>';
        self::authorSelect('to', 'alps-author-merge-to', 0, [], true);
        echo '</td></tr></tbody></table>';
        submit_button(__('Merge', 'alps'));
        echo '<p><a href="' . esc_url(self::url()) . '">' . esc_html__('Cancel', 'alps') . '</a></p>';
        echo '</form>';
    }

    private static function authorSelect($name, $id, $selected, array $exclude, $required)
    {
        $terms = get_terms(['taxonomy' => Taxonomy::NAME, 'hide_empty' => false, 'orderby' => 'name', 'exclude' => $exclude]);
        printf('<select name="%s" id="%s"%s><option value="">%s</option>', esc_attr($name), esc_attr($id), $required ? ' required' : '', esc_html__('— Choose an author —', 'alps'));
        foreach (is_array($terms) ? $terms : [] as $term) {
            printf('<option value="%d"%s>%s</option>', (int) $term->term_id, selected($selected, (int) $term->term_id, false), esc_html($term->name . ' (' . $term->slug . ')'));
        }
        echo '</select>';
    }

    private static function guard()
    {
        if (! current_user_can(self::capability())) {
            wp_die(esc_html__('Sorry, you are not allowed to manage authors.', 'alps'), 403);
        }
    }

    private static function back($args)
    {
        wp_safe_redirect(self::url(array_map('rawurlencode', array_map('strval', $args))));
        exit;
    }

    public static function save()
    {
        self::guard();
        check_admin_referer('alps_author_save');
        $term_id = isset($_POST['author']) ? absint(wp_unslash($_POST['author'])) : 0;
        $fields = [];
        foreach (['name', 'slug', 'url', 'facebook'] as $key) {
            $fields[$key] = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        }
        $fields['description'] = isset($_POST['description']) ? wp_kses_post(wp_unslash($_POST['description'])) : '';
        $fields['image'] = isset($_POST['image']) ? absint(wp_unslash($_POST['image'])) : 0;
        $fields['user'] = isset($_POST['user']) ? absint(wp_unslash($_POST['user'])) : 0;
        if ($fields['image'] && ! wp_attachment_is_image($fields['image'])) {
            $fields['image'] = 0;
        }

        $back = $term_id ? ['action' => 'edit', 'author' => $term_id] : ['action' => 'new'];
        if ($fields['user']) {
            $user = get_userdata($fields['user']);
            $linked = Taxonomy::linkedTo($fields['user']);
            if (! $user || Accounts::isLocked($user)) {
                self::back($back + ['error' => __('This account cannot be linked to an author.', 'alps')]);
            }
            if ($linked && (int) $linked->term_id !== $term_id) {
                /* translators: %s: author name */
                self::back($back + ['error' => sprintf(__('This account is already linked to the author “%s”.', 'alps'), $linked->name)]);
            }
        }
        if ($term_id && ! get_term($term_id, Taxonomy::NAME)) {
            self::back(['error' => __('This author no longer exists.', 'alps')]);
        }
        $result = $term_id ? Taxonomy::update($term_id, $fields) : Taxonomy::create($fields);
        if (is_wp_error($result)) {
            self::back($back + ['error' => $result->get_error_message()]);
        }
        self::back(['message' => $term_id ? 'updated' : 'added']);
    }

    public static function delete()
    {
        self::guard();
        check_admin_referer('alps_author_delete');
        $term_id = isset($_POST['author']) ? absint(wp_unslash($_POST['author'])) : 0;
        $to = isset($_POST['reassign']) ? absint(wp_unslash($_POST['reassign'])) : 0;
        $term = $term_id ? get_term($term_id, Taxonomy::NAME) : null;
        if (! $term instanceof \WP_Term) {
            self::back(['error' => __('This author no longer exists.', 'alps')]);
        }
        if (Taxonomy::postIds($term_id)) {
            // Posts are never left without an author.
            if (! $to || ! get_term($to, Taxonomy::NAME) || $to === $term_id) {
                self::back(['action' => 'delete', 'author' => $term_id, 'error' => __('Choose the author who is credited instead.', 'alps')]);
            }
            $result = Taxonomy::replace($term_id, $to);
        } else {
            $result = wp_delete_term($term_id, Taxonomy::NAME);
        }
        if (is_wp_error($result)) {
            self::back(['error' => $result->get_error_message()]);
        }
        self::clearDefault($term_id, $to);
        self::back(['message' => 'deleted']);
    }

    public static function merge()
    {
        self::guard();
        check_admin_referer('alps_author_merge');
        $from = isset($_POST['from']) ? absint(wp_unslash($_POST['from'])) : 0;
        $to = isset($_POST['to']) ? absint(wp_unslash($_POST['to'])) : 0;
        $result = Taxonomy::replace($from, $to);
        if (is_wp_error($result)) {
            self::back(['action' => 'merge', 'error' => $result->get_error_message()]);
        }
        self::clearDefault($from, $to);
        self::back(['message' => 'merged']);
    }

    /** When the default author is deleted, the author who took its posts becomes the default. */
    private static function clearDefault($deleted, $replacement)
    {
        if (absint(\App\Fields\Store::option('alps_authors_default')) === (int) $deleted) {
            \App\Fields\Store::setOption('alps_authors_default', $replacement ? (string) $replacement : '');
        }
    }
}
