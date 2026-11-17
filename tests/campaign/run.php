<?php
/**
 * "Visa darau nauja" band (App\CampaignBand, 3.42.0). Renders the real page
 * templates (page, template-custom, index) through their real header
 * dispatchers (page-header-hero, page-header-v2, page-header, the header
 * blocks) with Illuminate's view factory and WordPress stubbed
 * (tests/campaign/fixtures/stubs.php). Only the layout and the leaf views
 * around the header (content, breadcrumbs, media block, carousel) are stubs
 * (fixtures/views/, looked up first).
 * - band off (the default): on every kind of page, exactly the markup of
 *   3.41.0, whose three header views are frozen in fixtures/released/
 *   (copies of 57b44f9) and rendered in a copy of resources/views;
 * - band on: on every kind of front page (static, with each header or hero
 *   layout, page builder template, latest posts) the band and nothing of the
 *   usual header; one h1 holding both wordmark lines, texts escaped, buttons
 *   without a URL left out, unsafe URLs (also entity-encoded) dropped, the
 *   theme's images unless a Media Library image is chosen, the timer inside;
 * plus the settings fields, their sanitising, and the stylesheet's scope.
 * Run: php tests/campaign/run.php (needs composer install).
 */

$failures = 0;
function check($label, $condition)
{
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    $failures += $condition ? 0 : 1;
}

// Defined before the autoloader so the stubbed Store wins.
require __DIR__ . '/fixtures/stubs.php';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/template-helpers.php';

use App\CampaignBand;
use App\Fields\Registry;
use App\Fields\SettingsPage;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;

$tmp = sys_get_temp_dir() . '/alps-campaign-' . getmypid();
$files = new Filesystem();
// 3.41.0's views: today's tree with the three header views it changed put back.
$files->copyDirectory($root . '/resources/views', "$tmp/released-views");
foreach (['page-header', 'page-header-hero', 'page-header-v2'] as $view) {
    $files->copy(__DIR__ . "/fixtures/released/$view.blade.php", "$tmp/released-views/patterns/02-organisms/sections/$view.blade.php");
}
$factory = function (string $views, string $cache) use ($files) {
    $files->ensureDirectoryExists($cache);
    $resolver = new EngineResolver();
    $resolver->register('blade', fn () => new CompilerEngine(new BladeCompiler($files, $cache), $files));
    $finder = new FileViewFinder($files, [__DIR__ . '/fixtures/views', $views]);
    $finder->addExtension('blade.php');
    $factory = new Factory($resolver, $finder, new Dispatcher(new Container()));
    $factory->addExtension('blade.php', 'blade');
    return $factory;
};
$current = $factory($root . '/resources/views', "$tmp/cache-current");
$released = $factory("$tmp/released-views", "$tmp/cache-released");

// Kinds of page: the template WordPress would pick, and the request.
$requests = [
    'static front page' => ['page', ['front' => true]],
    'static front page, hero "full"' => ['page', ['front' => true, 'fields' => ['hero_type' => 'full'], 'meta' => ['_hero_image' => 77]]],
    'static front page, hero "default"' => ['page', ['front' => true, 'fields' => ['hero_type' => 'default'], 'meta' => ['_hero_image' => 77]]],
    'static front page, hero "full_overlay"' => ['page', ['front' => true, 'fields' => ['hero_type' => 'full_overlay'], 'meta' => ['_hero_image' => 77]]],
    'static front page, carousel' => ['page', ['front' => true, 'fields' => ['hero_type' => 'carousel']]],
    'static front page, featured header' => ['page', ['front' => true, 'meta' => ['_featured_image_hero_layout' => 'header-block-featured']]],
    'static front page, header removed' => ['page', ['front' => true, 'meta' => ['_remove_header' => 'true']]],
    'page builder front page' => ['template-custom', ['front' => true]],
    'latest-posts front page' => ['index', ['front' => true, 'home' => true]],
    'other page' => ['page', ['front' => false]],
    'other page, hero "full"' => ['page', ['front' => false, 'fields' => ['hero_type' => 'full'], 'meta' => ['_hero_image' => 77]]],
    'posts page (not the front page)' => ['index', ['front' => false, 'home' => true]],
];
$render = function (array $options, string $request = 'static front page', bool $old = false) use ($current, $released, $requests) {
    [$view, $page] = $requests[$request];
    $GLOBALS['options'] = $options;
    $GLOBALS['page'] = $page + ['title' => 'Pradžia', 'meta' => [], 'fields' => []];
    $GLOBALS['post'] = (object) ['ID' => 5];
    $GLOBALS['loop'] = 1;
    return ($old ? $released : $current)->make($view, ['post' => $GLOBALS['post']])->render();
};

