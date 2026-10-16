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

// WordPress adds "auto" to lazy images; a preload must not repeat it (Chrome took the 1200w file).
$autoPicture = str_replace('sizes="(max', 'sizes="auto, (max', $picture);
check('"auto" is not copied into imagesizes', $preload === LatestPostSlider::priorityPreload($autoPicture));
check('a lone "auto": no imagesizes', false === strpos(LatestPostSlider::priorityPreload('<img src="a.jpg" srcset="a.jpg 800w" sizes="auto">'), 'imagesizes'));
check('withoutAuto keeps sizes without "auto"', '(max-width: 700px) 100vw, 420px' === LatestPostSlider::withoutAuto('(max-width: 700px) 100vw, 420px'));
check('withoutAuto drops a leading "auto" only', '177px' === LatestPostSlider::withoutAuto('AUTO , 177px') && 'auto' === LatestPostSlider::withoutAuto('auto') && '100vw, auto' === LatestPostSlider::withoutAuto('100vw, auto'));
check('slide markup: <source> and <img> keep only the theme sizes', $picture === LatestPostSlider::withoutAutoSizes($autoPicture));
check('slide markup without "auto" is unchanged', $picture === LatestPostSlider::withoutAutoSizes($picture));
check('the preload and the slide then share the same sizes', false !== strpos(LatestPostSlider::withoutAutoSizes($autoPicture), 'sizes="(max-width: 700px) calc(100vw - 34px), 420px"') && false !== strpos(LatestPostSlider::priorityPreload(LatestPostSlider::withoutAutoSizes($autoPicture)), 'imagesizes="(max-width: 700px) calc(100vw - 34px), 420px"'));

// Blade renders the content before the layout's wp_head(): the preload waits for <head>.
$GLOBALS['did_head'] = 0;
function did_action($hook) { return 'wp_head' === $hook ? $GLOBALS['did_head'] : 0; }
check('before wp_head: nothing next to the slide', '' === LatestPostSlider::queuePreload($picture));
ob_start();
LatestPostSlider::printHeadPreload();
check('wp_head prints the queued preload', $preload . "\n" === ob_get_clean());
ob_start();
LatestPostSlider::printHeadPreload();
check('and only once', '' === ob_get_clean());
$GLOBALS['did_head'] = 1;
check('after wp_head: the preload stays next to the slide', $preload === LatestPostSlider::queuePreload($picture));
ob_start();
LatestPostSlider::printHeadPreload();
check('nothing left for <head> then', '' === ob_get_clean());

$template = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/patterns/02-organisms/sections/latest-post-sliders.blade.php');
check('slide images never say fetchpriority="high" themselves', false === strpos($template, "'high'") && false !== strpos($template, 'queuePreload($thumbnailHtml)'));
check('the template no longer calls the removed per-image claim', false === strpos($template, 'claimEagerImage'));
check('slide images carry no fetchpriority attribute (lazy images never get high)', false === strpos($template, "'fetchpriority'"));
check('every slide image is lazy, none eager', false !== strpos($template, "'loading' => 'lazy',") && false === strpos($template, "'eager'"));
check('slide images lose the "auto" WordPress adds, before deferring and the preload', false !== strpos($template, "\$thumbnailHtml = \\App\\LatestPostSlider::withoutAutoSizes(\$thumbnailHtml);\n                      // Hidden slides get their image when they are next in line."));
check('hidden slides are deferred', false !== strpos($template, "if (!\$isActive) {\n                        \$thumbnailHtml = \\App\\DeferredImages::defer(\$thumbnailHtml);"));

