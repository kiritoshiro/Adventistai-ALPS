<?php
// Security regressions inside a throwaway WordPress (assessment 2026-10-27):
// link fields refuse javascript: URLs and their templates escape stored ones;
// password-protected text stays out of the Latest Post Slider, Related Pages
// and the theme's own SEO description; the search rate limit trusts
// CF-Connecting-IP only from Cloudflare. Run by tests/security/run.py.

use App\Fields\Store;
use App\LatestPostSliderBlock;
use App\SeoDefaults;

$checks = 0;
$failures = 0;
$ok = function ($condition, $label) use (&$checks, &$failures) {
    $checks++;
    if (! $condition) {
        $failures++;
    }
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
};
$rest = function ($id, array $fields) {
    $request = new WP_REST_Request('POST', "/wp/v2/posts/$id");
    $request->set_param('alps_fields', $fields);
    return rest_do_request($request);
};
$hero = function ($id) {
    // get_alps_field() reads the queried entry.
    $GLOBALS['wp_query'] = new WP_Query(['p' => $id, 'post_status' => 'any']);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    $GLOBALS['post'] = get_post($id);
    setup_postdata($GLOBALS['post']);
    return view('patterns.02-organisms.sections.page-header-hero', ['post' => get_post($id)])->render();
};

do_action('rest_api_init');
$thumb = (int) (get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids'])[0] ?? 0);
$ok($thumb > 0, 'fixture image exists');

// 1. Hero and slide links.
$contributor = wp_insert_user(['user_login' => 'sec-contributor', 'user_pass' => wp_generate_password(), 'role' => 'contributor']);
$admin = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids'])[0] ?? 0);
$draft = wp_insert_post(['post_title' => 'Hero links', 'post_status' => 'draft', 'post_author' => $contributor]);
$hero_fields = ['hero_type' => 'default', 'hero_image' => (string) $thumb, 'hero_title' => 'Hero', 'hero_link_button' => 'Go'];

wp_set_current_user($contributor);
$ok(! current_user_can('unfiltered_html'), 'contributor has no unfiltered HTML');
foreach (['javascript:alert(1)', ' JaVaScRiPt:alert(1)', "java\tscript:alert(1)", 'javascript&#58;alert(1)', 'data:text/html,<script>alert(1)</script>', 'vbscript:x'] as $i => $bad) {
    $response = $rest($draft, $hero_fields + ['hero_link_url' => $bad]);
    $ok($response->get_status() === 200 && Store::post($draft, 'hero_link_url') === '', "contributor hero_link_url #$i is not stored");
}
$rest($draft, ['hero_type' => 'carousel', 'hero_carousel' => [['slide_image' => (string) $thumb, 'slide_heading' => 'S', 'slide_dek' => 'D', 'slide_url' => 'javascript:alert(1)', 'slide_cta' => 'Go']]]);
$ok((Store::post($draft, 'hero_carousel')[0]['slide_url'] ?? null) === '', 'contributor slide_url is not stored');
foreach (['https://example.test/a?b=1&c=2', '/kontaktai/', '#apie', 'mailto:info@example.test', 'Text hero_link_url'] as $i => $good) {
    $rest($draft, $hero_fields + ['hero_link_url' => $good]);
    $ok(Store::post($draft, 'hero_link_url') === wp_kses_post($good), "safe hero_link_url #$i is kept");
}

wp_set_current_user($admin);
$ok(current_user_can('unfiltered_html') || is_multisite(), 'administrator has unfiltered HTML');
$rest($draft, $hero_fields + ['hero_link_url' => 'javascript:alert(1)']);
$ok(Store::post($draft, 'hero_link_url') === '', 'administrator hero_link_url refuses javascript: too');

// Values stored before this fix are escaped where they are printed.
Store::setPost($draft, 'hero_type', 'default');
Store::setPost($draft, 'hero_link_url', 'javascript:alert(1)');
$html = $hero($draft);
$ok(str_contains($html, 'c-block__button') && stripos($html, 'javascript:') === false, 'stored javascript: hero link is not printed');
Store::setPost($draft, 'hero_link_url', 'https://example.test/a?b=1&c=2');
$ok(str_contains($hero($draft), 'href="https://example.test/a?b=1&#038;c=2"'), 'safe hero link is printed once-escaped');
Store::setPost($draft, 'hero_type', 'column');
Store::setPost($draft, 'hero_column', array_fill(0, 3, ['_type' => '_', 'hero_image_column' => (string) $thumb, 'hero_title_column' => 'C', 'hero_kicker_column' => 'K', 'hero_link_url' => 'javascript:alert(1)']));
$html = $hero($draft);
$ok(substr_count($html, 'c-block__title-link') === 3 && stripos($html, 'javascript:') === false, 'stored javascript: column links are not printed');
Store::setPost($draft, 'hero_type', 'carousel');
Store::setPost($draft, 'hero_carousel', [['_type' => '_', 'slide_image' => (string) $thumb, 'slide_heading' => 'S', 'slide_dek' => 'D', 'slide_url' => 'javascript:alert(1)', 'slide_cta' => 'Go']]);
$html = $hero($draft);
$ok(str_contains($html, 'c-carousel__item-cta') && stripos($html, 'javascript:') === false, 'stored javascript: slide link is not printed');

// 2. Password-protected text, for a logged-out visitor.
wp_set_current_user(0);
$secret = 'ALPS_PASSWORD_SECRET_73019';
$open_text = 'ALPS_OPEN_TEXT_51122';
$protected = wp_insert_post(['post_title' => 'Protected post', 'post_status' => 'publish', 'post_password' => 'pw', 'post_content' => "$secret Behind the password form."]);
$open = wp_insert_post(['post_title' => 'Open post', 'post_status' => 'publish', 'post_content' => "$open_text Anyone may read this."]);
$ok(post_password_required($protected), 'core requires the password');

$slider = LatestPostSliderBlock::render(['modules' => [['source' => 'custom', 'items' => [$protected, $open], 'title' => 'Custom']]]);
$ok(! str_contains($slider, $secret) && ! str_contains($slider, 'Protected post'), 'custom slider leaves the protected post out');
$ok(str_contains($slider, $open_text), 'custom slider still shows an open post');
$slider = LatestPostSliderBlock::render(['modules' => [['source' => 'latest', 'count' => 20, 'title' => 'Latest']]]);
$ok(! str_contains($slider, $secret) && str_contains($slider, $open_text), 'latest slider leaves the protected post out');

$parent = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Parent']);
$child_secret = 'ALPS_CHILD_SECRET_91240';
$child = wp_insert_post(['post_type' => 'page', 'post_parent' => $parent, 'post_status' => 'publish', 'post_password' => 'pw', 'post_title' => 'Protected child', 'post_content' => "$child_secret Private."]);
$sibling = wp_insert_post(['post_type' => 'page', 'post_parent' => $parent, 'post_status' => 'publish', 'post_title' => 'Open child', 'post_content' => "$open_text Open child."]);
$related = function ($mode) use ($parent) {
    Store::setPost($parent, 'related', $mode);
    $GLOBALS['post'] = get_post($parent);
    setup_postdata($GLOBALS['post']);
    return view('patterns.02-organisms.sections.related-pages', ['post' => get_post($parent)])->render();
};
$html = $related('related_top_level');
$ok(! str_contains($html, $child_secret) && str_contains($html, 'Protected child'), 'related pages list the protected child without its text');
$ok(str_contains($html, $open_text), 'related pages still show an open child\'s text');
Store::setPost($parent, 'related_custom_value', [['type' => 'post', 'subtype' => 'page', 'id' => $child], ['type' => 'post', 'subtype' => 'post', 'id' => $protected]]);
$html = $related('related_custom');
$ok(! str_contains($html, $child_secret) && ! str_contains($html, $secret), 'custom related pages show no protected text');

$seo = function ($id) {
    $GLOBALS['wp_query'] = new WP_Query(['p' => $id, 'post_type' => 'post']);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    $GLOBALS['post'] = get_post($id);
    setup_postdata($GLOBALS['post']);
    (new ReflectionProperty(SeoDefaults::class, 'metadata'))->setValue(null, null);
    ob_start();
    SeoDefaults::render();
    return ob_get_clean();
};
$ok(! str_contains($seo($protected), $secret), 'theme SEO description leaves protected text out');
$ok(str_contains($seo($open), $open_text), 'theme SEO description still uses an open post\'s text');

// 3. Search rate limit.
$limit = new ReflectionMethod(Adv_Search_REST::class, 'within_rate_limit');
$try = function ($remote, $header) use ($limit) {
    $_SERVER['REMOTE_ADDR'] = $remote;
    if ($header === null) {
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
    } else {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = $header;
    }
    return $limit->invoke(null, 1, 10);
};
$ok($try('198.51.100.77', '198.51.100.11') && ! $try('198.51.100.77', '198.51.100.12'), 'direct request: a new CF-Connecting-IP does not reset the bucket');
$ok($try('162.158.1.1', '203.0.113.5') && ! $try('162.158.200.9', '203.0.113.5'), 'via Cloudflare (IPv4): the visitor address is the bucket');
$ok($try('162.158.1.1', '203.0.113.6'), 'via Cloudflare: another visitor has their own bucket');
$ok($try('2606:4700::1', '2001:db8::5') && ! $try('2606:4700:10::2', '2001:db8::5'), 'via Cloudflare (IPv6): the visitor address is the bucket');
$ok($try('162.158.1.1', 'not-an-ip') && ! $try('162.158.1.1', null), 'an invalid header falls back to the connecting address');
unset($_SERVER['HTTP_CF_CONNECTING_IP']);

echo "security: $checks checks, $failures failures\n";
