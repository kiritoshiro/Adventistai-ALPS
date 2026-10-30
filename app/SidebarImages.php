<?php

namespace App;

/**
 * Side-column and footer widget images load lazily, at their real width.
 *
 * WordPress leaves the first images it meets on a page without
 * loading="lazy", counting them as above the fold. The side column is
 * printed before the article ends, so its image widgets and the images in its
 * block widgets were among them, yet on phones it comes after the whole
 * article (on the front page 7,800-9,300px down) and they were downloaded
 * with the first screen.
 *
 * Inside dynamic_sidebar() every image is lazy, except in the widget areas
 * printed above the content (TOP_AREAS), whose images can be the first thing
 * on screen. The answer is given through the pre_ filter, so these images
 * neither take the page's one fetchpriority="high" nor count towards the
 * images WordPress keeps eager.
 *
 * Images in the side column and the footer areas also get a `sizes` for the
 * width they are drawn at (areaSizes()). WordPress's default says the full
 * file width up to the whole screen, e.g. "(max-width: 1280px) 100vw, 1280px",
 * and desktop browsers fetched 1000-1892px files for a column about 320px
 * wide.
 */
class SidebarImages
{
    /** Widget areas printed above the content: WordPress decides there. */
    public const TOP_AREAS = ['section-page-top', 'category-top'];

    /** The side column beside the article (page, posts and archive templates). */
    public const SIDE_AREAS = ['sidebar-page', 'sidebar-posts'];

    /** The footer widgets and the widgets under a post. */
    public const FOOTER_AREAS = ['footer-region', 'footer-region-post'];

    /**
     * How wide an area is drawn, as [up to this viewport width or null for
     * the rest, vw share, px taken off], from site-overrides.css and
     * responsive-zoom-fixes.css. The content column is the viewport less the
     * Sabbath rail (85.71vw) above 700px, capped at 90rem; the side column is
     * 38% of it above 1000px and the full column below; each column loses the
     * page gutter, clamp(1rem, 2.2vw, 1.75rem), on both sides. The page and
     * archive templates' side column also has an inner u-padding--right
     * (15px, 20px above 900px); a single post's has not ('single-side').
     * Widgets under a post ('post') share the article's 62% beside a side
     * column; without one they are as wide as the footer.
     */
    public const LAYOUTS = [
        'side' => [[700, 100, 47], [900, 81.31, 15], [1000, 81.31, 20], [1272, 28.17, 20], [1680, 32.57, 76], [null, 0, -472]],
        'single-side' => [[700, 100, 32], [1000, 81.31, 0], [1272, 28.17, 0], [1680, 32.57, 56], [null, 0, -492]],
        'post' => [[700, 100, 32], [1000, 81.31, 0], [1272, 48.74, 0], [1680, 53.14, 56], [null, 0, -837]],
        'footer' => [[700, 100, 32], [1272, 81.31, 0], [1680, 85.71, 56], [null, 0, -1384]],
        // Sidebar position "Left side column" (sidebar-rail.css), above 1000px:
        // the column is clamp(17rem, 17vw, 22rem) less its 1.25rem padding on
        // both sides (its inner padding is removed); the article beside it is
        // the content column less the side column and both gutters, at most
        // 90rem. Up to 1000px the same as without it.
        'rail-side' => [[700, 100, 47], [900, 81.31, 15], [1000, 81.31, 20], [1600, 0, -232], [2071, 17, 40], [null, 0, -312]],
        'rail-single-side' => [[700, 100, 32], [1000, 81.31, 0], [1600, 0, -232], [2071, 17, 40], [null, 0, -312]],
        'rail-post' => [[700, 100, 32], [1000, 81.31, 0], [1272, 81.31, 272], [1600, 85.71, 328], [2071, 68.71, 56], [2156, 85.71, 408], [null, 0, -1440]],
        // "Left side column, scaled like the Sabbath column" (sidebar-rail-scaled.css),
        // above 700px: a seventh of the window, laid out as 17rem less its padding
        // (14.5rem) and zoomed to fit, so 14.5/17 of 14.29vw; the article beside it
        // is the rest of the content column (71.43vw) less both gutters, at most 90rem.
        'rail-scaled-side' => [[700, 100, 47], [null, 12.19, 0]],
        'rail-scaled-single-side' => [[700, 100, 32], [null, 12.19, 0]],
        'rail-scaled-post' => [[700, 100, 32], [727, 71.43, 32], [1272, 67.03, 0], [2094, 71.43, 56], [null, 0, -1440]],
    ];

    /** On a single post (single()): whether its side column is shown; else null. */
    private static ?bool $singleSidebar = null;

    /** @var array<int, string> One entry per open dynamic_sidebar(): its id. */
    private static array $stack = [];

