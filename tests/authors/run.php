<?php
// Author profiles (App\Authors) inside a throwaway WordPress: the migration
// (dry run, real, idempotent), the default author, deleting accounts, the
// locked "Redakcija" account, delete with reassignment, merge, the safety
// net for posts published through REST, the editor's new-post default,
// contributor rights, the order of several authors, and what visitors see
// with the feature off and on. Run by tests/authors/run.py.

require __DIR__ . '/wp-cli-stub.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use App\Authors\Accounts;
use App\Authors\Cli;
use App\Authors\Output;
use App\Authors\Taxonomy;
use App\Fields\Store;

$checks = 0;
$failures = 0;
$ok = function ($condition, $label, $detail = null) use (&$checks, &$failures) {
    $checks++;
    if (! $condition) {
        $failures++;
        echo "FAIL $label" . ($detail !== null ? ' — ' . json_encode($detail, JSON_UNESCAPED_UNICODE) : '') . "\n";
    }
};
$names = function ($post) {
    clean_object_term_cache($post, get_post_type($post));
    return wp_list_pluck(Taxonomy::forPost($post), 'name');
};
$cli = new Cli();
$run = function ($method, $assoc = []) use ($cli) {
    WP_CLI::reset();
    $cli->$method([], $assoc);
    return implode("\n", WP_CLI::$log);
};
$reload = function () {
    Taxonomy::registerTaxonomy();
};

update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules(false);

// Accounts like adventistai.lt's.
$make_user = function ($login, $display, $role, $extra = []) {
    $id = wp_insert_user(array_merge([
        'user_login' => $login, 'user_pass' => wp_generate_password(24), 'user_email' => "$login@example.test",
        'display_name' => $display, 'role' => $role,
    ], $extra));
    if (is_wp_error($id)) {
        throw new Exception($id->get_error_message());
    }
    return $id;
};
$admin = 1;
wp_update_user(['ID' => $admin, 'display_name' => 'Adventistai']);
$vaiva = $make_user('vaiva', 'Vaiva Paldavičienė', 'editor', ['description' => 'Redaktorė.', 'user_url' => 'https://example.test/vaiva']);
update_user_meta($vaiva, 'facebook', 'https://facebook.com/vaiva.test');
$bridge = $make_user('chatgpt-bridge', 'Adventistai.lt', 'editor');
$orgacct = $make_user('adventistai', 'adventistai lt', 'editor');
$mantas = $make_user('mantas', 'Mantas', '');
$test = $make_user('test', 'adventomedia', '');
$jonas = $make_user('jonas', 'Jonas Jonaitis', 'contributor');
$senas = $make_user('senas', 'Senas Autorius', 'author');

$make_post = function ($author, $status = 'publish', $type = 'post', $title = null) {
    $id = wp_insert_post(['post_author' => $author, 'post_status' => $status, 'post_type' => $type, 'post_title' => $title ?: "Post by $author ($status)"]);
    if (is_wp_error($id) || ! $id) {
        throw new Exception('post not created');
    }
    return $id;
};
// The default "Hello world!" post is the admin's.
$p = [
    'vaiva1' => $make_post($vaiva), 'vaiva2' => $make_post($vaiva, 'draft'), 'vaiva_page' => $make_post($vaiva, 'publish', 'page'),
    'bridge' => $make_post($bridge), 'org' => $make_post($orgacct),
    'mantas1' => $make_post($mantas), 'mantas2' => $make_post($mantas, 'private'), 'mantas3' => $make_post($mantas, 'trash'),
    'test' => $make_post($test), 'jonas' => $make_post($jonas, 'pending'), 'admin' => $make_post($admin),
];
// A post whose account was deleted long ago.
$orphan = $make_post($admin, 'publish', 'post', 'Orphan');
$GLOBALS['wpdb']->update($GLOBALS['wpdb']->posts, ['post_author' => 99999], ['ID' => $orphan]);
clean_post_cache($orphan);

// 1. Off: registered, but nothing public, and visitors see the accounts as before.
$tax = get_taxonomy(Taxonomy::NAME);
$ok($tax && ! $tax->public && ! $tax->show_in_rest && ! $tax->rewrite, 'off: taxonomy registered but not public', $tax ? [$tax->public, $tax->show_in_rest] : null);
$ok(in_array('mec-events', Taxonomy::postTypes(), true) === post_type_exists('mec-events') && in_array('page', Taxonomy::postTypes(), true) && ! in_array('attachment', Taxonomy::postTypes(), true), 'post types with authors', Taxonomy::postTypes());
$GLOBALS['post'] = get_post($p['mantas1']);
setup_postdata($GLOBALS['post']);
$ok(get_the_author() === 'Mantas', 'off: the_author is the account name', get_the_author());
$ok(strpos(get_author_posts_url($mantas), '/author/mantas/') !== false, 'off: author link unchanged', get_author_posts_url($mantas));
$ok(Output::widgetAuthor() === null, 'off: author box widget unchanged');