// 1. Off by default: every kind of page renders exactly as in 3.41.0.
$states = [
    'nothing saved' => [],
    'switched off' => ['campaign_band_show' => false, 'campaign_band_button_1_url' => '/x/'],
    'timer hidden' => ['sabbath_timer_hide' => true],
];
foreach ($states as $state => $options) {
    foreach (array_keys($requests) as $request) {
        $now = $render($options, $request);
        check("off ($state, $request): same markup as 3.41.0", $now === $render($options, $request, true) && strlen($now) > 50);
        check("off ($state, $request): no band", false === strpos($now, 'adventistai-vdn'));
    }
}
check('off: the static front page still shows the Sabbath timer', false !== strpos($render([]), 'adventistai-sabbath-timer-host'));
check('off, timer hidden: the static front page shows its title as before', 1 === substr_count($render(['sabbath_timer_hide' => true]), '<h1'));
check('off: the hero front page shows its hero (the stubbed media block)', false !== strpos($render([], 'static front page, hero "full"'), 'stub-media-block'));
check('off: the latest-posts front page shows its title', 1 === substr_count($render([], 'latest-posts front page'), '<h1'));

// On: the band on every kind of front page, in place of its usual header;
// other pages unchanged.
foreach (array_keys($requests) as $request) {
    $on = $render(['campaign_band_show' => true], $request);
    if ($GLOBALS['page']['front']) {
        check("on ($request): the band, one h1, none of the usual header",
            1 === substr_count($on, '<header class="adventistai-vdn">') && 1 === substr_count($on, '<h1')
            && false === strpos($on, 'c-page-header') && false === strpos($on, 'stub-media-block') && false === strpos($on, 'stub-carousel')
            && (false !== strpos($on, 'c-article') || false !== strpos($on, 'stub-content')));
    } else {
        check("on ($request): same markup as 3.41.0", $on === $render(['campaign_band_show' => true], $request, true));
    }
}
check('CampaignBand::shown(): the front page with the band on', (function () use ($render) { $render(['campaign_band_show' => true]); return CampaignBand::shown(); })());
check('CampaignBand::shown(): not on other pages', (function () use ($render) { $render(['campaign_band_show' => true], 'other page'); return !CampaignBand::shown(); })());
check('CampaignBand::shown(): not with the band off', (function () use ($render) { $render([]); return !CampaignBand::shown(); })());

// 2. On, with the defaults.
$on = $render(['campaign_band_show' => true]);
check('on: the band replaces the header band', false !== strpos($on, '<header class="adventistai-vdn">') && false === strpos($on, 'c-page-header__long'));
check('on: exactly one h1', 1 === substr_count($on, '<h1'));
check('on: the h1 holds both wordmark lines',
    1 === preg_match('~<h1 class="adventistai-vdn__wordmark"><span class="adventistai-vdn__line adventistai-vdn__line--old">Visa darau</span> <span class="adventistai-vdn__line adventistai-vdn__line--new">nauja</span></h1>~', $on));
check('on: tagline, verse and reference by default',
    false !== strpos($on, '<p class="adventistai-vdn__tagline">Viltis prasideda čia.</p>')
    && false !== strpos($on, '„Štai aš visa darau nauja!“ <span class="adventistai-vdn__reference">(Apr 21, 5)</span>'));
check('on: no buttons without URLs (the pages do not exist yet)', false === strpos($on, 'adventistai-vdn__button'));
check('on: the Sabbath timer sits inside the band', 1 === preg_match('~<header class="adventistai-vdn">.*data-sabbath-timer.*</header>~s', $on));
check('on, timer hidden in the settings: no timer markup', false === strpos($render(['campaign_band_show' => true, 'sabbath_timer_hide' => true]), 'data-sabbath-timer'));

