<?php
/**
 * Checks for App\InlineStyles (small stylesheets printed inside the page) and
 * App\ThemeCache (compiled-view cleanup), with WordPress stubbed.
 * Run: php tests/styles/run.php
 */

define('ABSPATH', sys_get_temp_dir() . '/alps-styles-test/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');

$failures = 0;
function check($label, $condition)
{
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    $failures += $condition ? 0 : 1;
}

// A throwaway site tree: two plugin stylesheets, a large theme stylesheet,
// compiled views and an Acorn manifest.
$files = [
    'wp-content/plugins/small/a.css' => str_repeat('a{}', 100),
    'wp-content/themes/t/big.css' => str_repeat('b{}', 9000),
    'wp-includes/css/core.css' => 'c{}',
    'wp-content/cache/acorn/framework/views/one.php' => '<?php',
    'wp-content/cache/acorn/framework/views/two.php' => '<?php',
    'wp-content/cache/acorn/framework/cache/services.php' => '<?php return [];',
    'secret.css' => 'x{}',
];
foreach ($files as $file => $content) {
    @mkdir(dirname(ABSPATH . $file), 0777, true);
    file_put_contents(ABSPATH . $file, $content);
}

function content_url($path = '') { return 'https://example.test/wp-content' . $path; }
function includes_url($path = '') { return 'https://example.test/wp-includes' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function is_admin() { return false; }
function add_action() {}
function add_filter() {}
function wp_delete_file($file) { unlink($file); }
function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }

class Styles
{
    public $queue = [];
    public $registered = [];
    public $done = [];
    public $data = [];
    public function get_data($handle, $key) { return $this->data[$handle][$key] ?? false; }
}
$GLOBALS['styles'] = new Styles();
function wp_styles() { return $GLOBALS['styles']; }
function wp_style_add_data($handle, $key, $value) { $GLOBALS['styles']->data[$handle][$key] = $value; }
function style($handle, $src, $media = 'all')
{
    $GLOBALS['styles']->queue[] = $handle;
    $GLOBALS['styles']->registered[$handle] = (object) ['src' => $src, 'args' => $media];
}

require dirname(__DIR__, 2) . '/app/InlineStyles.php';
require dirname(__DIR__, 2) . '/app/ThemeCache.php';
use App\InlineStyles;
use App\ThemeCache;

$real = function ($path) { return realpath(ABSPATH . $path); };
check('plugin stylesheet URL maps to its file', $real('wp-content/plugins/small/a.css') === InlineStyles::localPath('https://example.test/wp-content/plugins/small/a.css?ver=1.0'));
check('root-relative URL maps too', $real('wp-includes/css/core.css') === InlineStyles::localPath('/wp-includes/css/core.css'));
check('other hosts are not local', '' === InlineStyles::localPath('https://cdn.example.org/wp-content/plugins/small/a.css'));
check('paths cannot leave wp-content', '' === InlineStyles::localPath('https://example.test/wp-content/../secret.css'));
check('only .css files', '' === InlineStyles::localPath('https://example.test/wp-content/cache/acorn/framework/views/one.php'));

style('small', 'https://example.test/wp-content/plugins/small/a.css?ver=2');
style('big', 'https://example.test/wp-content/themes/t/big.css');
style('print', 'https://example.test/wp-content/plugins/small/a.css', 'print');
style('remote', 'https://fonts.example.org/x.css');
InlineStyles::markSmallStyles();
$copy = wp_styles()->get_data('small', 'path');
check('a small local stylesheet gets path data: a minified copy in wp-content/cache/adventistai/css', is_string($copy) && 0 === strpos($copy, WP_CONTENT_DIR . '/cache/adventistai/css/') && is_file($copy));
check('a stylesheet over 26 KB stays a link', false === wp_styles()->get_data('big', 'path'));
check('print-only and remote stylesheets stay links', false === wp_styles()->get_data('print', 'path') && false === wp_styles()->get_data('remote', 'path'));
check('the inline total is raised to 150 KB', 150000 === InlineStyles::limit(20000));

// Minified copies of plugin stylesheets.
$plugin = ABSPATH . 'wp-content/plugins/small/readable.css';
file_put_contents($plugin, "/* Banner */\n.banner {\n    color: red;   /* brand */\n}\n");
$first = InlineStyles::minifiedPath($plugin);
check('a readable stylesheet is inlined from its minified copy', is_file($first) && '.banner{color:red;}' === file_get_contents($first));
check('the copy is reused while the source is unchanged', $first === InlineStyles::minifiedPath($plugin));
file_put_contents($plugin, ".banner { color: blue; }\n.x { }\n");
touch($plugin, time() + 60);
clearstatcache();
$second = InlineStyles::minifiedPath($plugin);
check('a changed source gets a new copy; a fresh old copy stays (a request may be reading it)', $second !== $first && is_file($first) && '.banner{color:blue;}.x{}' === file_get_contents($second));
file_put_contents($plugin, ".banner { color: green; }\n");
touch($plugin, time() + 120);
touch($first, time() - 7200);
touch($second, time() - 7200);
clearstatcache();
$third = InlineStyles::minifiedPath($plugin);
check('copies over an hour old are removed when a new one is written', ! is_file($first) && ! is_file($second) && is_file($third));
function get_template_directory() { return ABSPATH . 'wp-content/themes/t'; }
@mkdir(ABSPATH . 'wp-content/themes/t/public/css', 0777, true);
$built = ABSPATH . 'wp-content/themes/t/public/css/app.abc123.css';
file_put_contents($built, 'a{b:c}');
check("the theme's own build output (public/) is inlined as it is", realpath($built) === InlineStyles::minifiedPath(realpath($built)));
$min = ABSPATH . 'wp-includes/css/core.min.css';
file_put_contents($min, 'c { }');
check('*.min.css files are inlined as they are', $min === InlineStyles::minifiedPath($min));