// 2. Dry run changes nothing and reports the plan and the possible duplicates.
$out = $run('migrate', ['dry-run' => true]);
$ok((int) wp_count_terms(['taxonomy' => Taxonomy::NAME, 'hide_empty' => false]) === 0, 'dry run creates no author');
$ok(strpos($out, 'Dry run') !== false, 'dry run says so', $out);
$rows = WP_CLI::$tables[0] ?? [];
$accounts = wp_list_pluck($rows, 'account');
$ok(count(array_intersect(['admin', 'vaiva', 'chatgpt-bridge', 'adventistai', 'mantas', 'test', 'jonas'], $accounts)) === 7, 'dry run lists every account with posts', $accounts);
$ok(in_array('#99999 (deleted)', $accounts, true), 'dry run lists the deleted account', $accounts);
$ok(! in_array('senas', $accounts, true), 'accounts without posts are not listed', $accounts);
$ok((bool) preg_match('/Possibly the same author: .*Adventistai.*Adventistai\.lt/u', $out) && strpos($out, 'adventistai lt') !== false, 'dry run flags the Adventistai near-duplicates', $out);
$ok(strpos($out, 'would be the author made above') !== false, 'dry run: the admin\'s "Adventistai" becomes the default', $out);
$dry_assigned = array_sum(wp_list_pluck($rows, 'posts assigned'));

// 3. Migration.
$out = $run('migrate');
$ok(strpos($out, 'Every post has an author.') !== false, 'migration: every post has an author', $out);
$ok(Taxonomy::countWithoutAuthor(Taxonomy::postTypes()) === 0, 'no post without an author');
$ok(array_sum(wp_list_pluck(WP_CLI::$tables[0], 'posts assigned')) === $dry_assigned, 'dry run and migration assign the same number of posts', [$dry_assigned]);
$terms = get_terms(['taxonomy' => Taxonomy::NAME, 'hide_empty' => false]);
$ok(count($terms) === 7, 'one author per account with posts', wp_list_pluck($terms, 'name'));
$org = Taxonomy::findByName('Adventistai');
$ok($org && (int) get_term_meta($org->term_id, Taxonomy::USER, true) === $admin, '"Adventistai" is the admin\'s author, not a duplicate');
$ok(Taxonomy::defaultAuthor() && Taxonomy::defaultAuthor()->term_id === $org->term_id, 'default author is "Adventistai"');
$vt = Taxonomy::linkedTo($vaiva);
$ok($vt && $vt->slug === 'vaiva-paldaviciene' && $vt->description === 'Redaktorė.', 'author made from the account: slug from the name, bio', $vt);
$ok($vt && get_term_meta($vt->term_id, Taxonomy::URL, true) === 'https://example.test/vaiva' && get_term_meta($vt->term_id, Taxonomy::FACEBOOK, true) === 'https://facebook.com/vaiva.test', 'website and Facebook copied');
$ok(Taxonomy::linkedTo($test)->slug === 'adventomedia', 'slug never a login');
$ok($names($p['vaiva2']) === ['Vaiva Paldavičienė'] && $names($p['vaiva_page']) === ['Vaiva Paldavičienė'], 'drafts and pages get the author');
$ok($names($p['mantas3']) === ['Mantas'] && $names($p['jonas']) === ['Jonas Jonaitis'], 'trashed and pending posts get the author');
$ok($names($orphan) === ['Adventistai'], 'a deleted account\'s post gets the default author', $names($orphan));

// Idempotent.
$count = count($terms);
$out = $run('migrate');
$ok(array_sum(wp_list_pluck(WP_CLI::$tables[0], 'posts assigned')) === 0, 'second run assigns nothing', WP_CLI::$tables[0]);
$ok((int) wp_count_terms(['taxonomy' => Taxonomy::NAME, 'hide_empty' => false]) === $count, 'second run creates no author');
$run('verify');
$ok(WP_CLI::$halted === null, 'verify: every post has an author', WP_CLI::$log);
$run('list_');
$ok(count(WP_CLI::$tables[0]) === $count, 'list shows every author');