// The theme's own images: decorative, eager, high priority, phones get their crops.
$img = THEME_URI . '/assets/images/visa-darau-nauja/';
check('on: the default old image with its 1x/2x files',
    false !== strpos($on, '<img src="' . $img . 'old-470.webp" srcset="' . $img . 'old-470.webp 470w, ' . $img . 'old-940.webp 940w" sizes="32.6vw" width="470" height="360" alt="" fetchpriority="high" decoding="async">'));
check('on: the default new image with its 1x/2x files',
    false !== strpos($on, '<img src="' . $img . 'new-764.webp" srcset="' . $img . 'new-764.webp 764w, ' . $img . 'new-1528.webp 1528w" sizes="53vw" width="764" height="360" alt="" fetchpriority="high" decoding="async">'));
check('on: phones get the phone crops',
    false !== strpos($on, '<source media="(max-width: 699px)" srcset="' . $img . 'old-phone-390.webp 390w, ' . $img . 'old-phone-780.webp 780w" sizes="100vw">')
    && false !== strpos($on, '<source media="(max-width: 699px)" srcset="' . $img . 'new-phone-390.webp 390w, ' . $img . 'new-phone-780.webp 780w" sizes="100vw">'));
check('on: no lazy loading, every image has an empty alt', false === strpos($on, 'loading=') && substr_count($on, '<img') === substr_count($on, 'alt=""'));
foreach (['old-470', 'old-940', 'new-764', 'new-1528', 'old-phone-390', 'old-phone-780', 'new-phone-390', 'new-phone-780'] as $file) {
    $size = @filesize($root . "/assets/images/visa-darau-nauja/$file.webp");
    check("image $file.webp is shipped and at most 120 KB ($size bytes)", $size > 0 && $size <= 120_000);
}

// 3. Edited texts and buttons.
$edited = $render([
    'campaign_band_show' => true,
    'campaign_band_line_old' => '<b>Visa</b> & darau',
    'campaign_band_line_new' => '',
    'campaign_band_tagline' => '"Viltis" <script>alert(1)</script>',
    'campaign_band_verse' => '',
    'campaign_band_verse_reference' => 'Apr 21, 5',
    'campaign_band_button_1_label' => '',
    'campaign_band_button_1_url' => 'https://example.org/a?x=1&y=<2>',
    'campaign_band_button_2_label' => 'Bendruomenės <i>visur</i>',
    'campaign_band_button_2_url' => '/bendruomenes/',
]);
check('edited: tags are stripped and text is escaped', false !== strpos($edited, 'line--old">Visa &amp; darau</span>') && false === strpos($edited, '<script>alert') && false !== strpos($edited, '&quot;Viltis&quot; alert(1)'));
check('edited: an emptied wordmark line falls back to its default', false !== strpos($edited, 'line--new">nauja</span>'));
check('edited: an emptied verse is not shown, nor its reference alone', false === strpos($edited, 'adventistai-vdn__verse') && false === strpos($edited, 'Apr 21'));
check('edited: an emptied button label falls back to its default, the first button has the arrow',
    false !== strpos($edited, 'href="https://example.org/a?x=1&amp;y=2">Sužinokite daugiau<span class="adventistai-vdn__arrow" aria-hidden="true">→</span></a>'));
check('edited: the second button is the outlined one, its label as text',
    false !== strpos($edited, '<a class="adventistai-vdn__button adventistai-vdn__button--secondary" href="/bendruomenes/">Bendruomenės visur</a>'));

$unsafe = $render([
    'campaign_band_show' => true,
    'campaign_band_button_1_url' => 'javascript:alert(1)',
    'campaign_band_button_2_url' => " jAvA\tScRiPt:alert(document.cookie)",
]);
check('unsafe: javascript: URLs are dropped with their buttons', false === stripos($unsafe, 'javascript') && false === strpos($unsafe, 'adventistai-vdn__button'));
$one = $render(['campaign_band_show' => true, 'campaign_band_button_2_url' => '#bendruomenes']);
check('one URL: only that button is shown', 1 === substr_count($one, 'adventistai-vdn__button ') && false !== strpos($one, 'href="#bendruomenes">Rasti bendruomenę</a>'));