// Hidden slides: no file address until the slide is next in line.
require dirname(__DIR__, 2) . '/app/DeferredImages.php';
use App\DeferredImages;
$GLOBALS['feed'] = false;
$GLOBALS['rest'] = false;
function is_feed() { return $GLOBALS['feed']; }
function wp_is_serving_rest_request() { return $GLOBALS['rest']; }
function get_template_directory() { return dirname(__DIR__, 2); }
$GLOBALS['feed'] = true;
check('feeds keep the real files (nothing restores them there)', $picture === DeferredImages::defer($picture));
$GLOBALS['feed'] = false;
$GLOBALS['rest'] = true;
check('REST responses and the editor preview keep the real files', $picture === DeferredImages::defer($picture));
$GLOBALS['rest'] = false;
$GLOBALS['inline'] = [];
function wp_print_inline_script_tag($js) { $GLOBALS['inline'][] = $js; }
DeferredImages::printScript();
check('nothing deferred yet: no restore script', [] === $GLOBALS['inline']);
$deferred = DeferredImages::defer($picture);
check('deferred <source>: srcset moved to data-alps-srcset', false !== strpos($deferred, '<source type="image/avif" data-alps-srcset="https://e.test/d-768.avif 768w, https://e.test/d.avif 1200w" sizes='));
check('deferred <img>: src and srcset moved, blank placeholder first', 0 === strpos(substr($deferred, strpos($deferred, '<img')), '<img src="' . DeferredImages::BLANK . '" width="1200"') && false !== strpos($deferred, ' data-alps-src="https://e.test/d-fallback.jpg" data-alps-srcset="https://e.test/d-fallback.jpg 1200w"'));
check('deferred: no real file address left', 1 === preg_match_all('/\s(src|srcset)="/', $deferred) && false !== strpos($deferred, 'sizes="(max-width: 700px) calc(100vw - 34px), 420px" loading="eager"'));
check('deferred: data-src of a lazy-load plugin is left alone', '<img src="' . DeferredImages::BLANK . '" data-src="a.jpg" data-alps-src="b.jpg">' === DeferredImages::defer('<img data-src="a.jpg" src="b.jpg">'));
check('text without an image is unchanged', '<span>text</span>' === DeferredImages::defer('<span>text</span>'));
DeferredImages::printScript();
$minified = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/slider-images.min.js');
check('a deferred image embeds the minified restore script once', 1 === count($GLOBALS['inline']) && trim($minified) === $GLOBALS['inline'][0] && '' !== trim($minified));
DeferredImages::printScript();
check('and not twice', 1 === count($GLOBALS['inline']));
// node --check (ci.yml) parses it; here: it keeps to the slider's and the card scroller's markup.
$restore = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/slider-images.js');
$sliderJs = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/scripts/latest-post-sliders.js');
foreach (['[data-alps-latest-slider]', '[data-alps-slider-slide]', "'is-active'"] as $hook) {
    check("restore script and slider script share $hook", false !== strpos($restore, $hook) && false !== strpos($sliderJs, $hook));
}
$scrollerJs = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/card-scroller.js');
$scrollerPhp = (string) file_get_contents(dirname(__DIR__, 2) . '/app/CardScrollerBlock.php');
check('restore script, card scroller script and block share [data-card-scroller-track]', false !== strpos($restore, "'[data-card-scroller-track]'") && false !== strpos($scrollerJs, "'[data-card-scroller-track]'") && false !== strpos($scrollerPhp, ' data-card-scroller-track>'));
check('the card scroller defers through DeferredImages', false !== strpos($scrollerPhp, '$card = DeferredImages::defer($card);'));
check('restore script: no syntax newer than the browsers it must run in (no => or ?.)', false === strpos($restore, '=>') && false === strpos($restore, '?.') && 0 === preg_match('/\b(const|let)\s/', $restore));

// Sidebar images: lazy, never high priority, decided before WordPress counts them.
if (! function_exists('add_filter')) { function add_filter($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['actions'][] = [$hook, $priority, $callback[1]]; } }
require dirname(__DIR__, 2) . '/app/SidebarImages.php';
use App\SidebarImages;
SidebarImages::register();
$lazy = ['decoding' => 'async', 'loading' => 'lazy'];
check('sidebar hooks registered (pre_ filter, so no high-priority slot or eager count is used)', in_array(['dynamic_sidebar_before', 10, 'enter'], $GLOBALS['actions'], true) && in_array(['dynamic_sidebar_after', 10, 'leave'], $GLOBALS['actions'], true) && in_array(['pre_wp_get_loading_optimization_attributes', 10, 'attributes'], $GLOBALS['actions'], true));
check('outside a sidebar: WordPress decides', false === SidebarImages::attributes(false, 'img'));
SidebarImages::enter('sidebar-page');
check('side column: lazy', $lazy === SidebarImages::attributes(false, 'img'));
check('side column: iframes left to WordPress', false === SidebarImages::attributes(false, 'iframe'));
SidebarImages::enter('section-page-top');
check('a widget area above the content (nested): WordPress decides', false === SidebarImages::attributes(false, 'img'));
SidebarImages::leave();
check('back in the side column: lazy again', $lazy === SidebarImages::attributes(false, 'img'));
SidebarImages::leave();
SidebarImages::enter('category-top');
check('category header area: WordPress decides', false === SidebarImages::attributes(false, 'img'));
SidebarImages::leave();
check('after the sidebar: WordPress decides', false === SidebarImages::attributes(false, 'img'));
SidebarImages::leave();
check('an extra leave is harmless', false === SidebarImages::attributes(false, 'img'));
SidebarImages::enter('footer-region');
check('footer widgets: lazy', $lazy === SidebarImages::attributes(false, 'img'));
SidebarImages::leave();

