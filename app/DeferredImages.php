<?php

namespace App;

/**
 * Images printed without their file address until they are about to be seen.
 *
 * loading="lazy" does not help images that sit at the same page height as
 * visible ones but are hidden in some other way: the Latest Post Slider's
 * hidden slides (they fade in place) and the cards of a card scroller beyond
 * the first screen-width of its track. The browser fetched all of them as soon
 * as the block came near. Their files move to data-alps-src /
 * data-alps-srcset instead, and script() restores them when the slide is next
 * in line or the track is scrolled towards the card.
 */
class DeferredImages
{
    /** Whether an image was deferred, so the page needs script(). */
    private static bool $deferred = false;

    /** A 1x1 transparent GIF: a deferred image shows nothing, not its alt text. */
    public const BLANK = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /**
     * The markup with every <img>/<source> file moved to data-alps-src /
     * data-alps-srcset (front-end pages only). On the slider this saved about
     * 0.6 MB on the front page before anything was shown.
     */
    public static function defer(string $html): string
    {
        // Feeds, the REST API (and so the editor's block preview) and wp-admin
        // never print the footer script that restores the files.
        if (is_admin() || is_feed() || (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request())) {
            return $html;
        }
        $deferred = (string) preg_replace_callback('/<(img|source)\b[^>]*>/i', static function (array $tag): string {
            $out = (string) preg_replace('/(\s)(src|srcset)\s*=\s*"/i', '$1data-alps-$2="', $tag[0]);
            if (0 === strcasecmp($tag[1], 'img')) {
                $out = (string) preg_replace('/^<img\b/i', '<img src="' . self::BLANK . '"', $out);
            }
            return $out;
        }, $html);
        if ($deferred !== $html) {
            self::$deferred = true;
        }
        return $deferred;
    }

    /**
     * The script that restores deferred images (assets/js/slider-images.js,
     * named after its first user): the minified copy, or the readable file
     * under SCRIPT_DEBUG or when the copy is missing.
     */
    public static function script(): string
    {
        $dir = get_template_directory() . '/assets/js/';
        $file = $dir . 'slider-images.min.js';
        if ((defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) || !is_readable($file)) {
            $file = $dir . 'slider-images.js';
        }
        return is_readable($file) ? trim((string) file_get_contents($file)) : '';
    }

    /** Print script() in the footer when an image was deferred. */
    public static function printScript(): void
    {
        $script = self::$deferred ? self::script() : '';
        if ('' !== $script) {
            wp_print_inline_script_tag($script);
        }
        self::$deferred = false;
    }
}
