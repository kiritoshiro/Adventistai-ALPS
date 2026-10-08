<?php
/**
 * Checks for App\ScriptLoading (deferred jQuery and plugin scripts) and the
 * Latest Post Slider image priority, with WordPress stubbed.
 * Run: php tests/scripts/run.php
 */

define('ABSPATH', sys_get_temp_dir() . '/alps-scripts-test/');

$failures = 0;
function check($label, $condition)
{
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    $failures += $condition ? 0 : 1;
}

$GLOBALS['actions'] = [];
$GLOBALS['front'] = true;
$GLOBALS['logged_in'] = false;
$GLOBALS['filters'] = [];
function add_action($hook, $callback, $priority = 10) { $GLOBALS['actions'][] = [$hook, $priority, $callback[1]]; }
function apply_filters($name, $value) { return isset($GLOBALS['filters'][$name]) ? $GLOBALS['filters'][$name]($value) : $value; }
function is_admin() { return false; }
function is_front_page() { return $GLOBALS['front']; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }

class Scripts
{
    public $registered = [];
    public $done = [];
    public $data = [];
    public function get_data($handle, $key) { return $this->data[$handle][$key] ?? false; }
    public function add_data($handle, $key, $value) { $this->data[$handle][$key] = $value; return true; }
}
function wp_scripts() { return $GLOBALS['scripts']; }
function site_scripts()
{
    $scripts = new Scripts();
    foreach (['jquery', 'jquery-core', 'jquery-migrate', 'alps-main', 'knygos-front', 'other'] as $handle) {
        $scripts->registered[$handle] = (object) ['handle' => $handle];
    }
    $scripts->data['alps-main']['strategy'] = 'async';
    $scripts->data['other']['strategy'] = 'async';
    return $scripts;
}

require dirname(__DIR__, 2) . '/app/ScriptLoading.php';
require dirname(__DIR__, 2) . '/app/LatestPostSlider.php';
use App\ScriptLoading;
use App\LatestPostSlider;

ScriptLoading::register();
check('runs after every wp_enqueue_scripts callback and before footer scripts are printed', in_array(['wp_enqueue_scripts', PHP_INT_MAX, 'apply'], $GLOBALS['actions'], true) && in_array(['wp_print_footer_scripts', 1, 'apply'], $GLOBALS['actions'], true));

$GLOBALS['scripts'] = site_scripts();
ScriptLoading::apply();
$s = $GLOBALS['scripts'];
check('front page: jQuery and migrate deferred', 'defer' === $s->get_data('jquery-core', 'strategy') && 'defer' === $s->get_data('jquery-migrate', 'strategy'));
check('front page: jQuery printed in the footer', 1 === $s->get_data('jquery', 'group') && 1 === $s->get_data('jquery-core', 'group') && 1 === $s->get_data('jquery-migrate', 'group'));
check('the alias keeps no strategy of its own', false === $s->get_data('jquery', 'strategy'));
check('the ALPS script waits for jQuery (async becomes defer)', 'defer' === $s->get_data('alps-main', 'strategy'));
check('unrelated async scripts are left alone', 'async' === $s->get_data('other', 'strategy'));
check('book showcase script deferred', 'defer' === $s->get_data('knygos-front', 'strategy'));

$s->data['knygos-front']['strategy'] = 'async';
ScriptLoading::apply();
check('a strategy set by the plugin itself is kept', 'async' === $s->get_data('knygos-front', 'strategy'));

$GLOBALS['scripts'] = site_scripts();
$GLOBALS['scripts']->done = ['jquery-core', 'jquery-migrate'];
ScriptLoading::apply();
check('jQuery already printed is not touched', false === $GLOBALS['scripts']->get_data('jquery-core', 'group'));

foreach ([['other pages', false, false], ['logged-in visitors', true, true]] as [$label, $front, $loggedIn]) {
    $GLOBALS['front'] = $front;
    $GLOBALS['logged_in'] = $loggedIn;
    $GLOBALS['scripts'] = site_scripts();
    ScriptLoading::apply();
    $s = $GLOBALS['scripts'];
    check("$label: jQuery unchanged", false === $s->get_data('jquery-core', 'strategy') && false === $s->get_data('jquery-core', 'group') && 'async' === $s->get_data('alps-main', 'strategy'));
    check("$label: book showcase script still deferred", 'defer' === $s->get_data('knygos-front', 'strategy'));
}