// Sidebar images: sizes for the width the area is drawn at.
check('sizes filters registered (image widgets before ImageDelivery copies them, block images)', in_array(['wp_get_attachment_image', 8, 'sizes'], $GLOBALS['actions'], true) && in_array(['wp_content_img_tag', 10, 'sizes'], $GLOBALS['actions'], true));
check('area types', 'side' === SidebarImages::layout('sidebar-page') && 'side' === SidebarImages::layout('sidebar-posts') && 'footer' === SidebarImages::layout('footer-region') && 'footer' === SidebarImages::layout('footer-region-post') && '' === SidebarImages::layout('section-page-top') && '' === SidebarImages::layout('category-top') && '' === SidebarImages::layout('section-page-bottom'));
$side = '(max-width: 700px) calc(100vw - 32px), (max-width: 1000px) 81.31vw, (max-width: 1272px) 28.17vw, (max-width: 1680px) calc(32.57vw - 56px), 492px';
check('side column: full width less the gutters on phones and tablets, 38% of the content column on desktop', $side === SidebarImages::areaSizes('side'));
check('side column, a 1280px image: no cap reached', $side === SidebarImages::areaSizes('side', 1280));
check('side column, a 300px image: never wider than the image', '(max-width: 332px) calc(100vw - 32px), (max-width: 1000px) 300px, (max-width: 1064px) 28.17vw, 300px' === SidebarImages::areaSizes('side', 300));
check('side column, a 400px image', '(max-width: 432px) calc(100vw - 32px), (max-width: 1000px) 400px, (max-width: 1272px) 28.17vw, (max-width: 1400px) calc(32.57vw - 56px), 400px' === SidebarImages::areaSizes('side', 400));
$footer = '(max-width: 700px) calc(100vw - 32px), (max-width: 1272px) 81.31vw, (max-width: 1680px) calc(85.71vw - 56px), 1384px';
check('footer: the content column less the gutters, up to the 90rem measure', $footer === SidebarImages::areaSizes('footer') && $footer === SidebarImages::areaSizes('footer', 2000));
check('unknown layout: nothing', '' === SidebarImages::areaSizes('other'));