// 4. On.
Store::setOption('alps_authors_enabled', 'true');
$reload();
$tax = get_taxonomy(Taxonomy::NAME);
$ok($tax->public && $tax->show_in_rest && $tax->rewrite['slug'] === 'autorius', 'on: public, in REST, /autorius/');
$ok(get_option(Taxonomy::REWRITE_OPTION) === 'autorius', 'rewrite rules rebuilt for the base');
$mt = Taxonomy::linkedTo($mantas);
Taxonomy::update($mt->term_id, ['name' => 'Mantas Petraitis', 'slug' => 'mantas-petraitis', 'description' => 'Bio <b>bold</b>', 'image' => 0, 'url' => '', 'facebook' => '']);
$ok(strpos(get_term_link($mt->term_id, Taxonomy::NAME), '/autorius/mantas-petraitis/') !== false, 'author page address', get_term_link($mt->term_id, Taxonomy::NAME));
$GLOBALS['post'] = get_post($p['mantas1']);
setup_postdata($GLOBALS['post']);
$ok(get_the_author() === 'Mantas Petraitis', 'on: the_author is the author', get_the_author());
$ok(get_the_author_meta('display_name', $mantas) === 'Mantas Petraitis', 'on: the post author\'s display name is the author');
$ok(strpos(get_author_posts_url($mantas), '/autorius/mantas-petraitis/') !== false, 'on: author link is the author page', get_author_posts_url($mantas));
$ok(strpos(get_author_posts_url($senas), '/author/') === false, 'on: no /author/<login>/ link even without an author', get_author_posts_url($senas));
$oembed = get_oembed_response_data($p['mantas1'], 600);
$ok($oembed['author_name'] === 'Mantas Petraitis' && strpos($oembed['author_url'], '/autorius/') !== false, 'oEmbed uses the author', $oembed);

// Several authors keep their order.
Taxonomy::setForPost($p['vaiva1'], [$vt->term_id, $mt->term_id]);
$ok($names($p['vaiva1']) === ['Vaiva Paldavičienė', 'Mantas Petraitis'], 'two authors in order');
Taxonomy::setForPost($p['vaiva1'], [$mt->term_id, $vt->term_id]);
$ok($names($p['vaiva1']) === ['Mantas Petraitis', 'Vaiva Paldavičienė'], 'reordered');
update_object_term_cache([$p['vaiva1']], 'post');
$ok(wp_list_pluck(get_the_terms($p['vaiva1'], Taxonomy::NAME), 'name') === ['Mantas Petraitis', 'Vaiva Paldavičienė'], 'order kept through the primed term cache');
$GLOBALS['post'] = get_post($p['vaiva1']);
setup_postdata($GLOBALS['post']);
$ok(get_the_author() === 'Mantas Petraitis', 'the_author: first author');
$GLOBALS['wp_query']->is_feed = true;
$ok(get_the_author() === 'Mantas Petraitis, Vaiva Paldavičienė', 'RSS dc:creator: all authors', get_the_author());
$GLOBALS['wp_query']->is_feed = false;

// Yoast's schema graph: the account's Person piece is replaced by the authors.
$user_person = 'https://example.test/#/schema/person/abc';
$graph = Output::yoastSchema([
    ['@type' => 'Article', '@id' => 'x#article', 'author' => ['name' => 'Vaiva Paldavičienė', '@id' => $user_person]],
    ['@type' => 'Person', '@id' => $user_person, 'name' => 'vaiva', 'url' => 'https://example.test/author/vaiva/'],
    ['@type' => 'Organization', '@id' => 'https://example.test/#organization'],
], (object) ['post' => get_post($p['vaiva1']), 'site_url' => 'https://example.test/', 'site_represents' => 'organization', 'site_user_id' => 0]);
$json = wp_json_encode($graph);
$ok(strpos($json, $user_person) === false && strpos($json, '/author/vaiva/') === false, 'schema: the account\'s Person is gone', $graph);
$ok(isset($graph[0]['author'][0]['name'], $graph[0]['author'][1]['name']) && $graph[0]['author'][0]['name'] === 'Mantas Petraitis', 'schema: Article author is the two authors in order', $graph[0]);
$ok(count(array_filter($graph, function ($piece) {
    return $piece['@type'] === 'Person' && strpos($piece['url'] ?? '', '/autorius/') !== false;
})) === 2, 'schema: a Person per author with the author page URL', $graph);
$ok(Output::yoastExcludeAuthors([1, 2]) === [], 'Yoast author sitemap emptied');
$ok(Output::yoastMetaAuthor('vaiva') === 'Mantas Petraitis, Vaiva Paldavičienė', 'Yoast meta author');
$ok(Output::yoastTwitter('@someone') === '', 'no twitter:creator from the account');
$ok(Output::commentClass(['comment', 'comment-author-vaiva', 'bypostauthor']) === ['comment', 'bypostauthor'], 'comment classes without the login');