$cases = [
    'comments and indentation go' => ["a {\n  color: red; /* note */\n}\n", 'a{color:red;}'],
    'selector whitespace collapses; combinators keep a space' => ['.a   .b , .c >  .d  {x:y}', '.a .b,.c > .d{x:y}'],
    'a comment between two names stays as an empty comment' => ['a/* x */b{}', 'a/**/b{}'],
    'a comment before a class just goes' => ['.x/* c */.y{}', '.x.y{}'],
    'strings are untouched' => ['a{content:" a  /* b */ ; "}', 'a{content:" a  /* b */ ; "}'],
    'escaped quotes stay inside the string' => ["a{content:'it\\'s  ok'}", "a{content:'it\\'s  ok'}"],
    'an empty custom property keeps its space' => ['*{--tw-pan-x: ;--tw-pan-y: }', '*{--tw-pan-x: ;--tw-pan-y: }'],
    'calc() keeps the spaces around + and -' => ['a{width: calc( 100% - 2 * 1rem )}', 'a{width:calc( 100% - 2 * 1rem )}'],
    'a hex escape keeps the space that ends it' => ['.\\31 0  .a{}', '.\\31 0 .a{}'],
    'a hex escape followed by a descendant keeps both spaces' => ['.\\31  .a{}', '.\\31  .a{}'],
    'an escaped space is not whitespace' => ['.a\\ b {}', '.a\\ b{}'],
    'unquoted url() is copied as it is' => ['a{background:url( img/a b.png ) no-repeat}', 'a{background:url( img/a b.png ) no-repeat}'],
    'url() with a comment-like path is untouched' => ['a{b:url(x/*y*/z.png)}', 'a{b:url(x/*y*/z.png)}'],
    'media queries keep "and ("' => ["@media screen and (min-width: 600px) {\n a { b: c }\n}", '@media screen and (min-width:600px){a{b:c}}'],
    '!important keeps its space' => ['a{color: red !important ;}', 'a{color:red !important;}'],
    'an unclosed comment runs to the end' => ['a{}/* unfinished', 'a{}'],
    'pseudo-classes after a descendant space survive' => ['.a :hover{}', '.a :hover{}'],
];
foreach ($cases as $label => [$in, $expected]) {
    $got = InlineStyles::minify($in);
    check('minify: ' . $label, $expected === $got);
    if ($expected !== $got) {
        echo "    expected " . json_encode($expected) . "\n    got      " . json_encode($got) . "\n";
    }
}

check('Acorn storage defaults to wp-content/cache/acorn', WP_CONTENT_DIR . '/cache/acorn' === ThemeCache::storagePath());
check('clearing removes the compiled views', 2 === ThemeCache::clear() && ! glob(WP_CONTENT_DIR . '/cache/acorn/framework/views/*.php'));
check('Acorn\'s services manifest is kept', is_file(WP_CONTENT_DIR . '/cache/acorn/framework/cache/services.php'));
check('clearing also removes the minified stylesheet copies', ! glob(WP_CONTENT_DIR . '/cache/adventistai/css/*.css'));

// The v4 identity changes (brand-identity.css, embedded on every page) are for
// the front page only (3.41.1): every selector goes through <body class="home">
// (a literal body.home, since any block can be given the class "home"), except
// the one rule that hides the heading-row "Daugiau" link on the other pages.
function front_page_only(string $selector): bool
{
    $selector = preg_replace('~\s+~', ' ', trim($selector));
    if ($selector === 'body:not(.home) .alps-card-scroller__head-more') {
        return true;
    }
    // "body.home" as a whole compound (optionally after html or the <html>
    // colour-scheme class), followed by a descendant or child combinator,
    // never a sibling one (+, ~, ||), which would leave the body.
    return (bool) preg_match('#^(html |\.u-theme--[a-z-]+ )?body\.home( ?> ?| )(?![+~|>])\S#', $selector)
        && ! str_contains($selector, ':not(.home)');
}
function css_selectors(string $css): array
{
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    // Statement at-rules (@layer a;, @import …;, @charset …;) would otherwise
    // hide the selector that follows them in the same prelude.
    $css = preg_replace('~@[a-z-]+[^;{}]*;~i', '', $css);
    preg_match_all('~([^{}]+)\{~', $css, $m);
    $selectors = [];
    foreach ($m[1] as $prelude) {
        if (str_starts_with(trim($prelude), '@')) {
            continue; // @media, @layer x {…}: their rules are matched on their own
        }
        foreach (explode(',', $prelude) as $selector) {
            $selectors[] = trim($selector);
        }
    }
    return $selectors;
}

