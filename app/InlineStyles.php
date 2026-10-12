<?php

namespace App;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Prints small stylesheets inside the page instead of linking them.
 *
 * Plugins and blocks each add a 1–11 KB stylesheet, and in this theme they
 * all end up in <head> (wp_footer runs before the head is printed), so every
 * one of them is a separate request that blocks the first paint. WordPress
 * already inlines styles that carry `path` data (wp_maybe_inline_styles(),
 * on wp_head and wp_footer at priority 1, smallest first, up to
 * `styles_inline_size_limit` bytes in total, rewriting relative url()s).
 * This adds that data to every queued local stylesheet of at most MAX_FILE
 * bytes. That includes the theme's app.css (17 KB) and site overrides (13 KB)
 * and the calendar's front-page magenda.min.css (24 KB): linked, each was a
 * render-blocking request on phones. Only the ALPS main stylesheet (200 KB)
 * stays linked and cacheable; setup.php announces it at the top of <head>.
 * The cascade order does not change: the <style> is printed where the <link>
 * would have been.
 */
class InlineStyles
{
    /** Largest stylesheet that is inlined, in bytes. */
    const MAX_FILE = 26000;

    /** Total inlined per page, in bytes (WordPress' default is 20000). */
    const LIMIT = 150000;

    /** Change when minify() changes, so cached copies are rebuilt. */
    const MINIFY_VERSION = '1';

    /** Where minified copies of plugin stylesheets are kept (under WP_CONTENT_DIR). */
    const CACHE_DIR = '/cache/adventistai/css';

    public static function register()
    {
        if (is_admin()) {
            return;
        }
        add_action('wp_head', [__CLASS__, 'markSmallStyles'], 0);
        // WordPress runs wp_enqueue_scripts on wp_head priority 1, after the
        // pass above and just before wp_maybe_inline_styles (also priority 1),
        // so stylesheets enqueued there (the Bible, calendar, cookie banner and
        // other plugin ones) were never marked. Mark them once all are queued.
        add_action('wp_enqueue_scripts', [__CLASS__, 'markSmallStyles'], PHP_INT_MAX);
        add_action('wp_footer', [__CLASS__, 'markSmallStyles'], 0);
        // Plugins that decide in wp_footer whether the page needs them (the
        // calendar, the Bible popups) enqueue after the two passes above, so
        // their small stylesheets stayed linked. One more pass right before
        // WordPress prints those late styles (_wp_footer_scripts, priority 10).
        add_action('wp_print_footer_scripts', [__CLASS__, 'inlineLateStyles'], 5);
        add_filter('styles_inline_size_limit', [__CLASS__, 'limit']);
    }

    public static function inlineLateStyles()
    {
        self::markSmallStyles();
        if (function_exists('wp_maybe_inline_styles')) {
            // Already inlined styles have no src any more and are skipped.
            wp_maybe_inline_styles();
        }
    }

    public static function limit($limit)
    {
        return max((int) $limit, self::LIMIT);
    }