    public static function register(): void
    {
        add_action('dynamic_sidebar_before', [self::class, 'enter'], 10, 1);
        add_action('dynamic_sidebar_after', [self::class, 'leave'], 10, 0);
        add_filter('pre_wp_get_loading_optimization_attributes', [self::class, 'attributes'], 10, 2);
        // Image widgets: before ImageDelivery (9) copies the sizes to the AVIF <source>.
        add_filter('wp_get_attachment_image', [self::class, 'sizes'], 8, 1);
        // Image blocks in block widgets.
        add_filter('wp_content_img_tag', [self::class, 'sizes'], 10, 1);
    }

    /** @param int|string $index The sidebar id dynamic_sidebar() was called with. */
    public static function enter($index = ''): void
    {
        self::$stack[] = (string) $index;
    }

    public static function leave(): void
    {
        array_pop(self::$stack);
    }

    /**
     * @param array<string, string>|false $value False lets WordPress decide.
     * @return array<string, string>|false
     */
    public static function attributes($value, $tagName)
    {
        $area = end(self::$stack);
        if ('img' !== $tagName || false === $area || in_array($area, self::TOP_AREAS, true)) {
            return $value;
        }
        return ['decoding' => 'async', 'loading' => 'lazy'];
    }

    /**
     * Image markup with WordPress's default sizes replaced by the current
     * area's (areaSizes()). Sizes set on purpose are kept.
     *
     * @param mixed $html
     * @return mixed
     */
    public static function sizes($html)
    {
        $area = end(self::$stack);
        $layout = false === $area ? '' : self::layout($area);
        if ('' === $layout || !is_string($html) || false === stripos($html, 'sizes=')) {
            return $html;
        }
        // WordPress's default, with the "auto" it adds to lazy images: that
        // stands for the <img>'s layout width, which a <source> may not take.
        return (string) preg_replace_callback(
            '/(\ssizes\s*=\s*")(?:auto\s*,\s*)?\(max-width:\s*(\d+)px\)\s*100vw\s*,\s*(\d+)px(")/i',
            static function (array $m) use ($layout): string {
                return $m[2] === $m[3] ? $m[1] . self::areaSizes($layout, (int) $m[2]) . $m[4] : $m[0];
            },
            $html
        );
    }

    /**
     * Called by the single post template (content-single.blade.php) before
     * it prints the article: whether the side column is shown beside it.
     */
    public static function single(bool $sidebar): void
    {
        self::$singleSidebar = $sidebar;
    }

    /**
     * A LAYOUTS key, or '' (left to WordPress), for a widget area id.
     * $position: the Sidebar position (null: read the setting).
     */
    public static function layout(string $area, ?string $position = null): string
    {
        if (null === $position) {
            $position = class_exists(SidebarPosition::class) ? SidebarPosition::position() : '';
        }
        $prefix = in_array($position, ['rail', 'rail-scaled'], true) ? $position . '-' : '';
        if ('sidebar-posts' === $area && null !== self::$singleSidebar) {
            return $prefix . 'single-side';
        }
        if (in_array($area, self::SIDE_AREAS, true)) {
            return $prefix . 'side';
        }
        if ('footer-region-post' === $area && true === self::$singleSidebar) {
            return $prefix . 'post';
        }
        return in_array($area, self::FOOTER_AREAS, true) ? 'footer' : '';
    }

    /**
     * The sizes value for an image in a 'side' or 'footer' area, never wider
     * than the image's own width (0: unknown).
     */
    public static function areaSizes(string $layout, int $width = 0): string
    {
        $value = static function (float $vw, int $px): string {
            if ($vw <= 0) {
                return -$px . 'px';
            }
            $share = rtrim(rtrim(sprintf('%.2F', $vw), '0'), '.') . 'vw';
            return 0 === $px ? $share : 'calc(' . $share . ' - ' . $px . 'px)';
        };
        $parts = [];
        $add = static function (?int $upTo, string $size) use (&$parts): void {
            $last = count($parts) - 1;
            if ($last >= 0 && $parts[$last][1] === $size) {
                $parts[$last][0] = $upTo;
            } else {
                $parts[] = [$upTo, $size];
            }
        };
        $from = 0;
        foreach (self::LAYOUTS[$layout] ?? [] as [$upTo, $vw, $px]) {
            $end = $upTo ?? PHP_INT_MAX;
            // The widest viewport at which this column is no wider than the image.
            $fits = PHP_INT_MAX;
            if ($width > 0) {
                $fits = $vw > 0 ? (int) floor(($width + $px) * 100 / $vw) : ($width >= -$px ? PHP_INT_MAX : 0);
            }
            if ($fits > $from) {
                $add(min($fits, $end) === PHP_INT_MAX ? null : min($fits, $end), $value($vw, $px));
            }
            if ($fits < $end) {
                $add($upTo, $width . 'px');
            }
            $from = $end;
        }

        $out = [];
        foreach ($parts as [$upTo, $size]) {
            $out[] = null === $upTo ? $size : '(max-width: ' . $upTo . 'px) ' . $size;
        }
        return implode(', ', $out);
    }
}
