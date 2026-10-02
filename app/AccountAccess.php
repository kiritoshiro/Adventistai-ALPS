<?php

namespace App;

/**
 * Keeps accounts that cannot edit posts (subscribers and similar roles) out of
 * wp-admin and hides the admin bar for them.
 *
 * admin-ajax.php and admin-post.php stay reachable because front-end features
 * post to them. Those accounts may only run actions that logged-out visitors
 * can run too (a `nopriv` handler exists), so a plugin handler that forgets
 * its own capability check is not exposed to every registered account.
 */
final class AccountAccess
{
    public static function register(): void
    {
        add_action('admin_init', [self::class, 'guard'], 0);
        // Core denies pages the role cannot open before admin_init runs.
        add_action('admin_page_access_denied', [self::class, 'guard'], 0);
        add_filter('show_admin_bar', [self::class, 'showAdminBar']);
        add_filter('login_redirect', [self::class, 'loginRedirect'], 20, 3);
    }

    public static function isRestricted($user = null): bool
    {
        $user = $user instanceof \WP_User ? $user : wp_get_current_user();

        if (!$user instanceof \WP_User || !$user->exists() || user_can($user, 'edit_posts')) {
            return false;
        }

        return (bool) apply_filters('adventistai_restrict_admin_access', true, $user);
    }

    public static function guard(): void
    {
        switch (self::decide()) {
            case 'deny-ajax':
                wp_die('-1', '', ['response' => 403]);
                break;
            case 'deny-post':
                wp_die(esc_html__('Sorry, you are not allowed to do that.', 'alps'), '', ['response' => 403]);
                break;
            case 'redirect':
                wp_safe_redirect(home_url('/'));
                exit;
        }
    }

    /**
     * @return string|null 'redirect', 'deny-ajax', 'deny-post', or null to allow the request.
     */
    public static function decide(): ?string
    {
        if (!self::isRestricted()) {
            return null;
        }

        if (wp_doing_ajax()) {
            return self::publicAction('wp_ajax_nopriv_') ? null : 'deny-ajax';
        }

        if (($GLOBALS['pagenow'] ?? '') === 'admin-post.php') {
            return self::publicAction('admin_post_nopriv_') ? null : 'deny-post';
        }

        return 'redirect';
    }

    public static function showAdminBar($show)
    {
        return self::isRestricted() ? false : $show;
    }

    public static function loginRedirect($redirect_to, $requested, $user)
    {
        if (!self::isRestricted($user)) {
            return $redirect_to;
        }

        // Keep a requested front-end page; send everything aimed at wp-admin home.
        return strpos((string) $redirect_to, admin_url()) === 0 ? home_url('/') : $redirect_to;
    }

    private static function publicAction(string $prefix): bool
    {
        // Match the name exactly as admin-ajax.php and admin-post.php dispatch it.
        $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? wp_unslash($_REQUEST['action']) : '';
        if ($action === '') {
            return true;
        }

        $allowed = (array) apply_filters('adventistai_restricted_account_actions', ['heartbeat']);

        return has_action($prefix . $action) || in_array($action, $allowed, true);
    }
}