foreach ([
    'https://adventistai.lt/kampanija/' => 'https://adventistai.lt/kampanija/',
    'http://example.org' => 'http://example.org',
    '/rasti-bendruomene/' => '/rasti-bendruomene/',
    '#anchor' => '#anchor',
    '?p=12' => '?p=12',
    '  /tarpai/  ' => '/tarpai/',
    'javascript:alert(1)' => '',
    'JAVASCRIPT:alert(1)' => '',
    "java\nscript:alert(1)" => '',
    'data:text/html,<b>x</b>' => '',
    'vbscript:x' => '',
    'mailto:info@example.org' => '',
    '//evil.example/' => '',
    '\\\\evil.example' => '',
    '/\\evil.example/' => '',
    'https:/no-host' => '',
    'puslapis/' => '',
    '' => '',
    // Character references a browser decodes in an href: "/\\host" and
    // "//host" both lead to another site.
    '/&#92;&#92;evil.example/' => '',
    '/&#x5c;&#x5C;evil.example/' => '',
    '/&#0092;evil.example/' => '',
    '/&#47;evil.example/' => '',
    '&#47;&#47;evil.example/' => '',
    '/&sol;evil.example/' => '',
    '/&bsol;evil.example/' => '',
    '/&amp;#92;&amp;#92;evil.example/' => '',
    '/&amp;amp;#47;evil.example/' => '',
    '/&#92evil.example/' => '',
    '&#106;avascript:alert(1)' => '',
    'javascript&colon;alert(1)' => '',
    '/a&Tab;b/' => '',
    "/a\u{200B}b/" => '',
    "/a\u{00A0}b/" => '',
    "/\xC0\xAF" => '',
    '/a?b=1&amp;c=2' => '/a?b=1&c=2',
    'https://example.org/?q=&quot;x&quot;' => 'https://example.org/?q="x"',
] as $input => $expected) {
    check('safeUrl(' . json_encode($input) . ') = ' . json_encode($expected), CampaignBand::safeUrl($input) === $expected);
}

// Stored values that never went through the settings page (an older row, a
// direct database edit) are checked again when printed, and the href is
// escaped once by Blade: no reference reaches the page undecoded.
foreach (['/&#92;&#92;evil.example/', '/&#x2F;evil.example/', '/&sol;&sol;evil.example/', '/&amp;#92;evil.example/'] as $stored) {
    $html = $render(['campaign_band_show' => true, 'campaign_band_button_1_url' => $stored]);
    check('printed: ' . $stored . ' gives no button', false === strpos($html, 'adventistai-vdn__button') && false === strpos($html, 'evil.example'));
}
$html = $render(['campaign_band_show' => true, 'campaign_band_button_1_url' => '/paieska/?q=a&amp;b=c&d="e"', 'campaign_band_button_2_url' => "https://example.org/it's"]);
check('printed: an allowed URL is escaped once (& → &amp;, " dropped by URL cleaning, \' → &#039;)',
    false !== strpos($html, 'href="/paieska/?q=a&amp;b=c&amp;d=e"') && false !== strpos($html, 'href="https://example.org/it&#039;s"'));
preg_match_all('~href="([^"]*)"~', $html, $hrefs);
check('printed: no href holds a character reference other than &amp; and &#039;', [] === array_filter($hrefs[1], fn ($h) => preg_match('~&(?!amp;|#039;)~', $h)));

// Media Library images: an image replaces the default, anything else does not.
$media = $render(['campaign_band_show' => true, 'campaign_band_image_old' => '41', 'campaign_band_image_new' => '42']);
check('media: a chosen image replaces the old photo, with its srcset and phone sizes',
    false !== strpos($media, '<img src="https://example.test/uploads/ruduo.jpg" srcset="https://example.test/uploads/ruduo-800.jpg 800w, https://example.test/uploads/ruduo.jpg 1600w" sizes="(max-width: 699px) 100vw, 32.6vw" width="1600" height="1200" alt=""')
    && false === strpos($media, 'old-470.webp') && false === strpos($media, 'old-phone-390.webp'));
check('media: a chosen file that is not an image keeps the default new photo', false !== strpos($media, 'new-764.webp') && false !== strpos($media, 'new-phone-390.webp'));
check('media: a Media Library image gets the stronger shade, the theme photo does not',
    false !== strpos($media, 'adventistai-vdn__picture--old adventistai-vdn__picture--custom') && false === strpos($media, 'adventistai-vdn__picture--new adventistai-vdn__picture--custom'));

