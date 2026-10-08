<?php
/**
 * Checks for App\LogoImage (SVG logo dimensions), App\SecurityHeaders and the
 * late pass of App\InlineStyles, with WordPress stubbed.
 * Run: php tests/styles/head.php
 */

define('ABSPATH', sys_get_temp_dir() . '/alps-head-test/');
define('DAY_IN_SECONDS', 86400);
@mkdir(ABSPATH, 0777, true);

$failures = 0;
function check($label, $condition)
{
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    $failures += $condition ? 0 : 1;
}

$GLOBALS['meta'] = [];
$GLOBALS['files'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['filters'] = [];
$GLOBALS['inlined'] = 0;
function absint($v) { return abs((int) $v); }
function wp_get_attachment_metadata($id) { return $GLOBALS['meta'][$id] ?? false; }
function get_attached_file($id) { return $GLOBALS['files'][$id] ?? ''; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; }
function add_action() {}
function add_filter() {}
function apply_filters($name, $value) { return isset($GLOBALS['filters'][$name]) ? $GLOBALS['filters'][$name]($value) : $value; }
function is_admin() { return false; }
function wp_styles() { return (object) ['queue' => [], 'registered' => [], 'done' => []]; }
function wp_maybe_inline_styles() { $GLOBALS['inlined']++; }

require dirname(__DIR__, 2) . '/app/LogoImage.php';
require dirname(__DIR__, 2) . '/app/SecurityHeaders.php';
require dirname(__DIR__, 2) . '/app/InlineStyles.php';
use App\LogoImage;
use App\SecurityHeaders;
use App\InlineStyles;

function svg($name, $content)
{
    file_put_contents(ABSPATH . $name, $content);
    return ABSPATH . $name;
}

check('viewBox gives the size', [550, 149] === LogoImage::svgSize(svg('a.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 550 149"><g/></svg>')));
check('width/height attributes win over viewBox', [200, 50] === LogoImage::svgSize(svg('b.svg', '<svg width="200px" height="50" viewBox="0 0 400 100"></svg>')));
check('percent sizes fall back to viewBox', [400, 100] === LogoImage::svgSize(svg('c.svg', '<svg width="100%" height="100%" viewBox="0,0,400,100"></svg>')));
check('not an SVG file', null === LogoImage::svgSize(svg('d.png', '<svg viewBox="0 0 1 1"></svg>')));
check('no size at all', null === LogoImage::svgSize(svg('e.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')));

$GLOBALS['files'][7] = ABSPATH . 'a.svg';
check('header logo: size plus LCP hints', ' width="550" height="149" fetchpriority="high" decoding="async"' === LogoImage::attributes(7, true));
check('size is cached', [550, 149] === $GLOBALS['transients']['adventistai_svg_size_7']);
$GLOBALS['meta'][8] = ['width' => 300, 'height' => 80];
check('attachment metadata is used first', ' width="300" height="80"' === LogoImage::attributes(8));
check('unknown attachment: no attributes', '' === LogoImage::attributes(0));

$headers = SecurityHeaders::headers();
check('frame protection and COOP', 'SAMEORIGIN' === $headers['X-Frame-Options'] && "frame-ancestors 'self'" === $headers['Content-Security-Policy'] && 'same-origin-allow-popups' === $headers['Cross-Origin-Opener-Policy']);
check('the CSP holds only the frame rule', false === strpos($headers['Content-Security-Policy'], 'script-src') && false === strpos($headers['Content-Security-Policy'], 'default-src'));
$GLOBALS['filters']['adventistai_security_headers'] = function ($h) { unset($h['X-Frame-Options']); $h['Content-Security-Policy'] = 'frame-ancestors https://partner.example'; return $h; };
$headers = SecurityHeaders::headers();
check('a filter can drop or change headers', ! isset($headers['X-Frame-Options']) && 'frame-ancestors https://partner.example' === $headers['Content-Security-Policy']);

InlineStyles::inlineLateStyles();
check('late pass runs WordPress inlining again', 1 === $GLOBALS['inlined']);

echo $failures ? "$failures check(s) failed\n" : "All checks passed\n";
exit($failures ? 1 : 0);
