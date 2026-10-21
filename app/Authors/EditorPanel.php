<?php

namespace App\Authors;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Choosing a post's authors: the block editor's Author panel
 * (resources/scripts/author-panel.js), a meta box on classic edit screens,
 * and the defaults.
 *
 * The panel edits the post's own `alps_author` REST field (the taxonomy's),
 * so the authors are saved with the post, in the order chosen (the taxonomy
 * keeps term order per post).
 *
 * Defaults: a new post starts with the author linked to its account. When a
 * post is published or scheduled with no author — from the editor, REST
 * (WPBridge) or WP-CLI — it gets that linked author, else the default author
 * (ALPS Theme Settings → AUTHORS). Authors that are set are never changed.
 */
class EditorPanel
{
    const NONCE = 'alps_author_box';

    /** Status of each post before the current REST update, by ID. */
    private static $statusBefore = [];

    public static function register()
    {
        add_action('enqueue_block_editor_assets', [__CLASS__, 'config'], 5);
        add_action('rest_api_init', [__CLASS__, 'restHooks']);
        add_filter('rest_request_before_callbacks', [__CLASS__, 'restPermissions'], 10, 3);
        add_action('wp_after_insert_post', [__CLASS__, 'safetyNet'], 20, 2);
        add_action('add_meta_boxes', [__CLASS__, 'metaBox'], 10, 2);
        add_action('save_post', [__CLASS__, 'saveMetaBox'], 10, 2);
    }

    private static function applies($type)
    {
        return Taxonomy::enabled() && in_array($type, Taxonomy::postTypes(), true);
    }

    /** The author a post gets when published without one: its account's author, else the default. */
    public static function fallback(\WP_Post $post)
    {
        $term = Taxonomy::linkedTo((int) $post->post_author);
        return $term ?: Taxonomy::defaultAuthor();
    }