// That shade, on a plain white image: white text at WCAG AA (sRGB compositing).
$shade = function (string $side) use ($root) {
    $css = (string) file_get_contents($root . '/assets/css/campaign-band.css');
    preg_match('~\.adventistai-vdn__picture--' . $side . '\.adventistai-vdn__picture--custom::after\s*\{\s*background:\s*rgba\((\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\);~', $css, $m);
    return $m ? array_map('floatval', array_slice($m, 1)) : null;
};
$contrastOnWhite = function (?array $rgba) {
    if (!$rgba) {
        return 0.0;
    }
    $lin = fn ($c) => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    $mix = fn ($c) => $lin((1 - $rgba[3]) + $rgba[3] * $c / 255);
    $l = 0.2126 * $mix($rgba[0]) + 0.7152 * $mix($rgba[1]) + 0.0722 * $mix($rgba[2]);
    return 1.05 / ($l + 0.05);
};
$oldOnWhite = $contrastOnWhite($shade('old'));
$newOnWhite = $contrastOnWhite($shade('new'));
check(sprintf('contrast on a white Media Library image: wordmark on the old half %.2f:1 (3:1 for large text)', $oldOnWhite), $oldOnWhite >= 3.0);
check(sprintf('contrast on a white Media Library image: everything on the new half %.2f:1 (4.5:1)', $newOnWhite), $newOnWhite >= 4.5);

// The outlined button lays its own background over that shade, at rest and on
// hover; white text must stay at AA in both states.
$buttonOnWhite = function (string $state) use ($root, $shade) {
    $css = (string) file_get_contents($root . '/assets/css/campaign-band.css');
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    preg_match('~\.adventistai-vdn \.adventistai-vdn__button--secondary' . preg_quote($state, '~') . '\s*\{[^}]*?background:\s*rgba\((\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\)~', $css, $m);
    $under = $shade('new');
    if (!$m || !$under) {
        return 0.0;
    }
    $lin = fn ($c) => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    $l = 0.0;
    foreach ([0 => 0.2126, 1 => 0.7152, 2 => 0.0722] as $i => $weight) {
        $c = (1 - $under[3]) + $under[3] * $under[$i] / 255;     // shade over white
        $c = (1 - (float) $m[4]) * $c + (float) $m[4] * (float) $m[$i + 1] / 255; // button over that
        $l += $weight * $lin($c);
    }
    return 1.05 / ($l + 0.05);
};
foreach (['' => 'at rest', ':hover' => 'on hover'] as $state => $label) {
    $ratio = $buttonOnWhite($state);
    check(sprintf('contrast on a white Media Library image: the outlined button %s %.2f:1 (4.5:1)', $label, $ratio), $ratio >= 4.5);
}

// 4. Settings: the fields, their defaults (off), and sanitising on save.
$fields = Registry::options();
$names = ['campaign_band_show', 'campaign_band_image_old', 'campaign_band_image_new', 'campaign_band_line_old', 'campaign_band_line_new',
    'campaign_band_tagline', 'campaign_band_verse', 'campaign_band_verse_reference', 'campaign_band_button_1_label',
    'campaign_band_button_1_url', 'campaign_band_button_2_label', 'campaign_band_button_2_url'];