$adversarial = [
    '.home .c-footer' => false, // any element with class "home"
    'body .home .c-footer' => false,
    '.u-theme--denim .home .c-footer' => false,
    'body:not(.home) .c-footer' => false, // only the Daugiau rule may target other pages
    'body:not(.home) .alps-card-scroller__head-more, .x' => false,
    'body.homepage .c-footer' => false,
    'body.home' => false, // the body itself (no descendant)
    'body.home:not(.home) .x' => false,
    'body.home + .x' => false, // siblings of the body, not inside it
    'body.home ~ .x' => false,
    'body.home || .x' => false,
    'body.home+.x' => false,
    'body.home > + .x' => false,
    'body.home > .x' => true,
    '.c-header .c-header__logo' => false,
    'body.home .c-header .c-header__logo:not(.c-header__logo--wide) img' => true,
    '.u-theme--denim body.home .c-footer.u-theme--background-color--darker' => true,
    'html body.home .alps-card-scroller a' => true,
    'body.home .text>h2+*' => true,
    'body:not(.home) .alps-card-scroller__head-more' => true,
];
$wrong = [];
foreach ($adversarial as $selector => $expected) {
    $got = count(css_selectors("$selector{}")) === 1 && front_page_only($selector);
    if ($got !== $expected) {
        $wrong[] = $selector;
    }
}
// Rules behind statement at-rules or inside block at-rules are still checked.
$hidden = [
    '@layer base;.leak{color:red}',
    '@import url(x.css);@charset "utf-8";.leak{}',
    '@media (min-width:1px){.leak{}}',
    '@layer base{.leak{}}',
    '@supports (display:grid){@media print{.leak{}}}',
];
foreach ($hidden as $css) {
    if (css_selectors($css) !== ['.leak']) {
        $wrong[] = $css;
    }
}
check('the front-page check rejects ' . count(array_filter($adversarial, fn ($e) => ! $e)) . ' unscoped selectors, also behind at-rules, and accepts the scoped forms', ! $wrong);
foreach ($wrong as $selector) {
    echo "    misjudged: $selector\n";
}

// The card scroller prints the heading-row link on every page, so the rule that
// hides it outside the front page must stay (an emptied rule is dropped by the
// minifier and would pass the scope check).
check('brand-identity.min.css hides the heading-row Daugiau link on other pages', str_contains(
    (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/brand-identity.min.css'),
    'body:not(.home) .alps-card-scroller__head-more{display:none!important}'
));

// The footer, the page header band and the search drawer keep the ALPS navy
// on the front page too (3.42.1): no rule in the identity CSS recolours them.
foreach (['brand-identity.css', 'brand-identity.min.css'] as $file) {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/' . $file);
    check($file . ': footer, header band and search drawer keep their ALPS colours',
        ! preg_match('~c-footer|c-page-header|c-search-drawer|c-drawer|background-color--dark~i', $css));
}

foreach (['brand-identity.css', 'brand-identity.min.css'] as $file) {
    $selectors = css_selectors((string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/' . $file));
    $unscoped = array_filter($selectors, fn ($s) => ! front_page_only($s));
    check($file . ': ' . count($selectors) . ' selectors, all front-page only', count($selectors) > 15 && ! $unscoped);
    foreach ($unscoped as $selector) {
        echo "    not scoped: $selector\n";
    }
}

// Beside the left side column, Wide and Full width blocks take the article's
// width, measured against the content row (3.42.4); they used to run over the
// column and the Sabbath column.
$rail = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/sidebar-rail.min.css');
check('sidebar-rail.min.css: the content row is a size container', (bool) preg_match('~\.alps-has-rail \.l-wrap \.l-main \.l-main__content\{[^}]*container-type:inline-size~', $rail));
check('sidebar-rail.min.css: Full width blocks are the row less the column', str_contains($rail, '.alps-has-rail .text>.alignfull{width:calc(100cqw - var(--alps-rail))}'));
check('sidebar-rail.min.css: Wide blocks are capped the same way', str_contains($rail, 'width:min(100cqw - var(--alps-rail) - 2rem, 105rem)'));
check('site-overrides.min.css: no break-out beside a grid sidebar', str_contains(
    (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/site-overrides.min.css'),
    '.alps-has-sidebar .text>.alignfull{width:100%;margin-left:0;transform:none}'
));

echo $failures ? "$failures check(s) failed\n" : "All checks passed\n";
exit($failures ? 1 : 0);
