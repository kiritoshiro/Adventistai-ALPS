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
    'wp-content/themes/t/big.css' => str_repeat('b{}', 5000),
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
check('a small local stylesheet gets path data (WordPress then inlines it)', $real('wp-content/plugins/small/a.css') === wp_styles()->get_data('small', 'path'));
check('a stylesheet over 12 KB stays a link', false === wp_styles()->get_data('big', 'path'));
check('print-only and remote stylesheets stay links', false === wp_styles()->get_data('print', 'path') && false === wp_styles()->get_data('remote', 'path'));
check('the inline total is raised to 90 KB', 90000 === InlineStyles::limit(20000));

check('Acorn storage defaults to wp-content/cache/acorn', WP_CONTENT_DIR . '/cache/acorn' === ThemeCache::storagePath());
check('clearing removes the compiled views', 2 === ThemeCache::clear() && ! glob(WP_CONTENT_DIR . '/cache/acorn/framework/views/*.php'));
check('Acorn\'s services manifest is kept', is_file(WP_CONTENT_DIR . '/cache/acorn/framework/cache/services.php'));

echo $failures ? "$failures check(s) failed\n" : "All checks passed\n";
exit($failures ? 1 : 0);
