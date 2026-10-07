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
function wp_get_attachment_image($id, $size, $icon, $attr) {
    return sprintf('<img src="/uploads/%d-300x440.jpg" width="300" height="440" srcset="x" sizes="%s" alt="%s" class="%s">', $id, esc_attr($attr['sizes']), esc_attr($attr['alt']), esc_attr($attr['class']));
}
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
$ok(strpos($html, 'sizes="(max-width: 600px) 149px, 170px"') !== false, 'cover sizes hint');
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
$ok(strpos($logo, 'sizes="156px"') !== false, 'logo sizes hint');
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

echo "Card scroller block: {$checks} checks passed.\n";
