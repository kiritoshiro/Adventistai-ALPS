<?php

namespace App\Authors;

use App\Fields\Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Moves authorship from user accounts to authors, and checks it.
 *
 * ## EXAMPLES
 *
 *     wp alps authors migrate --dry-run
 *     wp alps authors migrate
 *     wp alps authors verify
 *     wp alps authors holder-account
 */
class Cli
{
    /** The organisation's author, the default for posts published without one. */
    const DEFAULT_NAME = 'Adventistai';

    public static function register()
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('alps authors', __CLASS__);
        }
    }

    /**
     * Give every post the author of the account that wrote it.
     *
     * For each account that wrote a post (published, scheduled, draft,
     * pending, private or trashed) of a post type with authors: use the author
     * linked to it, or make one from its display name, bio, website and
     * Facebook. Then credit that account's posts that have no author yet to
     * it. Makes sure an author named "Adventistai" exists and is the default
     * author. Posts of accounts that no longer exist, of no account, or of the
     * locked "Redakcija" account get the default author. Can be run again: posts
     * that have an author are left alone.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would happen; change nothing.
     *
     * [--post-type=<types>]
     * : Comma-separated post types. Default: every post type with authors.
     *
     * @when after_wp_load
     */
    public function migrate($args, $assoc)
    {
        $dry = ! empty($assoc['dry-run']);
        $types = $this->types($assoc);
        $rows = [];
        $planned = [];
        $orphans = [];

        foreach ($this->accounts($types) as $user_id) {
            $user = $user_id > 0 ? get_userdata($user_id) : false;
            if (! $user || Accounts::isLocked($user)) {
                $orphans[] = $user_id;
                continue;
            }
            $missing = Taxonomy::postsWithoutAuthor($user_id, $types, PHP_INT_MAX);
            $term = Taxonomy::linkedTo($user->ID);
            $created = false;
            if (! $term && ! $dry) {
                list($term, $created) = Taxonomy::ensureForUser($user);
                if (is_wp_error($term)) {
                    \WP_CLI::warning(sprintf('%s: %s', $user->user_login, $term->get_error_message()));
                    continue;
                }
            }
            $name = $term ? $term->name : (trim((string) $user->display_name) !== '' ? $user->display_name : $user->user_login);
            $planned[$term ? 'term:' . $term->term_id : 'user:' . $user->ID] = $name;
            $assigned = $dry ? count($missing) : Taxonomy::assignUserPosts($user->ID, $term->term_id, $types);
            $rows[] = [
                'account' => $user->user_login,
                'author' => $name,
                'new author' => ($dry ? ! $term : $created) ? 'yes' : 'no',
                'posts assigned' => $assigned,
                'already had an author' => $this->authoredCount($user->ID, $types) - ($dry ? 0 : $assigned),
            ];
        }

        // The default author: keep the one set, else "Adventistai".
        $default = Taxonomy::defaultAuthor();
        if (! $default) {
            $default = Taxonomy::findByName(self::DEFAULT_NAME);
            if (! $default && ! $dry) {
                $id = Taxonomy::create(['name' => self::DEFAULT_NAME]);
                $default = is_wp_error($id) ? null : get_term($id, Taxonomy::NAME);
            }
            if ($default && ! $dry) {
                Store::setOption('alps_authors_default', (string) $default->term_id);
            }
            $default_note = $default
                ? sprintf('Default author: %s (%s).', $default->name, $dry ? 'would be set' : 'set')
                : sprintf('Default author: "%s" (%s).', self::DEFAULT_NAME, in_array(self::DEFAULT_NAME, $planned, true) ? 'would be the author made above' : 'would be created');
        } else {
            $default_note = sprintf('Default author: %s (already set).', $default->name);
        }
        if (! $default && ! in_array(self::DEFAULT_NAME, $planned, true)) {
            $planned['default'] = self::DEFAULT_NAME;
        }

        foreach ($orphans as $user_id) {
            $user = $user_id > 0 ? get_userdata($user_id) : false;
            $missing = count(Taxonomy::postsWithoutAuthor($user_id, $types, PHP_INT_MAX));
            $assigned = $default && ! $dry ? Taxonomy::assignUserPosts($user_id, $default->term_id, $types) : 0;
            $rows[] = [
                'account' => $user ? $user->user_login : ($user_id > 0 ? sprintf('#%d (deleted)', $user_id) : '(none)'),
                'author' => ($default ? $default->name : self::DEFAULT_NAME) . ' (default)',
                'new author' => 'no',
                'posts assigned' => $dry ? $missing : $assigned,
                'already had an author' => $this->authoredCount($user_id, $types) - ($dry ? 0 : $assigned),
            ];
        }

        \WP_CLI::log($dry ? 'Dry run: nothing was changed.' : 'Migration done.');
        \WP_CLI::log('Post types: ' . implode(', ', $types));
        \WP_CLI\Utils\format_items('table', $rows, ['account', 'author', 'new author', 'posts assigned', 'already had an author']);
        \WP_CLI::log($default_note);

        foreach ($this->allTerms() as $term) {
            $planned['term:' . $term->term_id] = $term->name;
        }
        foreach ($this->nearDuplicates($planned) as $group) {
            \WP_CLI::warning('Possibly the same author: ' . implode(' / ', $group) . '. Merge them under Posts → Authors if so.');
        }

        $left = Taxonomy::countWithoutAuthor($types);
        if ($dry) {
            \WP_CLI::log(sprintf('Posts without an author now: %d.', $left));
        } elseif ($left) {
            \WP_CLI::warning(sprintf('Posts still without an author: %d (wp alps authors verify lists them).', $left));
        } else {
            \WP_CLI::success('Every post has an author.');
        }
    }

    /**
     * List the authors.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, csv, json …
     * ---
     * default: table
     * ---
     *
     * @subcommand list
     * @when after_wp_load
     */
    public function list_($args, $assoc)
    {
        $rows = [];
        foreach ($this->allTerms() as $term) {
            $user = Taxonomy::linkedUser($term);
            $rows[] = [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'posts' => count(Taxonomy::postIds($term->term_id)),
                'account' => $user ? $user->user_login : '',
            ];
        }
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['id', 'name', 'slug', 'posts', 'account']);
    }

    /**
     * Check: posts without an author, authors without posts, authors linked to
     * deleted accounts. Exits with 1 when a post has no author.
     *
     * ## OPTIONS
     *
     * [--post-type=<types>]
     * : Comma-separated post types. Default: every post type with authors.
     *
     * @when after_wp_load
     */
    public function verify($args, $assoc)
    {
        $types = $this->types($assoc);
        $without = Taxonomy::postsWithoutAuthor(null, $types, PHP_INT_MAX);
        $empty = [];
        $orphaned = [];
        foreach ($this->allTerms() as $term) {
            if (! Taxonomy::postIds($term->term_id)) {
                $empty[] = $term->name;
            }
            $linked = (int) get_term_meta($term->term_id, Taxonomy::USER, true);
            if ($linked && ! get_userdata($linked)) {
                $orphaned[] = sprintf('%s (account #%d)', $term->name, $linked);
            }
        }
        \WP_CLI::log(sprintf('Authors without posts: %s', $empty ? implode(', ', $empty) : 'none'));
        \WP_CLI::log(sprintf('Authors linked to deleted accounts: %s', $orphaned ? implode(', ', $orphaned) : 'none'));
        if ($without) {
            \WP_CLI::log(sprintf('Posts without an author (%d): %s', count($without), implode(', ', array_slice($without, 0, 50)) . (count($without) > 50 ? ' …' : '')));
            \WP_CLI::halt(1);
        }
        \WP_CLI::success('Every post has an author.');
    }

    /**
     * Create the locked "Redakcija" account that holds deleted accounts' posts.
     *
     * No role, a random password nobody sees, and no login (password or
     * application password). Prints its ID for `wp user delete --reassign`.
     *
     * @subcommand holder-account
     * @when after_wp_load
     */
    public function holderAccount($args, $assoc)
    {
        $result = Accounts::ensureHolder();
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }
        list($user, $created) = $result;
        \WP_CLI::log(sprintf('%s "Redakcija" account: ID %d.', $created ? 'Created the' : 'Found the', $user->ID));
        \WP_CLI::log(sprintf('Delete an account and keep its posts: wp user delete <account> --reassign=%d', $user->ID));
        \WP_CLI::success('Done.');
    }

    private function types(array $assoc)
    {
        $all = Taxonomy::postTypes();
        if (empty($assoc['post-type'])) {
            return $all;
        }
        $types = array_filter(array_map('trim', explode(',', (string) $assoc['post-type'])));
        $unknown = array_diff($types, $all);
        if ($unknown) {
            \WP_CLI::error('Not a post type with authors: ' . implode(', ', $unknown));
        }
        return array_values($types);
    }

    /** Accounts that wrote a post of these types. */
    private function accounts(array $types)
    {
        global $wpdb;
        $type_list = implode(',', array_fill(0, count($types), '%s'));
        $status_list = implode(',', array_fill(0, count(Taxonomy::STATUSES), '%s'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder lists built above.
        $sql = $wpdb->prepare(
            "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type IN ($type_list) AND post_status IN ($status_list) ORDER BY post_author ASC",
            array_merge($types, Taxonomy::STATUSES)
        );
        // phpcs:enable
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
        return array_map('intval', (array) $wpdb->get_col($sql));
    }

    /** Posts of an account (of these types and statuses) that have an author. */
    private function authoredCount($user_id, array $types)
    {
        global $wpdb;
        $type_list = implode(',', array_fill(0, count($types), '%s'));
        $status_list = implode(',', array_fill(0, count(Taxonomy::STATUSES), '%s'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder lists built above.
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_author = %d AND p.post_type IN ($type_list) AND p.post_status IN ($status_list)
            AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tr.object_id = p.ID AND tt.taxonomy = %s)",
            array_merge([(int) $user_id], $types, Taxonomy::STATUSES, [Taxonomy::NAME])
        );
        // phpcs:enable
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
        return (int) $wpdb->get_var($sql);
    }

    private function allTerms()
    {
        $terms = get_terms(['taxonomy' => Taxonomy::NAME, 'hide_empty' => false, 'orderby' => 'name']);
        return is_array($terms) ? $terms : [];
    }

    /** Groups of authors (existing or to be made, by key) whose names look like the same author. */
    private function nearDuplicates(array $names)
    {
        $groups = [];
        foreach ($names as $name) {
            $groups[Taxonomy::nameKey($name)][] = $name;
        }
        return array_values(array_filter($groups, function ($group) {
            return count($group) > 1;
        }));
    }
}
