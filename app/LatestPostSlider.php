<?php

namespace App;

/**
 * Build the post collections used by the Latest Post Slider page module.
 */
class LatestPostSlider
{
    private static bool $eagerSectionClaimed = false;

    /** The priority preload waiting for wp_head. */
    private static string $headPreload = '';

    /**
     * True only for the first slider section rendered on the page. Its first
     * row of sliders (one per column) can be near the top on wide screens, so
     * the largest of their first slide images is preloaded there.
     */
    public static function claimEagerSection(): bool
    {
        if (self::$eagerSectionClaimed) {
            return false;
        }
        self::$eagerSectionClaimed = true;
        return true;
    }

    /**
     * Share of the 16:9 frame an image of this size covers once fitted in it
     * (1 for a 16:9 image, about 0.42 for a 3:4 portrait).
     */
    public static function frameShare(int $width, int $height): float
    {
        if ($width <= 0 || $height <= 0) {
            return 0.0;
        }
        $ratio = ($width / $height) / (16 / 9);
        return min($ratio, 1 / $ratio);
    }

    /**
     * Which of the first images in a row of sliders is drawn largest, and so
     * is the likely Largest Contentful Paint: it gets fetchpriority="high".
     * Sliders in a row share the frame size. -1 when none has an image.
     *
     * @param array<int, array{0: int, 1: int}|null> $sizes Width and height per slider, null without an image.
     */
    public static function priorityIndex(array $sizes): int
    {
        $best = -1;
        $bestShare = 0.0;
        foreach ($sizes as $index => $size) {
            $share = is_array($size) ? self::frameShare((int) $size[0], (int) $size[1]) : 0.0;
            if ($share > $bestShare) {
                $best = (int) $index;
                $bestShare = $share;
            }
        }
        return $best;
    }

    /**
     * Viewports where the first row of sliders is near the top. On phones
     * the slider sits below other content (a video, the Sabbath timer), and
     * a high-priority slide took bandwidth from the image that was really
     * the Largest Contentful Paint there.
     */
    public const PRIORITY_MEDIA = '(min-width: 1001px)';

    /**
     * A preload link that gives a slide image high priority on wide screens
     * only: it repeats the AVIF (or the image's own) srcset and sizes, so the
     * browser picks the same file the <picture> will use. '' when the markup
     * has no image.
     */
    public static function priorityPreload(string $html): string
    {
        $attribute = static function (string $tag, string $name): string {
            return preg_match('/\s' . $name . '\s*=\s*"([^"]*)"/i', $tag, $m)
                ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : '';
        };
        $type = '';
        if (preg_match('/<source\b[^>]*\btype\s*=\s*"image\/avif"[^>]*>/i', $html, $tag)) {
            $type = 'image/avif';
        } elseif (! preg_match('/<img\b[^>]*>/i', $html, $tag)) {
            return '';
        }
        $srcset = $attribute($tag[0], 'srcset');
        $src = $attribute($tag[0], 'src');
        if ($srcset === '' && $src === '') {
            return '';
        }

        $link = [
            'rel' => 'preload',
            'as' => 'image',
            'href' => $srcset === '' ? $src : '',
            'imagesrcset' => $srcset,
            'imagesizes' => $srcset === '' ? '' : $attribute($tag[0], 'sizes'),
            'type' => $type,
            'media' => self::PRIORITY_MEDIA,
            'fetchpriority' => 'high',
        ];
        $out = '<link';
        foreach (array_filter($link, static fn (string $value): bool => $value !== '') as $name => $value) {
            $out .= ' ' . $name . '="' . ($name === 'href' ? esc_url($value) : esc_attr($value)) . '"';
        }
        return $out . '>';
    }

    /**
     * Keep the slide's preload for <head>, where the browser finds it while
     * the page is still downloading; next to the slide it came about 290 KB
     * into the front page's HTML. Blade renders a template's content section
     * before the layout that calls wp_head(), so the slider normally runs
     * first. Returns the link for the slide itself when wp_head has already
     * run.
     */
    public static function queuePreload(string $html): string
    {
        $preload = self::priorityPreload($html);
        if (did_action('wp_head')) {
            return $preload;
        }
        self::$headPreload = $preload;
        return '';
    }