// Author box widget.
$GLOBALS['wp_query'] = new WP_Query(['p' => $p['mantas1']]);
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$widget = Output::widgetAuthor();
$ok(is_array($widget) && $widget['name'] === 'Mantas Petraitis' && strpos($widget['url'], '/autorius/') !== false, 'author box widget reads the author', $widget);

// 5. REST: users hidden from visitors; safety net; new-post default; contributors.
wp_set_current_user(0);
$response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/users'));
$ok($response->get_status() === 401, 'logged out: /wp/v2/users is 401', $response->get_status());
$response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/users/1'));
$ok($response->get_status() === 401, 'logged out: /wp/v2/users/1 is 401', $response->get_status());
wp_set_current_user($admin);
$ok(rest_do_request(new WP_REST_Request('GET', '/wp/v2/users'))->get_status() === 200, 'logged in: users list works');

$rest = function ($method, $route, $params = []) {
    $request = new WP_REST_Request($method, $route);
    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }
    return rest_do_request($request);
};
wp_set_current_user($bridge);
$bt = Taxonomy::linkedTo($bridge);
$created = $rest('POST', '/wp/v2/posts', ['title' => 'WPBridge post', 'status' => 'publish']);
$ok($created->get_status() === 201 && $names($created->get_data()['id']) === [$bt->name], 'REST publish: the account\'s author', [$created->get_status(), $names($created->get_data()['id'] ?? 0)]);
$created = $rest('POST', '/wp/v2/posts', ['title' => 'WPBridge post 2', 'status' => 'publish', Taxonomy::NAME => [$vt->term_id]]);
$ok($names($created->get_data()['id']) === ['Vaiva Paldavičienė'], 'REST publish with an author keeps it');
$created = $rest('POST', '/wp/v2/posts', ['title' => 'WPBridge draft', 'status' => 'draft']);
$ok($names($created->get_data()['id']) === [], 'a draft from REST gets no default');
wp_set_current_user($senas);
$created = $rest('POST', '/wp/v2/posts', ['title' => 'No linked author', 'status' => 'publish']);
$ok($names($created->get_data()['id']) === ['Adventistai'], 'REST publish by an account without an author: the default author', $names($created->get_data()['id'] ?? 0));

// A new post in the editor starts with the account's author, saved on first save.
wp_set_current_user($vaiva);
$draft = wp_insert_post(['post_title' => 'Auto Draft', 'post_status' => 'auto-draft', 'post_author' => $vaiva]);
$response = $rest('GET', "/wp/v2/posts/$draft", ['context' => 'edit']);
$ok(($response->get_data()[Taxonomy::NAME] ?? null) === [$vt->term_id], 'new post shows the account\'s author', $response->get_data()[Taxonomy::NAME] ?? null);
$rest('POST', "/wp/v2/posts/$draft", ['title' => 'First save', 'status' => 'draft']);
$ok($names($draft) === ['Vaiva Paldavičienė'], 'first save keeps that author');
$draft2 = wp_insert_post(['post_title' => 'Auto Draft', 'post_status' => 'auto-draft', 'post_author' => $vaiva]);
$rest('POST', "/wp/v2/posts/$draft2", ['title' => 'Cleared', 'status' => 'draft', Taxonomy::NAME => []]);
$ok($names($draft2) === [], 'an author removed before the first save stays removed');

// Contributors pick from the list but can't add to it.
wp_set_current_user($jonas);
$own = $make_post($jonas, 'draft');
$response = $rest('POST', "/wp/v2/posts/$own", [Taxonomy::NAME => [$vt->term_id, $mt->term_id]]);
$ok($response->get_status() === 200 && $names($own) === ['Vaiva Paldavičienė', 'Mantas Petraitis'], 'contributor chooses authors', [$response->get_status(), $response->get_data()]);
$response = $rest('POST', '/wp/v2/' . Taxonomy::NAME, ['name' => 'Naujas']);
$ok(in_array($response->get_status(), [401, 403], true), 'contributor cannot add an author', $response->get_status());
wp_set_current_user($vaiva);
$response = $rest('POST', '/wp/v2/' . Taxonomy::NAME, ['name' => 'Naujas Autorius', 'description' => 'Trumpai.']);
$ok($response->get_status() === 201, 'editor adds an author from the editor', $response->get_data());
$response = $rest('POST', '/wp/v2/' . Taxonomy::NAME, ['name' => 'Naujas Autorius']);
$ok($response->get_status() === 400 && ($response->get_data()['code'] ?? '') === 'term_exists', 'same name again: term_exists', $response->get_data());
$response = $rest('GET', '/wp/v2/' . Taxonomy::NAME . '/' . $vt->term_id, ['context' => 'edit']);
$ok(! array_key_exists(Taxonomy::USER, (array) ($response->get_data()['meta'] ?? [])), 'the linked account is not in REST', $response->get_data()['meta'] ?? null);