$GLOBALS['front'] = false;
$GLOBALS['logged_in'] = false;
$GLOBALS['filters']['adventistai_defer_jquery'] = function () { return true; };
check('the filter can turn deferring on', ScriptLoading::deferJquery());
$GLOBALS['front'] = true;
$GLOBALS['filters']['adventistai_defer_jquery'] = function () { return false; };
check('and off', ! ScriptLoading::deferJquery());

// Slider image priority: the image drawn largest in the first row.
check('16:9 fills the frame', abs(LatestPostSlider::frameShare(1200, 675) - 1.0) < 0.01);
check('3:4 portrait covers about 0.42', abs(LatestPostSlider::frameShare(743, 990) - 0.422) < 0.01);
check('a wide panorama covers less', LatestPostSlider::frameShare(3000, 1000) < 0.6);
check('no size: no share', 0.0 === LatestPostSlider::frameShare(0, 0));
check('portrait first, landscape second: the landscape gets high priority', 1 === LatestPostSlider::priorityIndex([[743, 990], [1200, 675]]));
check('equal shares: the first one', 0 === LatestPostSlider::priorityIndex([[1200, 675], [1920, 1080]]));
check('a slider without an image is skipped', 1 === LatestPostSlider::priorityIndex([null, [640, 940]]));
check('no images: none', -1 === LatestPostSlider::priorityIndex([null, null]));
check('only the first section claims eager images', LatestPostSlider::claimEagerSection() && ! LatestPostSlider::claimEagerSection());

// High priority on wide screens only, through a preload that matches the <picture>.
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES); }
$picture = '<picture class="x"><source type="image/avif" srcset="https://e.test/d-768.avif 768w, https://e.test/d.avif 1200w" sizes="(max-width: 700px) calc(100vw - 34px), 420px">'
    . '<img width="1200" height="675" src="https://e.test/d-fallback.jpg" srcset="https://e.test/d-fallback.jpg 1200w" sizes="(max-width: 700px) calc(100vw - 34px), 420px" loading="eager" fetchpriority="auto"></picture>';
$preload = LatestPostSlider::priorityPreload($picture);
check('preload repeats the AVIF srcset and sizes', '<link rel="preload" as="image" imagesrcset="https://e.test/d-768.avif 768w, https://e.test/d.avif 1200w" imagesizes="(max-width: 700px) calc(100vw - 34px), 420px" type="image/avif" media="(min-width: 1001px)" fetchpriority="high">' === $preload);
check('an image without AVIF: its own srcset, no type', false !== strpos(LatestPostSlider::priorityPreload('<img src="a.jpg" srcset="a.jpg 800w, b.jpg 1200w" sizes="100vw">'), 'imagesrcset="a.jpg 800w, b.jpg 1200w" imagesizes="100vw" media=') && false === strpos(LatestPostSlider::priorityPreload('<img src="a.jpg" srcset="a.jpg 800w">'), 'type='));
check('an image with only src: href', false !== strpos(LatestPostSlider::priorityPreload('<img src="https://e.test/a.jpg?x=1&amp;y=2" alt="">'), 'href="https://e.test/a.jpg?x=1&amp;y=2"'));
check('no image: no preload', '' === LatestPostSlider::priorityPreload('<span>text</span>'));

$template = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/patterns/02-organisms/sections/latest-post-sliders.blade.php');
check('slide images never say fetchpriority="high" themselves', false === strpos($template, "'high'") && false !== strpos($template, 'priorityPreload($thumbnailHtml)'));
check('the template no longer calls the removed per-image claim', false === strpos($template, 'claimEagerImage'));
check('lazy images get no fetchpriority attribute', false !== strpos($template, "(\$loadEager ? ['fetchpriority' => 'auto'] : [])"));

$functions = (string) file_get_contents(dirname(__DIR__, 2) . '/functions.php');
check('functions.php loads and registers ScriptLoading', false !== strpos($functions, "'ScriptLoading'") && false !== strpos($functions, '\App\ScriptLoading::register();'));

$head = (string) file_get_contents(dirname(__DIR__, 2) . '/app/local/alps/js/head-script.min.js');
check('Modernizr touch test reads no layout', false === strpos($head, 'a.join("touch-enabled),(")') && false === strpos($head, 't=9===e.offsetTop'));

echo $failures ? "$failures check(s) failed\n" : "All checks passed\n";
exit($failures ? 1 : 0);