check('settings: every band field is registered', !array_diff($names, array_keys($fields)));
check('settings: the band is off by default', $fields['campaign_band_show']['type'] === 'checkbox' && $fields['campaign_band_show']['default'] === false);
check('settings: CampaignBand::enabled() is false with nothing saved', (function () { $GLOBALS['options'] = []; return !CampaignBand::enabled(); })());
check('settings: URLs have no default', ($fields['campaign_band_button_1_url']['default'] ?? '') === '' && ($fields['campaign_band_button_2_url']['default'] ?? '') === '');
// SettingsPage::layout() needs WordPress for its other tabs: read the tab from the source.
$source = (string) file_get_contents($root . '/app/Fields/SettingsPage.php');
$tab = [1 => (string) strstr((string) strstr($source, "'campaign_band' => [__('VISA DARAU NAUJA'"), "'footer' => [", true)];
preg_match_all("~\['field', '([a-z0-9_]+)'~", $tab[1] ?? '', $listed);
check('settings: the VISA DARAU NAUJA tab lists every field', $listed[1] === $names);
$url = Registry::field('option', 'campaign_band_button_1_url');
$label = Registry::field('option', 'campaign_band_tagline');
check('save: a javascript: URL is stored empty', SettingsPage::sanitize($url, 'javascript:alert(1)') === '');
check('save: an https URL is kept', SettingsPage::sanitize($url, ' https://adventistai.lt/a/ ') === 'https://adventistai.lt/a/');
check('save: a relative URL is kept', SettingsPage::sanitize($url, '/bendruomenes/') === '/bendruomenes/');
check('save: entity-encoded separators are stored empty', SettingsPage::sanitize($url, '/&#92;&#92;evil.example/') === '' && SettingsPage::sanitize($url, '/&sol;evil.example/') === '');
$image = Registry::field('option', 'campaign_band_image_old');
check('save: a Media Library image ID is kept', SettingsPage::sanitize($image, '41') === '41');
check('save: the ID of a file that is not an image (a PDF) is stored empty', SettingsPage::sanitize($image, '42') === '');
check('save: an ID of nothing, a post or garbage is stored empty', SettingsPage::sanitize($image, '9999') === '' && SettingsPage::sanitize($image, 'abc') === '' && SettingsPage::sanitize($image, ['41']) === '');
check('save: other image fields keep their old cleaning', SettingsPage::sanitize(Registry::field('option', 'sabbath_icon'), '9999') === '9999');
check('save: text loses its tags', SettingsPage::sanitize($label, 'Viltis <script>x</script> prasideda') === 'Viltis x prasideda');
check('save: other text fields are unchanged (as typed for unfiltered_html)', SettingsPage::sanitize(Registry::field('option', 'footer_copyright'), 'A <b>B</b>') === 'A <b>B</b>');

// 5. Loading: styles and fonts only on the front page while the band is on.
$setup = (string) file_get_contents($root . '/app/setup.php');
check('setup: the band stylesheet is embedded only where the band is shown', false !== strpos($setup, '$band = CampaignBand::shown() ? CampaignBand::css()'));
check('setup: the Figtree preloads only where the band is shown', 1 === preg_match('~if \(CampaignBand::shown\(\)\) \{\s*foreach \(CampaignBand::fontUrls\(\)~', $setup));
check('setup: no other condition loads the band', 0 === substr_count($setup, 'CampaignBand::enabled()'));
$GLOBALS['options'] = [];
$css = CampaignBand::css();
check('css: two Figtree faces with font-display: swap', 2 === substr_count($css, '@font-face{font-family:"Figtree"') && 2 === substr_count($css, 'font-display:swap'));
foreach (CampaignBand::fontUrls() as $font) {
    $file = $root . substr($font, strlen(THEME_URI));
    check('font ' . basename($file) . ' is shipped as WOFF2 (' . @filesize($file) . ' bytes)', is_readable($file) && 'wOF2' === file_get_contents($file, false, null, 0, 4));
}
check('font: the OFL licence is shipped next to the fonts', false !== strpos((string) @file_get_contents($root . '/assets/fonts/figtree/OFL.txt'), 'SIL OPEN FONT LICENSE Version 1.1'));

// Every rule of the stylesheet is the band's own (nothing else on the page changes).
foreach (['assets/css/campaign-band.css', 'assets/css/campaign-band.min.css'] as $sheet) {
    $source = preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($root . '/' . $sheet));
    preg_match_all('/([^{}]+)\{[^{}]*\}/', $source, $rules);
    $bad = [];
    foreach ($rules[1] as $selectors) {
        foreach (explode(',', $selectors) as $selector) {
            $selector = trim(preg_replace('/^@media[^{]*/', '', trim($selector)));
            if ($selector !== '' && !str_starts_with($selector, '.adventistai-vdn')) {
                $bad[] = $selector;
            }
        }
    }
    check("$sheet: " . count($rules[1]) . ' rules, every selector starts at .adventistai-vdn', count($rules[1]) > 20 && !$bad);
    if ($bad) {
        echo '    unscoped: ' . implode(' | ', array_slice($bad, 0, 5)) . "\n";
    }
}

$files->deleteDirectory($tmp);
echo $failures ? "$failures check(s) failed\n" : "Visa darau nauja band: all checks passed\n";
exit($failures ? 1 : 0);
