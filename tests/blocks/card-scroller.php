<?php
// Stub-based checks for App\CardScrollerBlock rendering (no WordPress runtime).
const WEEK_IN_SECONDS = 604800;
$transients = [];
$lookups = 0;
function __($text, $domain = '') { return $text; }
function esc_attr__($text, $domain = '') { return esc_attr($text); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url($url, $protocols = null) {
    $url = trim((string) $url);
    if ($url === '' || preg_match('#^(javascript|data):#i', $url)) { return ''; }
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}
function esc_url_raw($url, $protocols = null) { return htmlspecialchars_decode(esc_url($url, $protocols), ENT_QUOTES); }
function sanitize_text_field($text) { return trim(strip_tags((string) $text)); }
function absint($value) { return abs((int) $value); }
function get_block_wrapper_attributes($extra) { return 'class="wp-block-alps-card-scroller ' . esc_attr($extra['class']) . '"'; }
function get_transient($key) { global $transients; return $transients[$key] ?? false; }
function set_transient($key, $value, $ttl) { global $transients; $transients[$key] = $value; }
function attachment_url_to_postid($url) { global $lookups; $lookups++; return strpos($url, 'known') !== false ? 42 : 0; }
// Like WordPress 6.7+, a lazy image's sizes start with "auto, ".
function wp_get_attachment_image($id, $size, $icon, $attr) {
    $sizes = ('lazy' === ($attr['loading'] ?? '') ? 'auto, ' : '') . $attr['sizes'];
    return sprintf('<img src="/uploads/%d-300x440.jpg" width="300" height="440" srcset="x" sizes="%s" alt="%s" class="%s" loading="%s" decoding="async">', $id, esc_attr($sizes), esc_attr($attr['alt']), esc_attr($attr['class']), esc_attr($attr['loading'] ?? ''));
}
// wp_filter_content_tags in post content: wp_img_tag_add_auto_sizes (10), then the theme's wp_content_img_tag filter (20).
function filter_content_tags($html) {
    return (string) preg_replace_callback('/<img\b[^>]*>/', static function (array $m): string {
        $tag = preg_match('/ loading="lazy"/', $m[0]) ? preg_replace('/ sizes="(?!auto)/', ' sizes="auto, ', $m[0]) : $m[0];
        return App\LatestPostSlider::contentImageSizes($tag);
    }, $html);
}
$feed = false;
function is_admin() { return false; }
function is_feed() { global $feed; return $feed; }
function wp_is_serving_rest_request() { return false; }
require __DIR__ . '/../../app/DeferredImages.php';
require __DIR__ . '/../../app/LatestPostSlider.php';
require __DIR__ . '/../../app/CardScrollerBlock.php';
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
use App\CardScrollerBlock as Block;

$checks = 0;
$ok = function ($condition, $message) use (&$checks) { check($condition, $message); $checks++; };

$ok(Block::render([]) === '', 'empty block renders nothing');
$ok(Block::render(['items' => [['url' => 'https://example.org/']]]) === '', 'cards with nothing to show are dropped');
$plain = Block::render(['items' => [['title' => 'No link']]]);
$ok(strpos($plain, '<div class="alps-card-scroller__card"><span class="alps-card-scroller__title">No link</span></div>') !== false, 'a card without a link is shown, not as a link');

$items = [
    ['imageUrl' => 'https://adventistai.lt/wp-content/uploads/known.jpeg', 'title' => 'Kelias pas Kristų', 'url' => 'https://adventistai.lt/kelias-pas-kristu/'],
    ['imageUrl' => 'https://adventistai.lt/wp-content/uploads/missing.png', 'title' => 'Ugdymas', 'url' => '/ugdymas/', 'alt' => 'Ugdymo viršelis'],
    ['imageId' => 7, 'title' => '<b>Bold</b> "quoted"', 'url' => 'https://example.org/', 'newTab' => true, 'description' => 'Aprašymas'],
    ['title' => 'XSS', 'url' => 'javascript:alert(1)'],
];
$html = Block::render(['heading' => 'Knygos', 'headingUrl' => 'https://adventistai.lt/knygos/', 'items' => $items]);
$ok(substr_count($html, 'role="listitem"') === 4, 'four cards rendered');
$ok(strpos($html, '<div class="alps-card-scroller__card"><span class="alps-card-scroller__title">XSS</span></div>') !== false, 'a card with an unsafe URL is shown without a link');
$ok(strpos($html, 'javascript:') === false, 'unsafe URL rejected');
$ok(strpos($html, 'alps-card-scroller--cover') !== false, 'default layout is cover');
$ok(strpos($html, '<h2 class="alps-card-scroller__heading"><a href="https://adventistai.lt/knygos/">Knygos</a></h2>') !== false, 'linked heading');
$ok(strpos($html, 'src="/uploads/42-300x440.jpg"') !== false, 'URL-only card resolved to a responsive attachment');
$ok(strpos($html, 'src="https://adventistai.lt/wp-content/uploads/missing.png" alt="Ugdymo viršelis" loading="lazy"') !== false, 'unknown URL falls back to plain lazy image');
$ok(strpos($html, 'alt="Kelias pas Kristų"') !== false, 'alt falls back to the title');
$ok(strpos($html, 'Bold &quot;quoted&quot;') !== false && strpos($html, '<b>') === false, 'title escaped and stripped');
$ok(strpos($html, 'target="_blank" rel="noopener"') !== false, 'new tab gets noopener');
$ok(substr_count($html, 'target="_blank"') === 1, 'only flagged cards open a new tab');
$ok(strpos($html, 'data-card-scroller-prev hidden') !== false && strpos($html, 'data-card-scroller-next hidden') !== false, 'arrows start hidden');
$ok(strpos($html, 'sizes="auto, (max-width: 600px) 149px, 170px"') !== false, 'cover sizes hint (after WordPress\'s "auto" for lazy images)');
$ok(strpos($html, 'alps-card-scroller__card--more') === false, 'no more card without label and URL');

$lookupsBefore = $lookups;
Block::render(['items' => $items]);
$ok($lookups === $lookupsBefore, 'URL lookups cached per block');

$logo = Block::render([
    'layout' => 'logo',
    'items' => [['imageId' => 3, 'title' => 'BibleTools.info', 'description' => 'Biblijos studijos', 'url' => 'https://bibletools.info/', 'newTab' => true]],
    'moreLabel' => 'Daugiau',
    'moreUrl' => 'https://adventistai.lt/naudingos-nurodos/',
]);
$ok(strpos($logo, 'alps-card-scroller--logo') !== false, 'logo layout');
$ok(strpos($logo, 'sizes="auto, 156px"') !== false, 'logo sizes hint');
$ok(strpos($logo, '<span class="alps-card-scroller__description">Biblijos studijos</span>') !== false, 'description rendered');
$ok(strpos($logo, '<a class="alps-card-scroller__card alps-card-scroller__card--more" href="https://adventistai.lt/naudingos-nurodos/">Daugiau') !== false, 'more card is a real link');
$ok(strpos($logo, 'aria-label="Kortelės"') !== false, 'unlabelled list gets a generic name');

$ok(strpos(Block::render(['layout' => 'evil', 'items' => $items]), 'alps-card-scroller--cover') !== false, 'unknown layout falls back to cover');

// End of the row: a link to the "more" page takes the next arrow's place.
$ok(strpos($html, 'data-card-scroller-more') !== false && strpos($html, 'alps-card-scroller__arrow--more" href="https://adventistai.lt/knygos/"') !== false, 'heading link is the end-of-row destination');
$ok(strpos($html, 'aria-label="Daugiau: Knygos" data-card-scroller-more hidden><span aria-hidden="true">→</span></a>') !== false, 'more link is labelled and starts hidden');
$onlyUrl = Block::render(['items' => $items, 'moreUrl' => 'https://adventistai.lt/visos-knygos/']);
$ok(strpos($onlyUrl, 'arrow--more" href="https://adventistai.lt/visos-knygos/"') !== false, 'Daugiau URL without a label still gives the end-of-row link');
$ok(strpos($onlyUrl, 'alps-card-scroller__card--more') === false, 'no Daugiau card without a label');
$ok(strpos($logo, 'data-card-scroller-more') === false, 'no end-of-row link when the row ends with a Daugiau card');
$ok(strpos(Block::render(['items' => $items]), 'data-card-scroller-more') === false, 'no end-of-row link without a destination');
$ok(strpos(Block::render(['items' => $items, 'moreUrl' => 'javascript:alert(1)']), 'data-card-scroller-more') === false, 'unsafe Daugiau URL is ignored');

// Cards past the first screen-width of the widest track wait for the track to scroll.
$ok(Block::eagerCount('cover') === 6 && Block::eagerCount('logo') === 7, 'widest default track (1184px) shows 6 covers or 7 logos');
$ok(Block::eagerCount('cover', 'wide') === 9 && Block::eagerCount('cover', 'full') === 10 && Block::eagerCount('cover', 'left') === 6, 'wide (105rem, a 1664px track) and full tracks show more cards, other alignments the default');
$ok(substr_count($html, 'data-alps-src') === 0, 'a short row defers nothing');
$covers = [];
for ($i = 1; $i <= 9; $i++) {
    $covers[] = ['imageId' => 100 + $i, 'title' => 'Knyga ' . $i, 'url' => 'https://adventistai.lt/knyga-' . $i . '/'];
}
$row = Block::render(['items' => $covers, 'moreLabel' => 'Daugiau', 'moreUrl' => 'https://adventistai.lt/knygos/']);
preg_match_all('/<div class="alps-card-scroller__item" role="listitem">(.*?)<\/div>(?=<div class="alps-card-scroller__item"|<\/div><button)/s', $row, $cardsHtml);
$ok(count($cardsHtml[1]) === 10, 'nine covers and the Daugiau card');
$ok(strpos($cardsHtml[1][5], 'src="/uploads/106-300x440.jpg"') !== false && strpos($cardsHtml[1][5], 'data-alps-') === false, 'the sixth cover keeps its file');
$ok(strpos($cardsHtml[1][6], '<img src="' . App\DeferredImages::BLANK . '" data-alps-src="/uploads/107-300x440.jpg" width="300" height="440" data-alps-srcset="x"') !== false, 'the seventh cover waits: blank placeholder, files in data-alps-*');
$ok(strpos($cardsHtml[1][8], 'data-alps-src="/uploads/109-300x440.jpg"') !== false && substr_count($row, 'data-alps-src=') === 3, 'covers seven to nine wait, no others');
$ok(preg_match('/<img src="data:[^>]*>/', $cardsHtml[1][6], $placeholder) && strpos($placeholder[0], ' sizes="(max-width: 600px) 149px, 170px" ') !== false, 'deferred covers keep their sizes, without "auto"');
$noscript = '<noscript><img src="/uploads/107-300x440.jpg" width="300" height="440" srcset="x" sizes="auto, (max-width: 600px) 149px, 170px" alt="Knyga 7" class="alps-card-scroller__image" loading="lazy" decoding="async"></noscript></span>';
$ok(strpos($cardsHtml[1][6], '<span class="alps-card-scroller__media">' . $placeholder[0] . $noscript) !== false, 'without JavaScript: a lazy <noscript> copy with the real files, in the same media box');
$ok(substr_count($row, '<noscript>') === 3 && strpos($cardsHtml[1][5], '<noscript>') === false, 'only deferred covers get a <noscript> copy');
$content = filter_content_tags($row);
preg_match_all('/<img src="data:[^>]*>/', $content, $placeholders);
$ok(count($placeholders[0]) === 3 && strpos(implode('', $placeholders[0]), 'auto') === false, 'in post content the deferred covers do not get "auto" back');
$ok(substr_count($content, 'sizes="auto, (max-width: 600px) 149px, 170px"') === 9, 'covers shown at once and the <noscript> copies keep it');
$wideRow = Block::render(['items' => $covers, 'align' => 'wide']);
$ok(substr_count($wideRow, 'data-alps-src=') === 0, 'a wide row shows nine covers at once');
$wideRow = Block::render(['items' => array_merge($covers, array_slice($covers, 0, 2)), 'align' => 'wide']);
$ok(substr_count($wideRow, 'data-alps-src=') === 2, 'a wide row defers from the tenth cover');
$feed = true;
$ok(strpos(Block::render(['items' => $covers]), 'data-alps-') === false, 'feeds keep every file (nothing restores them there)');
$feed = false;

echo "Card scroller block: {$checks} checks passed.\n";
