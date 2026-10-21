<?php

namespace App\Authors;

use App\Fields\Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Authors as a managed list of names, not user accounts (docs/authors.md).
 *
 * An author is a term of the `alps_author` taxonomy; a post is credited to the
 * authors it has. The taxonomy keeps the order the authors were chosen in
 * (`sort`, WordPress's term_order), so a post can name several authors in a
 * set order. The terms stay in the database if the theme is ever switched.
 *
 * The taxonomy is always registered, so the migration can run first. Until
 * "Use author profiles" is ticked (ALPS Theme Settings → AUTHORS) it has no
 * pages, no REST routes and no screens, and the site looks as before.
 */
class Taxonomy
{
    const NAME = 'alps_author';
    const DEFAULT_BASE = 'autorius';

    // Term meta.
    const IMAGE = 'alps_author_image';
    const URL = 'alps_author_url';
    const FACEBOOK = 'alps_author_facebook';
    const USER = 'alps_author_user';

    /** Post statuses whose posts keep an author. */
    const STATUSES = ['publish', 'future', 'draft', 'pending', 'private', 'trash'];

    /** Remembers the rewrite settings the rewrite rules were built with. */
    const REWRITE_OPTION = 'alps_authors_rewrite';

    public static function register()
    {
        // Late, so post types registered on init (Modern Events Calendar …) are known.
        add_action('init', [__CLASS__, 'registerTaxonomy'], 99);
        add_action('delete_' . self::NAME, [__CLASS__, 'forgetDefault']);
    }

    /** However an author is deleted, the default author setting never points to it. */
    public static function forgetDefault($term_id)
    {
        if (absint(Store::option('alps_authors_default')) === (int) $term_id) {
            Store::setOption('alps_authors_default', '');
        }
    }

    public static function enabled()
    {
        return (bool) Store::option('alps_authors_enabled');
    }

    public static function bylineEnabled()
    {
        return self::enabled() && (bool) Store::option('alps_authors_byline');
    }

    public static function hideUsers()
    {
        return self::enabled() && (bool) Store::option('alps_authors_hide_users');
    }

    /** The URL base of author pages, `autorius` unless set. */
    public static function base()
    {
        $base = sanitize_title((string) Store::option('alps_authors_base'));
        return $base !== '' ? $base : self::DEFAULT_BASE;
    }

    /** The author credited when a post is published without one. */
    public static function defaultAuthor()
    {
        $id = absint(Store::option('alps_authors_default'));
        $term = $id ? get_term($id, self::NAME) : null;
        return $term instanceof \WP_Term ? $term : null;
    }

    /** Post types with authors: those with an edit screen that support an author. */
    public static function postTypes()
    {
        $types = [];
        foreach (get_post_types(['show_ui' => true]) as $type) {
            if ($type !== 'attachment' && strpos($type, 'wp_') !== 0 && post_type_supports($type, 'author')) {
                $types[] = $type;
            }
        }
        return array_values(array_unique((array) apply_filters('alps_author_post_types', $types)));
    }

    public static function registerTaxonomy()
    {
        $enabled = self::enabled();
        register_taxonomy(self::NAME, self::postTypes(), [
            'labels' => [
                'name' => __('Authors', 'alps'),
                'singular_name' => __('Author', 'alps'),
                'menu_name' => __('Authors', 'alps'),
                'all_items' => __('All authors', 'alps'),
                'edit_item' => __('Edit author', 'alps'),
                'view_item' => __('View author', 'alps'),
                'update_item' => __('Update author', 'alps'),
                'add_new_item' => __('Add author', 'alps'),
                'new_item_name' => __('New author name', 'alps'),
                'search_items' => __('Search authors', 'alps'),
                'not_found' => __('No authors found.', 'alps'),
                'no_terms' => __('No authors', 'alps'),
                'back_to_items' => __('Back to authors', 'alps'),
            ],
            'description' => __('People and organisations credited as authors of posts and pages.', 'alps'),
            'hierarchical' => false,
            'public' => $enabled,
            'publicly_queryable' => $enabled,
            'query_var' => $enabled ? self::NAME : false,
            'rewrite' => $enabled ? ['slug' => self::base(), 'with_front' => false] : false,
            // The theme has its own screens (Posts → Authors and the editor panel).
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'show_tagcloud' => false,
            'show_in_quick_edit' => false,
            'show_admin_column' => $enabled,
            'show_in_rest' => $enabled,
            'rest_base' => self::NAME,
            // Keep the order the authors were chosen in, per post.
            'sort' => true,
            'args' => ['orderby' => 'term_order'],
            'capabilities' => [
                'manage_terms' => 'edit_others_posts',
                'edit_terms' => 'edit_others_posts',
                'delete_terms' => 'edit_others_posts',
                // Contributors choose from the list but don't add to it.
                'assign_terms' => 'edit_posts',
            ],
        ]);

        $manage = function () {
            return current_user_can('edit_others_posts');
        };
        register_term_meta(self::NAME, self::IMAGE, ['type' => 'integer', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'absint', 'auth_callback' => $manage]);
        register_term_meta(self::NAME, self::URL, ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'esc_url_raw', 'auth_callback' => $manage]);
        register_term_meta(self::NAME, self::FACEBOOK, ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'esc_url_raw', 'auth_callback' => $manage]);
        // The linked account stays private: an account ID is not public data.
        register_term_meta(self::NAME, self::USER, ['type' => 'integer', 'single' => true, 'show_in_rest' => false, 'sanitize_callback' => 'absint', 'auth_callback' => $manage]);

        // Rebuild the rewrite rules once after the feature or its URL base changed.
        $rewrite = $enabled ? self::base() : '';
        if (get_option(self::REWRITE_OPTION) !== $rewrite) {
            update_option(self::REWRITE_OPTION, $rewrite, true);
            flush_rewrite_rules(false);
        }
    }

    /**
     * The authors of a post, in order.
     *
     * @return \WP_Term[]
     */
    public static function forPost($post)
    {
        $post = get_post($post);
        if (! $post || ! taxonomy_exists(self::NAME)) {
            return [];
        }
        $terms = get_the_terms($post, self::NAME);
        return is_array($terms) ? array_values($terms) : [];
    }

    /** Set a post's authors, in this order. */
    public static function setForPost($post_id, array $term_ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
        return wp_set_object_terms((int) $post_id, $ids, self::NAME, false);
    }

    /** The author linked to an account, if any. */
    public static function linkedTo($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || ! taxonomy_exists(self::NAME)) {
            return null;
        }
        $terms = get_terms([
            'taxonomy' => self::NAME,
            'hide_empty' => false,
            'number' => 1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One small term meta lookup.
            'meta_query' => [['key' => self::USER, 'value' => $user_id, 'type' => 'NUMERIC']],
        ]);
        return is_array($terms) && $terms ? $terms[0] : null;
    }

    /** The account an author is linked to, if it still exists. */
    public static function linkedUser($term)
    {
        $id = (int) get_term_meta(is_object($term) ? $term->term_id : (int) $term, self::USER, true);
        $user = $id ? get_userdata($id) : false;
        return $user instanceof \WP_User ? $user : null;
    }

    /** An author named exactly $name (ignoring case), if any. */
    public static function findByName($name)
    {
        $name = trim(wp_strip_all_tags((string) $name));
        if ($name === '') {
            return null;
        }
        $terms = get_terms(['taxonomy' => self::NAME, 'hide_empty' => false, 'name' => $name]);
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if (mb_strtolower($term->name) === mb_strtolower($name)) {
                    return $term;
                }
            }
        }
        return null;
    }

    /** A slug from the name that no other author has (`name`, `name-2` …). */
    public static function uniqueSlug($name, $except = 0)
    {
        $base = sanitize_title($name);
        if ($base === '') {
            $base = 'autorius';
        }
        $slug = $base;
        for ($i = 2; ($term = get_term_by('slug', $slug, self::NAME)) && (int) $term->term_id !== (int) $except; $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    /**
     * Add an author. $fields: name (required), slug, description, image, url,
     * facebook, user. Returns the term ID or a WP_Error.
     */
    public static function create(array $fields)
    {
        $name = isset($fields['name']) ? trim(wp_strip_all_tags((string) $fields['name'])) : '';
        if ($name === '') {
            return new \WP_Error('alps_author_name', __('An author needs a name.', 'alps'));
        }
        $slug = isset($fields['slug']) && sanitize_title((string) $fields['slug']) !== '' ? sanitize_title((string) $fields['slug']) : self::uniqueSlug($name);
        if (get_term_by('slug', $slug, self::NAME)) {
            return new \WP_Error('alps_author_slug', __('Another author already has this URL slug.', 'alps'));
        }
        $result = wp_insert_term($name, self::NAME, [
            'slug' => $slug,
            'description' => isset($fields['description']) ? wp_kses_post((string) $fields['description']) : '',
        ]);
        if (is_wp_error($result)) {
            return $result;
        }
        self::saveMeta($result['term_id'], $fields);
        return (int) $result['term_id'];
    }

    /** Update an author's name, slug, bio and meta. */
    public static function update($term_id, array $fields)
    {
        $name = isset($fields['name']) ? trim(wp_strip_all_tags((string) $fields['name'])) : '';
        if ($name === '') {
            return new \WP_Error('alps_author_name', __('An author needs a name.', 'alps'));
        }
        $slug = isset($fields['slug']) ? sanitize_title((string) $fields['slug']) : '';
        $slug = $slug !== '' ? $slug : self::uniqueSlug($name, $term_id);
        $other = get_term_by('slug', $slug, self::NAME);
        if ($other && (int) $other->term_id !== (int) $term_id) {
            return new \WP_Error('alps_author_slug', __('Another author already has this URL slug.', 'alps'));
        }
        $result = wp_update_term((int) $term_id, self::NAME, [
            'name' => $name,
            'slug' => $slug,
            'description' => isset($fields['description']) ? wp_kses_post((string) $fields['description']) : '',
        ]);
        if (is_wp_error($result)) {
            return $result;
        }
        self::saveMeta($term_id, $fields);
        return (int) $term_id;
    }

    private static function saveMeta($term_id, array $fields)
    {
        $meta = [
            self::IMAGE => isset($fields['image']) ? absint($fields['image']) : null,
            self::URL => isset($fields['url']) ? esc_url_raw(trim((string) $fields['url'])) : null,
            self::FACEBOOK => isset($fields['facebook']) ? esc_url_raw(trim((string) $fields['facebook'])) : null,
            self::USER => isset($fields['user']) ? absint($fields['user']) : null,
        ];
        foreach ($meta as $key => $value) {
            if ($value === null) {
                continue;
            }
            if ($value === '' || $value === 0) {
                delete_term_meta($term_id, $key);
            } else {
                update_term_meta($term_id, $key, $value);
            }
        }
    }

    /** IDs of every post (any status) credited to an author. */
    public static function postIds($term_id)
    {
        $ids = get_objects_in_term((int) $term_id, self::NAME);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /**
     * Credit an author's posts to another author (in the same place in each
     * post's list), then delete the first author. Used by Delete and Merge.
     */
    public static function replace($from_id, $to_id)
    {
        $from_id = (int) $from_id;
        $to_id = (int) $to_id;
        if ($from_id === $to_id || ! get_term($from_id, self::NAME) || ! get_term($to_id, self::NAME)) {
            return new \WP_Error('alps_author_replace', __('Choose two different authors.', 'alps'));
        }
        $moved = 0;
        foreach (self::postIds($from_id) as $post_id) {
            $ids = wp_list_pluck(self::orderedTerms($post_id), 'term_id');
            $ids = array_map(function ($id) use ($from_id, $to_id) {
                return (int) $id === $from_id ? $to_id : (int) $id;
            }, $ids);
            self::setForPost($post_id, $ids);
            $moved++;
        }
        // The author who takes over also becomes the default, if the first was.
        if (absint(Store::option('alps_authors_default')) === $from_id) {
            Store::setOption('alps_authors_default', (string) $to_id);
        }
        $deleted = wp_delete_term($from_id, self::NAME);
        return is_wp_error($deleted) ? $deleted : $moved;
    }

    /** A post's authors read from the database (no cache), in order. */
    public static function orderedTerms($post_id)
    {
        $terms = wp_get_object_terms((int) $post_id, self::NAME, ['orderby' => 'term_order', 'order' => 'ASC']);
        return is_array($terms) ? $terms : [];
    }

    /**
     * Credit the posts an account wrote (post_author $user_id) that have no
     * author yet to an author. Returns the number of posts.
     */
    public static function assignUserPosts($user_id, $term_id, array $types = [])
    {
        $types = $types ?: self::postTypes();
        if (! $types) {
            return 0;
        }
        $count = 0;
        $seen = [];
        do {
            $ids = self::postsWithoutAuthor((int) $user_id, $types, 500);
            $new = array_diff($ids, $seen);
            foreach ($new as $post_id) {
                self::setForPost($post_id, [(int) $term_id]);
                clean_post_cache($post_id);
                $seen[] = $post_id;
                $count++;
            }
        } while ($new);
        return $count;
    }

    /**
     * Posts without an author, oldest first: of one account (post_author
     * $user_id, 0 included) or, with null, of any. A direct query, so no
     * plugin (WPML …) filters the results.
     *
     * @return int[]
     */
    public static function postsWithoutAuthor($user_id, array $types, $limit = 500)
    {
        global $wpdb;
        if (! $types) {
            return [];
        }
        $type_list = implode(',', array_fill(0, count($types), '%s'));
        $status_list = implode(',', array_fill(0, count(self::STATUSES), '%s'));
        $author_sql = $user_id !== null ? 'AND p.post_author = %d' : 'AND %d = 0';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder lists built above.
        $sql = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
            WHERE p.post_type IN ($type_list) AND p.post_status IN ($status_list) $author_sql
            AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tr.object_id = p.ID AND tt.taxonomy = %s)
            ORDER BY p.ID ASC LIMIT %d",
            array_merge($types, self::STATUSES, [(int) $user_id, self::NAME, (int) $limit])
        );
        // phpcs:enable
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
        return array_map('intval', (array) $wpdb->get_col($sql));
    }

    /** How many posts of these types have no author. */
    public static function countWithoutAuthor(array $types)
    {
        return count(self::postsWithoutAuthor(null, $types, PHP_INT_MAX));
    }

    /**
     * The author for an account: its linked author, or a new one made from
     * its display name, bio, website and Facebook. Returns [term, created].
     */
    public static function ensureForUser(\WP_User $user)
    {
        $term = self::linkedTo($user->ID);
        if ($term) {
            return [$term, false];
        }
        $name = trim((string) $user->display_name) !== '' ? $user->display_name : $user->user_login;
        $id = self::create([
            'name' => $name,
            'slug' => self::uniqueSlug($name),
            'description' => (string) $user->description,
            'url' => (string) $user->user_url,
            'facebook' => (string) get_user_meta($user->ID, 'facebook', true),
            'user' => $user->ID,
        ]);
        if (is_wp_error($id)) {
            return [$id, false];
        }
        return [get_term($id, self::NAME), true];
    }

    /** Name key for spotting near-duplicates: "Adventistai.lt", "adventistai lt" → "adventistai". */
    public static function nameKey($name)
    {
        $key = strtolower(remove_accents(trim((string) $name)));
        $key = preg_replace('/[\s.]+(lt|com|org|net)$/', '', $key);
        return preg_replace('/[^a-z0-9]+/', '', $key);
    }
}