    /**
     * Print the queued preload (wp_head, priority 2: before the stylesheets).
     */
    public static function printHeadPreload(): void
    {
        if ('' !== self::$headPreload) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- priorityPreload() escapes every attribute value.
            echo self::$headPreload . "\n";
            self::$headPreload = '';
        }
    }

    /** Whether a slide image was deferred, so the page needs deferScript(). */
    private static bool $deferred = false;

    /** A 1x1 transparent GIF: a hidden slide shows nothing, not its alt text. */
    public const BLANK = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /**
     * A hidden slide's image without its address: the files move to
     * data-alps-src / data-alps-srcset, which deferScript() restores once the
     * slide is next in line (front-end pages only). Hidden slides are laid out (they fade in place),
     * so loading="lazy" alone fetched every slide of a slider near the top:
     * about 0.6 MB on the front page before anything was shown.
     */
    public static function deferImage(string $html): string
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
     * The script that restores deferred slide images (assets/js/slider-images.js):
     * the minified copy, or the readable file under SCRIPT_DEBUG or when the
     * copy is missing.
     */
    public static function deferScript(): string
    {
        $dir = get_template_directory() . '/assets/js/';
        $file = $dir . 'slider-images.min.js';
        if ((defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) || !is_readable($file)) {
            $file = $dir . 'slider-images.js';
        }
        return is_readable($file) ? trim((string) file_get_contents($file)) : '';
    }

    /** Print deferScript() in the footer when a slide image was deferred. */
    public static function printDeferScript(): void
    {
        $script = self::$deferred ? self::deferScript() : '';
        if ('' !== $script) {
            wp_print_inline_script_tag($script);
        }
        self::$deferred = false;
    }

    /**
     * The `sizes` value for a slide image: how wide it is actually drawn.
     *
     * The frame is 16:9 (4:3 when a one-column slider puts the image beside
     * the text) and the image is fitted inside it, so a portrait cover is
     * drawn much narrower than the frame. Frame widths measured on the site:
     * at most 100vw minus the page padding on phones, about 560px for a
     * one-column slider and 840px shared by the columns on wide screens.
     * WordPress' default (the full file width, up to 1000px) made browsers
     * download 743-1000px files for 160-400px frames.
     */
    public static function imageSizes(int $columns, int $width, int $height): string
    {
        $columns = max(1, min(4, $columns));
        $fit = static function (float $frameRatio) use ($width, $height): float {
            return $width > 0 && $height > 0 ? min(1.0, $frameRatio * $width / $height) : 1.0;
        };
        $wide = $fit(9 / 16);
        $scale = static function (string $expression, float $factor): string {
            return $factor >= 1.0 ? 'calc(' . $expression . ')' : sprintf('calc((%s) * %.3F)', $expression, $factor);
        };
        $px = static fn (float $value): string => max(1, (int) round($value)) . 'px';

        $parts = ['(max-width: 700px) ' . $scale('100vw - 34px', $wide)];
        if (1 === $columns) {
            // Stacked below 560px of slider width, otherwise 40% beside the text.
            $parts[] = $px(max(560 * $wide, 380 * $fit(3 / 4)));
        } else {
            $narrow = 4 === $columns ? 2 : $columns;
            $parts[] = '(max-width: 1000px) ' . $scale('(85.71vw - 60px) / ' . $narrow, $wide);
            // ALPS shows four columns as two up to 1100px.
            if (4 === $columns) {
                $parts[] = '(max-width: 1100px) ' . $px(250 * $wide);
            }
            $parts[] = $px(840 / $columns * $wide);
        }

        return implode(', ', $parts);
    }

    /**
     * Resolve one configured module to published posts/pages.
     *
     * @param array<string, mixed> $module
     * @return array<int, \WP_Post>
     */
    public static function posts(array $module): array
    {
        $source = sanitize_key((string) ($module['alps_latest_slider_source'] ?? 'latest'));

        if ($source === 'custom') {
            return self::selectedPosts($module['alps_latest_slider_items'] ?? []);
        }

        $count = min(20, max(1, absint($module['alps_latest_slider_count'] ?? 5)));
        $query = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $count,
            'orderby' => 'date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        ];

        if ($source === 'category') {
            $categoryIds = self::associationIds($module['alps_latest_slider_category'] ?? []);
            if (empty($categoryIds)) {
                return [];
            }

            $query['category__in'] = [reset($categoryIds)];
        }

        $posts = get_posts($query);

        return is_array($posts) ? $posts : [];
    }

    /**
     * Extract IDs from Carbon Fields association values.
     *
     * @param mixed $items
     * @return int[]
     */
    public static function associationIds($items): array
    {
        $ids = [];

        foreach ((array) $items as $item) {
            if (is_numeric($item)) {
                $id = absint($item);
            } elseif (is_array($item)) {
                $id = absint($item['id'] ?? $item['value'] ?? 0);
            } elseif (is_object($item)) {
                $id = absint($item->id ?? 0);
            } else {
                $id = 0;
            }

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Preserve the editorial order of manually selected published posts/pages.
     *
     * @param mixed $items
     * @return array<int, \WP_Post>
     */
    private static function selectedPosts($items): array
    {
        $posts = [];

        foreach (self::associationIds($items) as $id) {
            $post = get_post($id);

            if (! $post || $post->post_status !== 'publish' || ! in_array($post->post_type, ['post', 'page'], true)) {
                continue;
            }

            $posts[] = $post;
        }

        return $posts;
    }
}
