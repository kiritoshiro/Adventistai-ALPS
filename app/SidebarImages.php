<?php

namespace App;

/**
 * Side-column and footer widget images load lazily.
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
 */
class SidebarImages
{
    /** Widget areas printed above the content: WordPress decides there. */
    public const TOP_AREAS = ['section-page-top', 'category-top'];

    /** @var array<int, bool> One entry per open dynamic_sidebar(): lazy or not. */
    private static array $stack = [];

    public static function register(): void
    {
        add_action('dynamic_sidebar_before', [self::class, 'enter'], 10, 1);
        add_action('dynamic_sidebar_after', [self::class, 'leave'], 10, 0);
        add_filter('pre_wp_get_loading_optimization_attributes', [self::class, 'attributes'], 10, 2);
    }

    /** @param int|string $index The sidebar id dynamic_sidebar() was called with. */
    public static function enter($index = ''): void
    {
        self::$stack[] = ! in_array((string) $index, self::TOP_AREAS, true);
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
        if ('img' !== $tagName || ! end(self::$stack)) {
            return $value;
        }
        return ['decoding' => 'async', 'loading' => 'lazy'];
    }
}
