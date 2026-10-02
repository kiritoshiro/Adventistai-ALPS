<?php
// Checks that accounts without edit_posts are kept out of wp-admin while
// public AJAX keeps working. WordPress functions are stubbed.

class WP_User
{
    public int $ID;
    public array $caps;

    public function __construct(int $id, array $caps)
    {
        $this->ID = $id;
        $this->caps = $caps;
    }

    public function exists(): bool
    {
        return $this->ID > 0;
    }
}

$current = new WP_User(0, []);
$actions = [];
$ajax = false;

function wp_get_current_user() { global $current; return $current; }
function user_can($user, $cap) { return in_array($cap, $user->caps, true); }
function apply_filters($hook, $value) { return $value; }
function has_action($hook) { global $actions; return isset($actions[$hook]); }
function wp_doing_ajax() { global $ajax; return $ajax; }
function wp_unslash($value) { return $value; }
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function add_action() {}
function add_filter() {}

require __DIR__ . '/../../app/AccountAccess.php';
use App\AccountAccess;

function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }

$checks = 0;
$ok = function ($condition, $message) use (&$checks) { check($condition, $message); $checks++; };

$decide = function () { return AccountAccess::decide(); };

$subscriber = new WP_User(2, ['read']);
$editor = new WP_User(3, ['read', 'edit_posts']);

$ok(!AccountAccess::isRestricted(new WP_User(0, [])), 'visitors are not restricted');
$ok(AccountAccess::isRestricted($subscriber), 'subscribers are restricted');
$ok(!AccountAccess::isRestricted($editor), 'editors are not restricted');

// wp-admin pages.
$current = $subscriber;
$GLOBALS['pagenow'] = 'index.php';
$ok($decide() === 'redirect', 'subscriber is sent home from wp-admin');

$current = $editor;
$ok($decide() === null, 'editor keeps wp-admin');

// admin-ajax.php.
$ajax = true;
$actions = ['wp_ajax_nopriv_public_thing' => true];
$current = $subscriber;
$_REQUEST['action'] = 'public_thing';
$ok($decide() === null, 'subscriber may call a public AJAX action');

$_REQUEST['action'] = 'private_thing';
$ok($decide() === 'deny-ajax', 'subscriber may not call a logged-in-only AJAX action');

$_REQUEST['action'] = 'heartbeat';
$ok($decide() === null, 'heartbeat stays allowed');

$current = $editor;
$_REQUEST['action'] = 'private_thing';
$ok($decide() === null, 'editor may call logged-in-only AJAX actions');

// admin-post.php.
$ajax = false;
$GLOBALS['pagenow'] = 'admin-post.php';
$current = $subscriber;
$_REQUEST['action'] = 'private_form';
$ok($decide() === 'deny-post', 'subscriber may not run a logged-in-only admin-post action');

$actions = ['admin_post_nopriv_public_form' => true];
$_REQUEST['action'] = 'public_form';
$ok($decide() === null, 'subscriber may run a public admin-post action');

// Admin bar and login redirect.
$current = $subscriber;
$ok(AccountAccess::showAdminBar(true) === false, 'no admin bar for subscribers');
$current = $editor;
$ok(AccountAccess::showAdminBar(true) === true, 'admin bar unchanged for editors');
$ok(AccountAccess::loginRedirect('https://example.test/wp-admin/', '', $subscriber) === 'https://example.test/', 'subscriber login lands on the home page');
$ok(AccountAccess::loginRedirect('https://example.test/renginiai/', '', $subscriber) === 'https://example.test/renginiai/', 'a requested front-end page is kept');
$ok(AccountAccess::loginRedirect('https://example.test/wp-admin/', '', $editor) === 'https://example.test/wp-admin/', 'editor login unchanged');

echo "Account access checks passed ({$checks}).\n";
