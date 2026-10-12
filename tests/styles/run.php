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

echo $failures ? "$failures check(s) failed\n" : "All checks passed\n";
exit($failures ? 1 : 0);