    public static function config()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || empty($screen->post_type) || ! self::applies($screen->post_type)) {
            return;
        }
        $post = get_post();
        $fallback = $post ? self::fallback($post) : Taxonomy::defaultAuthor();
        $taxonomy = get_taxonomy(Taxonomy::NAME);
        wp_register_script('alps-author-panel-config', false, [], null, false);
        wp_enqueue_script('alps-author-panel-config');
        wp_add_inline_script('alps-author-panel-config', 'window.alpsAuthorPanel = ' . wp_json_encode([
            'field' => Taxonomy::NAME,
            'path' => '/wp/v2/' . Taxonomy::NAME,
            'canCreate' => $taxonomy && current_user_can($taxonomy->cap->edit_terms),
            'fallback' => $fallback ? ['id' => $fallback->term_id, 'name' => $fallback->name] : null,
        ]) . ';', 'before');
    }

    public static function restHooks()
    {
        if (! Taxonomy::enabled()) {
            return;
        }
        foreach (Taxonomy::postTypes() as $type) {
            add_filter("rest_prepare_{$type}", [__CLASS__, 'newPostDefault'], 10, 3);
            add_filter("rest_pre_insert_{$type}", [__CLASS__, 'rememberStatus'], 10, 2);
            add_action("rest_after_insert_{$type}", [__CLASS__, 'saveNewPostDefault'], 10, 2);
        }
    }

    /**
     * The author routes of the REST API:
     * - only editors and administrators add authors (for tag-like taxonomies
     *   WordPress lets anyone who may assign terms create them);
     * - authors are not deleted through REST, which would skip choosing who
     *   takes over their posts (Posts → Authors does that).
     * WordPress matches routes ignoring case, so this does too.
     */
    public static function restPermissions($response, $handler, $request)
    {
        if ($response instanceof \WP_Error || ! $request instanceof \WP_REST_Request) {
            return $response;
        }
        $route = strtolower(untrailingslashit($request->get_route()));
        $base = '/wp/v2/' . Taxonomy::NAME;
        $method = $request->get_method();
        if ($method === 'POST' && $route === $base) {
            $taxonomy = get_taxonomy(Taxonomy::NAME);
            if (! $taxonomy || ! current_user_can($taxonomy->cap->edit_terms)) {
                return new \WP_Error('rest_cannot_create', __('Sorry, you are not allowed to add authors.', 'alps'), ['status' => rest_authorization_required_code()]);
            }
        }
        if ($method === 'DELETE' && preg_match('#^' . preg_quote($base, '#') . '/\d+$#', $route)) {
            return new \WP_Error('rest_cannot_delete', __('Delete authors under Posts → Authors, where their posts are given to another author.', 'alps'), ['status' => 403]);
        }
        return $response;
    }

    /** A new post (auto-draft) shows its account's author as chosen. */
    public static function newPostDefault($response, $post, $request)
    {
        if ($post->post_status !== 'auto-draft' || $request['context'] !== 'edit' || ! $response instanceof \WP_REST_Response) {
            return $response;
        }
        $data = $response->get_data();
        if (array_key_exists(Taxonomy::NAME, $data) && ! $data[Taxonomy::NAME]) {
            $term = Taxonomy::linkedTo((int) $post->post_author);
            if ($term) {
                $data[Taxonomy::NAME] = [$term->term_id];
                $response->set_data($data);
            }
        }
        return $response;
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

    /** The first save of a new post keeps the author it showed, unless the editor changed it. */
    public static function saveNewPostDefault($post, $request)
    {
        $was_new = isset(self::$statusBefore[$post->ID]) && self::$statusBefore[$post->ID] === 'auto-draft';
        if (! $was_new || isset($request[Taxonomy::NAME]) || Taxonomy::orderedTerms($post->ID)) {
            return;
        }
        $term = Taxonomy::linkedTo((int) $post->post_author);
        if ($term) {
            Taxonomy::setForPost($post->ID, [$term->term_id]);
        }
    }

    /** A post published or scheduled without an author gets one. */
    public static function safetyNet($post_id, $post)
    {
        if (! $post instanceof \WP_Post || ! in_array($post->post_status, ['publish', 'future'], true) || ! self::applies($post->post_type)) {
            return;
        }
        if (Taxonomy::orderedTerms($post_id)) {
            return;
        }
        $term = self::fallback($post);
        if ($term) {
            Taxonomy::setForPost($post_id, [$term->term_id]);
            return;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- An admin-facing notice in the PHP log.
        error_log(sprintf('ALPS authors: post %d was published without an author and no default author is set.', $post_id));
    }

    /** Post types edited on a classic screen get a simple author box. */
    public static function metaBox($post_type, $post = null)
    {
        if (! self::applies($post_type) || (function_exists('use_block_editor_for_post') && $post instanceof \WP_Post && use_block_editor_for_post($post))) {
            return;
        }
        add_meta_box('alps-authors', __('Author', 'alps'), [__CLASS__, 'renderMetaBox'], $post_type, 'side');
    }

    public static function renderMetaBox(\WP_Post $post)
    {
        wp_nonce_field(self::NONCE, self::NONCE);
        $chosen = wp_list_pluck(Taxonomy::orderedTerms($post->ID), 'term_id');
        if (! $chosen && $post->post_status === 'auto-draft') {
            $linked = Taxonomy::linkedTo((int) $post->post_author);
            $chosen = $linked ? [$linked->term_id] : [];
        }
        $terms = get_terms(['taxonomy' => Taxonomy::NAME, 'hide_empty' => false, 'orderby' => 'name']);
        $terms = is_array($terms) ? $terms : [];
        // Chosen authors first, in their order.
        usort($terms, function ($a, $b) use ($chosen) {
            $ia = array_search($a->term_id, $chosen);
            $ib = array_search($b->term_id, $chosen);
            return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
        });
        echo '<p><input type="search" class="widefat" placeholder="' . esc_attr__('Search authors', 'alps') . '" oninput="var q=this.value.toLowerCase();this.parentNode.nextElementSibling.querySelectorAll(\'li\').forEach(function(li){li.hidden=q&&li.textContent.toLowerCase().indexOf(q)<0;});"></p>';
        echo '<ul style="max-height:240px;overflow:auto;margin:0">';
        foreach ($terms as $term) {
            printf('<li><label><input type="checkbox" name="alps_authors[]" value="%d"%s> %s</label></li>', (int) $term->term_id, checked(in_array($term->term_id, $chosen, true), true, false), esc_html($term->name));
        }
        echo '</ul>';
    }

    public static function saveMetaBox($post_id, $post)
    {
        if (! isset($_POST[self::NONCE]) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), self::NONCE)) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || ! current_user_can('edit_post', $post_id) || ! self::applies($post->post_type)) {
            return;
        }
        $ids = isset($_POST['alps_authors']) ? array_map('absint', (array) wp_unslash($_POST['alps_authors'])) : [];
        $ids = array_values(array_filter($ids, function ($id) {
            return $id && current_user_can('assign_term', $id) && get_term($id, Taxonomy::NAME);
        }));
        // Keep the order of authors that stay chosen; add new ones after them.
        $kept = array_values(array_intersect(wp_list_pluck(Taxonomy::orderedTerms($post_id), 'term_id'), $ids));
        Taxonomy::setForPost($post_id, array_merge($kept, array_diff($ids, $kept)));
    }
}