// 6. Deleting accounts keeps the authors; the locked "Redakcija" account.
wp_set_current_user($admin);
$holder_result = Accounts::ensureHolder();
list($holder, $created_holder) = $holder_result;
$ok($created_holder && $holder->user_login === 'redakcija' && $holder->display_name === 'Redakcija' && ! $holder->roles, 'holder account: created without a role', $holder->roles);
list($again, $created_again) = Accounts::ensureHolder();
$ok(! $created_again && $again->ID === $holder->ID, 'holder account: created once');
wp_set_password('known-test-password', $holder->ID);
$ok(is_wp_error(wp_authenticate('redakcija', 'known-test-password')), 'holder cannot log in with a password');
add_filter('application_password_is_api_request', '__return_true');
list($app_password) = WP_Application_Passwords::create_new_application_password($holder->ID, ['name' => 'test']);
$ok(is_wp_error(wp_authenticate('redakcija', $app_password)), 'holder cannot log in with an application password');
remove_filter('application_password_is_api_request', '__return_true');
$ok(! apply_filters('allow_password_reset', true, $holder->ID), 'holder cannot reset its password');
wp_set_password('vaiva-test-password', $vaiva);
$ok(wp_authenticate('vaiva', 'vaiva-test-password') instanceof WP_User, 'other accounts still log in');

$ok(wp_delete_user($mantas, $holder->ID), 'delete Mantas, reassigning to Redakcija');
$ok($names($p['mantas1']) === ['Mantas Petraitis'] && (int) get_post($p['mantas1'])->post_author === $holder->ID, 'Mantas\'s posts keep their author, held by Redakcija');
$ok(! get_term_meta($mt->term_id, Taxonomy::USER, true), 'the author is no longer linked to the deleted account');
$late = $make_post($senas, 'draft');
wp_delete_user($senas, $holder->ID);
$ok($names($late) === ['Senas Autorius'], 'an account without an author: one is made before deleting it', $names($late));
$out = $run('migrate');
$ok(! Taxonomy::findByName('Redakcija') && Taxonomy::countWithoutAuthor(Taxonomy::postTypes()) === 0, 'Redakcija is never an author');
$GLOBALS['post'] = get_post($p['mantas1']);
setup_postdata($GLOBALS['post']);
$ok(get_the_author() === 'Mantas Petraitis', 'byline unchanged after deleting the account');

// 7. Delete with reassignment and merge.
$tt = Taxonomy::linkedTo($test);
$result = Taxonomy::replace($tt->term_id, $vt->term_id);
$ok($result === 1 && $names($p['test']) === ['Vaiva Paldavičienė'] && ! get_term($tt->term_id, Taxonomy::NAME), 'delete with reassignment', $result);
$bt = Taxonomy::linkedTo($bridge);
Taxonomy::setForPost($p['admin'], [$org->term_id, $bt->term_id]);
Taxonomy::setForPost($p['vaiva2'], [$bt->term_id, $vt->term_id]);
$result = Taxonomy::replace($bt->term_id, $org->term_id);
$ok($names($p['bridge']) === ['Adventistai'] && $names($p['admin']) === ['Adventistai'] && $names($p['vaiva2']) === ['Adventistai', 'Vaiva Paldavičienė'], 'merge keeps the place in each list, no duplicates', [$names($p['admin']), $names($p['vaiva2'])]);
$ok(is_wp_error(Taxonomy::replace($org->term_id, $org->term_id)), 'merging an author into itself is refused');

// 8. Off again: visitors see the accounts as before.
Store::setOption('alps_authors_enabled', '');
$reload();
$GLOBALS['post'] = get_post($p['vaiva1']);
setup_postdata($GLOBALS['post']);
$ok(get_the_author() === 'Vaiva Paldavičienė' && strpos(get_author_posts_url($vaiva), '/author/vaiva/') !== false, 'off again: account name and link', [get_the_author(), get_author_posts_url($vaiva)]);
$ok(! get_taxonomy(Taxonomy::NAME)->public && get_option(Taxonomy::REWRITE_OPTION) === '', 'off again: no author pages');

echo "PHP " . PHP_VERSION . ", WordPress " . get_bloginfo('version') . "\n";
echo "authors: $checks checks, $failures failures\n";
