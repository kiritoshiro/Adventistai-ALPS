<?php

namespace App\Authors;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Accounts and authors: deleting an account keeps its posts' author, and the
 * locked "Redakcija" account holds the posts of deleted accounts.
 *
 * WordPress gives a deleted account's posts to another account (or deletes
 * them). Before it does, delete_user gives those posts the account's author,
 * so the byline, which comes from the author, stays the same.
 *
 * The holder account (`wp alps authors holder-account`) has no role, a random
 * password nobody knows and `alps_login_disabled`: it can't log in, with a
 * password or an application password, and can't reset its password. It is
 * never offered as an author.
 */
class Accounts
{
    const LOGIN_DISABLED = 'alps_login_disabled';
    const HOLDER_LOGIN = 'redakcija';

    public static function register()
    {
        add_action('delete_user', [__CLASS__, 'beforeDelete'], 5, 2);
        add_filter('authenticate', [__CLASS__, 'refuseLogin'], 100);
        add_action('wp_authenticate_application_password_errors', [__CLASS__, 'refuseApplicationPassword'], 10, 2);
        add_filter('allow_password_reset', [__CLASS__, 'allowPasswordReset'], 10, 2);
    }

    public static function isLocked($user)
    {
        $id = $user instanceof \WP_User ? $user->ID : (int) $user;
        return $id > 0 && (bool) get_user_meta($id, self::LOGIN_DISABLED, true);
    }

    /** The holder account, if it was created. */
    public static function holder()
    {
        $user = get_user_by('login', self::HOLDER_LOGIN);
        return $user instanceof \WP_User && self::isLocked($user) ? $user : null;
    }

    /**
     * Create the holder account once. Returns [user, created] or a WP_Error.
     * The password is random and never shown or stored anywhere else.
     */
    public static function ensureHolder()
    {
        $existing = get_user_by('login', self::HOLDER_LOGIN);
        if ($existing) {
            if (! self::isLocked($existing)) {
                return new \WP_Error('alps_holder_exists', __('An account with the login "redakcija" already exists and is not locked; it was left unchanged.', 'alps'));
            }
            return [$existing, false];
        }
        $id = wp_insert_user([
            'user_login' => self::HOLDER_LOGIN,
            'user_pass' => wp_generate_password(64, true, true),
            'user_email' => '',
            'display_name' => 'Redakcija',
            'nickname' => 'Redakcija',
            'role' => '',
        ]);
        if (is_wp_error($id)) {
            return $id;
        }
        update_user_meta($id, self::LOGIN_DISABLED, 1);
        return [get_userdata($id), true];
    }

    /** Posts the holder account holds (any status). */
    public static function holderPostCount()
    {
        $holder = self::holder();
        if (! $holder) {
            return 0;
        }
        $count = 0;
        foreach (Taxonomy::postTypes() as $type) {
            $count += (int) count_user_posts($holder->ID, $type, false);
        }
        return $count;
    }

    /**
     * Runs before WordPress reassigns or deletes an account's posts: they get
     * the account's author (made from the account if the site uses authors
     * and it has none), and the author is no longer linked to the account.
     */
    public static function beforeDelete($user_id, $reassign = null)
    {
        $user = get_userdata($user_id);
        if (! $user || self::isLocked($user) || ! taxonomy_exists(Taxonomy::NAME)) {
            return;
        }
        $term = Taxonomy::linkedTo($user->ID);
        // Posts that are kept (reassigned) and have no author get one made from the account.
        if (! $term && $reassign && Taxonomy::enabled() && Taxonomy::postsWithoutAuthor($user->ID, Taxonomy::postTypes(), 1)) {
            list($term) = Taxonomy::ensureForUser($user);
        }
        if (! $term instanceof \WP_Term) {
            return;
        }
        Taxonomy::assignUserPosts($user->ID, $term->term_id);
        delete_term_meta($term->term_id, Taxonomy::USER);
    }

    /** No login for locked accounts (password form, XML-RPC, application passwords). */
    public static function refuseLogin($user)
    {
        if ($user instanceof \WP_User && self::isLocked($user)) {
            return new \WP_Error('alps_login_disabled', __('This account cannot log in.', 'alps'));
        }
        return $user;
    }

    public static function refuseApplicationPassword($error, $user)
    {
        if ($error instanceof \WP_Error && self::isLocked($user)) {
            $error->add('alps_login_disabled', __('This account cannot log in.', 'alps'));
        }
    }

    public static function allowPasswordReset($allow, $user_id)
    {
        return self::isLocked($user_id) ? false : $allow;
    }
}
