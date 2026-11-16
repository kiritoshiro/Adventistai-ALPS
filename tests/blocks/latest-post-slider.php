<?php
/**
 * Latest Post Slider: the v4 card look comes only with "Kortelių išdėstymas"
 * (block attribute cardLayout). Renders the real Blade template with
 * Illuminate's view factory and WordPress stubbed, once with cardLayout
 * missing, false and true, and checks that the stylesheet keeps every v4
 * card rule behind the card class.
 * Run: php tests/blocks/latest-post-slider.php (needs composer install).
 */

$failures = 0;
function check($label, $condition)
{
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    $failures += $condition ? 0 : 1;
}

// WordPress, stubbed. Defined before the autoloader so these win.
function __($text, $domain = '') { return $text; }
function absint($value) { return abs((int) $value); }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key)); }
function wp_strip_all_tags($text) { return trim(strip_tags((string) $text)); }
function esc_url($url, $protocols = null) { return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function is_wp_error($thing) { return false; }
function get_category_link($id) { return 'https://example.test/category/' . $id . '/'; }
function get_term($id, $taxonomy) { return (object) ['name' => 'Trijų angelų žinia']; }
function wp_unique_id($prefix) { static $n = 0; return $prefix . (++$n); }
function get_the_title($id) { return $GLOBALS['posts'][$id]['title']; }
function get_permalink($id) { return 'https://example.test/p' . $id . '/'; }
function get_post_thumbnail_id($id) { return $GLOBALS['posts'][$id]['thumb']; }
function get_the_excerpt($post) { return 'Ištrauka apie įrašą ' . $post->ID . '.'; }
function has_excerpt($id) { return true; }
function strip_shortcodes($text) { return $text; }
function get_post_field($field, $id) { return 'Turinys.'; }
function wp_trim_words($text, $words, $more) { return $text; }
function get_post_meta($id, $key, $single) { return ''; }
function wp_get_attachment_image_src($id, $size) { return $GLOBALS['images'][$id] ?? false; }
function get_the_post_thumbnail($id, $size, $attr)
{
    return '<img class="' . $attr['class'] . '" alt="' . $attr['alt'] . '" sizes="' . $attr['sizes'] . '" src="i' . $id . '.jpg">';
}

// The theme's helpers around the template, stubbed. imageSizes() shows the
// width and height it was given and whether it was asked for a card, so the
// test sees what the template passes; tests/scripts/run.php checks the real
// imageSizes() values.
require dirname(__DIR__, 2) . '/app/ImageDelivery.php';
eval('namespace App; class LatestPostSlider {
    public static function posts(array $module): array { return array_map(fn ($id) => (object) ["ID" => $id], $module["ids"]); }
    public static function claimEagerSection(): bool { return false; }
    public static function priorityIndex(array $sizes): int { return -1; }
    public static function associationIds($items): array { return (array) $items; }
    public static function imageSizes(int $columns, int $width, int $height, bool $card = false): string { return "sizes-" . $width . "x" . $height . ($card ? "-card" : ""); }
    public static function withoutAutoSizes(string $html): string { return $html; }
    public static function queuePreload(string $html): string { return ""; }
}
class ContentHelpers { public static function categoryName(int $id): string { return $GLOBALS["posts"][$id]["category"]; } }
class DeferredImages { public static function defer($html) { return $html; } }');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;

$cache = sys_get_temp_dir() . '/alps-slider-views-' . getmypid();
@mkdir($cache, 0777, true);
$files = new Filesystem();
$resolver = new EngineResolver();
$resolver->register('blade', fn () => new CompilerEngine(new BladeCompiler($files, $cache), $files));
$finder = new FileViewFinder($files, [dirname(__DIR__, 2) . '/resources/views']);
$finder->addExtension('blade.php');
$factory = new Factory($resolver, $finder, new Dispatcher(new Container()));
$factory->addExtension('blade.php', 'blade');

// Post 1: a 3:2 image (close to 16:9), post 2: a portrait image, post 3: no image.
$GLOBALS['posts'] = [
    1 => ['title' => 'Elenos G. Vait raštų autoritetas', 'thumb' => 11, 'category' => 'Teologiniai straipsniai'],
    2 => ['title' => 'Marijos sūnaus gyvenimas', 'thumb' => 12, 'category' => 'Mūsų naujienos'],
    3 => ['title' => 'Dėmesys į laiko ženklus', 'thumb' => 0, 'category' => 'Trijų angelų žinia'],
];
$GLOBALS['images'] = [11 => ['i11.jpg', 1200, 800], 12 => ['i12.jpg', 640, 940]];

$render = function (?bool $cardLayout, array $ids, string $source = 'latest') use ($factory) {
    $data = [
        'configuredSliderModules' => [[
            'ids' => $ids,
            'alps_latest_slider_source' => $source,
            'alps_latest_slider_category' => [7],
            'alps_latest_slider_interval' => 5,
            'alps_latest_slider_autoplay' => true,
            'alps_latest_slider_excerpt_lines' => 0,
        ]],
        'sliderColumns' => 3,
        'sectionAttributes' => '',
    ];
    if ($cardLayout !== null) {
        $data['cardLayout'] = $cardLayout;
    }
    return $factory->make('patterns.02-organisms.sections.latest-post-sliders', $data)->render();
};
// Unique ids differ per render; compare without them.
$normal = fn (string $html) => preg_replace('/alps-latest-slider-\d+/', 'alps-latest-slider-N', $html);

foreach (['latest' => [1, 2, 3], 'category' => [3, 1]] as $source => $ids) {
    $missing = $render(null, $ids, $source);
    $off = $render(false, $ids, $source);
    $on = $render(true, $ids, $source);

    check("$source: cardLayout missing and false render the same markup", $normal($missing) === $normal($off));
    check("$source: without cardLayout no card class, panel, cover frame or filled-frame sizes",
        false === strpos($off, 'alps-latest-slider--card')
        && false === strpos($off, '__image-frame--panel')
        && false === strpos($off, '__panel-label')
        && false === strpos($off, '__image-frame--cover')
        && false === strpos($off, 'sizes-16x9'));
    check("$source: without cardLayout the heading is printed and images keep their own shape in sizes",
        false !== strpos($off, 'alps-latest-slider__heading') && false !== strpos($off, 'sizes="sizes-1200x800"') && false === strpos($off, '-card"'));
    check("$source: with cardLayout the module carries the card class", false !== strpos($on, 'class="alps-latest-slider alps-latest-slider--short-text alps-latest-slider--card"'));
    check("$source: with cardLayout a post without an image gets the Denim panel with its category",
        1 === preg_match('~<div class="alps-latest-slider__image-frame alps-latest-slider__image-frame--panel" aria-hidden="true">\s*<span class="alps-latest-slider__panel-label">Trijų angelų žinia</span>~', $on));
    check("$source: with cardLayout and no typed title there is no heading", false === strpos($on, 'alps-latest-slider__heading'));
}

$off = $render(false, [1, 2, 3]);
$on = $render(true, [1, 2, 3]);
check('without cardLayout a slide without an image has no image frame (as before)', 2 === substr_count($off, 'class="alps-latest-slider__image-frame'));
check('with cardLayout an image close to 16:9 fills the frame and is sized for the full frame',
    false !== strpos($on, '<div class="alps-latest-slider__image-frame alps-latest-slider__image-frame--cover">') && false !== strpos($on, 'sizes="sizes-16x9-card"'));
check('with cardLayout a portrait image is shown whole and keeps its own shape in sizes',
    false !== strpos($on, 'sizes="sizes-640x940-card"') && 1 === substr_count($on, '__image-frame--cover'));
check('with cardLayout three lines of text unless set', false !== strpos($on, 'style="--alps-slider-excerpt-lines: 3;"') && false === strpos($off, '--alps-slider-excerpt-lines'));

$categoryOff = $render(false, [3, 1], 'category');
$categoryOn = $render(true, [3, 1], 'category');
check('a category slider without cardLayout still leaves the category out (3.39.0)', false === strpos($categoryOff, 'alps-latest-slider__meta'));
check('a category module with cardLayout shows the category above the title', false !== strpos($categoryOn, '<span class="alps-latest-slider__meta">Teologiniai straipsniai</span>'));

// The stylesheet: every rule that sets a v4 card value, or styles a class
// only card layout prints, applies only under the card class.
$v4 = '/#e3e6ec|#eef1f5|#2f557f|#04132b|#5b6472|--alps-slider-accent|-webkit-line-clamp:\s*2|border-radius:\s*6px|__image-frame--cover|__image-frame--panel|__panel-label/i';
$scoped = function (string $css, string $label) use ($v4) {
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
    $bad = [];
    $cardRules = 0;
    foreach ($rules as [, $selectors, $body]) {
        if (str_starts_with(trim($selectors), '@')) {
            continue;
        }
        if (!preg_match($v4, $selectors . '{' . $body)) {
            continue;
        }
        $cardRules++;
        foreach (explode(',', $selectors) as $selector) {
            if (false === strpos($selector, 'alps-latest-sliders--cards')) {
                $bad[] = trim($selector);
            }
        }
    }
    check("$label: $cardRules v4 card rules, all scoped to .alps-latest-sliders--cards", $cardRules > 5 && !$bad);
    if ($bad) {
        echo '    unscoped: ' . implode(' | ', array_slice($bad, 0, 5)) . "\n";
    }
};
$scoped((string) file_get_contents(dirname(__DIR__, 2) . '/resources/styles/latest-post-sliders.css'), 'resources/styles/latest-post-sliders.css');
$manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/public/manifest.json'), true);
$built = (string) file_get_contents(dirname(__DIR__, 2) . '/public/' . $manifest['app.css']);
$slider = substr($built, (int) strpos($built, '.alps-latest-sliders{'));
$scoped($slider, 'built ' . $manifest['app.css'] . ' (slider part)');

$files->deleteDirectory($cache);
echo $failures ? "$failures check(s) failed\n" : "Latest Post Slider card layout: all checks passed\n";
exit($failures ? 1 : 0);