    /**
     * Absolute path of a stylesheet URL under wp-content or wp-includes on
     * this site; '' for anything else.
     *
     * @param mixed $src Registered style source.
     * @return string
     */
    public static function localPath($src)
    {
        if (! is_string($src) || '' === $src) {
            return '';
        }
        $url = strtok($src, '?#');
        $bases = [
            content_url('/') => WP_CONTENT_DIR . '/',
            includes_url('/') => ABSPATH . WPINC . '/',
        ];
        foreach ($bases as $base => $dir) {
            $relative = null;
            if (0 === strpos($url, $base)) {
                $relative = substr($url, strlen($base));
            } elseif (0 === strpos($url, (string) wp_parse_url($base, PHP_URL_PATH)) && '/' === $url[0]) {
                $relative = substr($url, strlen((string) wp_parse_url($base, PHP_URL_PATH)));
            }
            if (null === $relative || '.css' !== substr($relative, -4)) {
                continue;
            }
            $path = realpath($dir . rawurldecode($relative));
            $root = realpath($dir);
            if ($path && $root && 0 === strpos($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) {
                return $path;
            }
        }
        return '';
    }

    public static function markSmallStyles()
    {
        $styles = wp_styles();
        foreach ($styles->queue as $handle) {
            $style = isset($styles->registered[$handle]) ? $styles->registered[$handle] : null;
            if (! $style || ! $style->src || $styles->get_data($handle, 'path') || in_array($handle, $styles->done, true)) {
                continue;
            }
            $media = isset($style->args) && is_string($style->args) ? $style->args : 'all';
            if (! in_array($media, ['all', 'screen', ''], true)) {
                continue;
            }
            $path = self::localPath($style->src);
            $size = '' !== $path ? (int) filesize($path) : 0;
            if ($size > 0 && $size <= self::MAX_FILE) {
                wp_style_add_data($handle, 'path', self::minifiedPath($path));
            }
        }
    }

    /**
     * The file WordPress should inline for a local stylesheet: a minified copy
     * in wp-content/cache/adventistai/css/, or the stylesheet itself when it is
     * already minified (*.min.css), under SCRIPT_DEBUG, or when the copy can't
     * be written; the theme's own build output (public/) is minified already.
     * Plugins ship readable CSS (the cookie banner, the calendar's lightbox and
     * tooltips, ...), and every comment and indent was printed into each page.
     * WordPress reads the CSS from 'path' but rewrites relative url()s against
     * the registered src, so they still resolve. A copy is named after the
     * source's path, mtime and size, so a plugin update makes a new one; older
     * copies are removed once they are an hour old (a request that picked one
     * just before may still read it). ThemeCache::clear() removes all copies.
     *
     * @param string $path Absolute path of a local stylesheet.
     * @return string
     */
    public static function minifiedPath($path)
    {
        if ('.min.css' === substr($path, -8) || (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG)) {
            return $path;
        }
        $built = function_exists('get_template_directory') ? realpath(get_template_directory() . '/public') : false;
        if ($built && 0 === strpos($path, $built . DIRECTORY_SEPARATOR)) {
            return $path;
        }
        $dir = WP_CONTENT_DIR . self::CACHE_DIR;
        $prefix = md5($path);
        $copy = sprintf('%s/%s-%s.css', $dir, $prefix, substr(md5(filemtime($path) . ':' . filesize($path) . ':' . self::MINIFY_VERSION), 0, 12));
        if (is_file($copy)) {
            return $copy;
        }
        $css = file_get_contents($path);
        if (! is_string($css) || ! wp_mkdir_p($dir) || ! is_writable($dir)) {
            return $path;
        }
        // Written beside the final name and renamed, so a page never reads a
        // half-written copy.
        $tmp = $copy . '.' . uniqid('', true) . '.tmp';
        if (false === file_put_contents($tmp, self::minify($css)) || ! rename($tmp, $copy)) {
            if (is_file($tmp)) {
                wp_delete_file($tmp);
            }
            return $path;
        }
        foreach ((array) glob($dir . '/' . $prefix . '-*.css') as $old) {
            if ($old !== $copy && filemtime($old) < time() - 3600) {
                wp_delete_file($old);
            }
        }
        return $copy;
    }

    /**
     * Removes comments and redundant whitespace from a stylesheet, and nothing
     * else. Strings, unquoted url(...) and backslash escapes are copied as they
     * are. A run of whitespace becomes one space, dropped only next to { } ; ,
     * or after a colon (but `--tw-pan-x: ;`, an empty custom property, keeps
     * its space). A comment between two name characters is kept as an empty
     * comment, so a name, a comment and another name don't merge into one.
     *
     * @param string $css Stylesheet.
     * @return string
     */
    public static function minify($css)
    {
        $css = (string) $css;
        $len = strlen($css);
        $out = '';
        $space = false;
        $name = static function ($char) {
            return '' !== $char && (ctype_alnum($char) || false !== strpos('-_\\%', $char) || ord($char) > 127);
        };

        for ($i = 0; $i < $len;) {
            $c = $css[$i];

            if ('/' === $c && '*' === ($css[$i + 1] ?? '')) {
                $end = strpos($css, '*/', $i + 2);
                if (false === $end) {
                    break; // An unclosed comment runs to the end of the file.
                }
                $i = $end + 2;
                if (! $space && $name(substr($out, -1)) && $name($css[$i] ?? '')) {
                    $out .= '/**/';
                }
                continue;
            }

            if (ctype_space($c)) {
                $space = true;
                $i++;
                continue;
            }

            if ($space) {
                $prev = substr($out, -1);
                $keep = '' !== $out && false === strpos('{};,', $prev) && false === strpos('{};,', $c);
                if (':' === $prev) {
                    $keep = ';' === $c || '}' === $c;
                }
                if ($keep) {
                    $out .= ' ';
                }
                $space = false;
            }

            if ('"' === $c || "'" === $c) {
                $j = $i + 1;
                while ($j < $len && $css[$j] !== $c && "\n" !== $css[$j]) {
                    $j += '\\' === $css[$j] ? 2 : 1;
                }
                $j = min($j, $len);
                $stop = ($j < $len && $css[$j] === $c) ? $j + 1 : $j;
                $out .= substr($css, $i, $stop - $i);
                $i = $stop;
                continue;
            }

            if ('\\' === $c) {
                $j = $i + 1;
                if ($j < $len && ctype_xdigit($css[$j])) {
                    while ($j < $len && $j < $i + 7 && ctype_xdigit($css[$j])) {
                        $j++;
                    }
                    // One whitespace character after a hex escape belongs to it.
                    if ($j < $len && ctype_space($css[$j])) {
                        $j += ("\r" === $css[$j] && "\n" === ($css[$j + 1] ?? '')) ? 2 : 1;
                    }
                } else {
                    $j = min($j + 1, $len);
                }
                $out .= substr($css, $i, $j - $i);
                $i = $j;
                continue;
            }

            if (('u' === $c || 'U' === $c) && 0 === strncasecmp(substr($css, $i, 4), 'url(', 4) && ! $name(substr($out, -1))) {
                $j = $i + 4;
                while ($j < $len && ctype_space($css[$j])) {
                    $j++;
                }
                if ($j < $len && '"' !== $css[$j] && "'" !== $css[$j]) {
                    while ($j < $len && ')' !== $css[$j]) {
                        $j += '\\' === $css[$j] ? 2 : 1;
                    }
                    $j = min($j + 1, $len);
                    $out .= substr($css, $i, $j - $i);
                    $i = $j;
                    continue;
                }
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }
}