$widget = '<img width="1280" height="640" src="w.jpg" srcset="w-768.jpg 768w, w.jpg 1280w" sizes="auto, (max-width: 1280px) 100vw, 1280px" loading="lazy">';
check('outside a sidebar: sizes unchanged', $widget === SidebarImages::sizes($widget));
SidebarImages::enter('sidebar-page');
$sized = SidebarImages::sizes($widget);
check('side column image widget: area sizes, no "auto"', str_replace('sizes="auto, (max-width: 1280px) 100vw, 1280px"', 'sizes="' . $side . '"', $widget) === $sized);
check('sizes set on purpose are kept', '<img sizes="(max-width: 600px) 50vw, 300px">' === SidebarImages::sizes('<img sizes="(max-width: 600px) 50vw, 300px">'));
check('mismatched default-like sizes are kept', '<img sizes="(max-width: 900px) 100vw, 300px">' === SidebarImages::sizes('<img sizes="(max-width: 900px) 100vw, 300px">'));
check('non-strings pass through', null === SidebarImages::sizes(null));
$pictureWidget = '<picture><source type="image/avif" srcset="a.avif 1892w" sizes="(max-width: 1000px) 100vw, 1000px"><img sizes="(max-width: 1000px) 100vw, 1000px" src="a.jpg"></picture>';
check('a <picture> made earlier: <source> and <img> both changed', 2 === substr_count(SidebarImages::sizes($pictureWidget), 'sizes="' . $side . '"'));
SidebarImages::enter('section-page-top');
check('a widget area above the content (nested): sizes left to WordPress', $widget === SidebarImages::sizes($widget));
SidebarImages::leave();
check('back in the side column: area sizes again', $sized === SidebarImages::sizes($widget));
SidebarImages::enter('footer-region-post');
check('footer area nested in the side column: footer sizes, capped at the 1280px image', false !== strpos(SidebarImages::sizes($widget), 'sizes="(max-width: 700px) calc(100vw - 32px), (max-width: 1272px) 81.31vw, (max-width: 1558px) calc(85.71vw - 56px), 1280px"'));
SidebarImages::leave();
SidebarImages::leave();
SidebarImages::enter('category-top');
check('category header area: sizes left to WordPress', $widget === SidebarImages::sizes($widget));
SidebarImages::leave();
SidebarImages::enter('category-bottom');
check('an area inside the article: sizes left to WordPress', $widget === SidebarImages::sizes($widget));
SidebarImages::leave();

// ImageDelivery gives the AVIF <source> the <img>'s sizes.
$uploads = sys_get_temp_dir() . '/alps-scripts-uploads-' . getmypid();
@mkdir($uploads);
foreach (['w.avif', 'w-small.avif', 'w-fallback.jpg'] as $file) {
    file_put_contents("$uploads/$file", 'x');
}
function get_post_meta($id, $key, $single) { return 'w' === $key ? '' : ['avif_full' => 'w.avif', 'avif_full_width' => 1892, 'avif_full_height' => 946, 'avif_small' => 'w-small.avif', 'avif_small_width' => 768, 'jpeg' => 'w-fallback.jpg', 'jpeg_width' => 1200]; }
function wp_upload_dir($time = null, $create = true) { return ['basedir' => $GLOBALS['uploads'], 'baseurl' => 'https://e.test/uploads']; }
function trailingslashit($value) { return rtrim($value, '/\\') . '/'; }
function wp_get_attachment_metadata($id) { return ['width' => 1892, 'height' => 946]; }
require dirname(__DIR__, 2) . '/app/ImageDelivery.php';
$wrapped = App\ImageDelivery::filterImage($sized, 5, [400, 200]);
check('AVIF <source> takes the <img> sizes, also for an image widget with a custom size', 1 === preg_match('/<source type="image\/avif" srcset="[^"]*w-small\.avif 768w[^"]*" sizes="([^"]*)">/', $wrapped, $source) && $side === html_entity_decode($source[1], ENT_QUOTES));
check('without sizes on the <img>, a custom size still gives the <source> its width', false !== strpos(App\ImageDelivery::filterImage('<img src="w.jpg" width="400">', 5, [400, 200]), 'sizes="(max-width: 400px) 100vw, 400px"'));
array_map('unlink', glob("$uploads/*"));
@rmdir($uploads);

$functions = (string) file_get_contents(dirname(__DIR__, 2) . '/functions.php');
check('functions.php loads and registers ScriptLoading', false !== strpos($functions, "'ScriptLoading'") && false !== strpos($functions, '\App\ScriptLoading::register();'));
check('functions.php prints the slider preload early in <head>', false !== strpos($functions, "add_action('wp_head', [\\App\\LatestPostSlider::class, 'printHeadPreload'], 2);"));
check('functions.php prints the deferred images script in the footer and registers SidebarImages', false !== strpos($functions, "add_action('wp_footer', [\\App\\DeferredImages::class, 'printScript'], 20);") && false !== strpos($functions, '\App\SidebarImages::register();'));

$head = (string) file_get_contents(dirname(__DIR__, 2) . '/app/local/alps/js/head-script.min.js');
check('Modernizr touch test reads no layout', false === strpos($head, 'a.join("touch-enabled),(")') && false === strpos($head, 't=9===e.offsetTop'));

echo $failures ? "$failures check(s) failed\n" : "All checks passed\n";
exit($failures ? 1 : 0);
