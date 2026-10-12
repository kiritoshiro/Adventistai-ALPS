<?php

namespace App;

/**
 * Sidebar images load lazily.
 *
 * WordPress leaves the first images it meets on a page without
 * loading="lazy", counting them as above the fold. The sidebar is printed
 * before the article ends, so its image widgets and the images in its block
 * widgets were among them, yet on phones the sidebar comes after the whole
 * article (on the front page 7,800-9,300px down) and they were downloaded
 * with the first screen. Inside dynamic_sidebar() every image is lazy and
 * none gets high priority.
 */
class SidebarImages
{
    private static int $depth = 0;

    public static function register(): void
    {
        add_action('dynamic_sidebar_before', [self::class, 'enter'], 10, 0);
        add_action('dynamic_sidebar_after', [self::class, 'leave'], 10, 0);
        add_filter('wp_get_loading_optimization_attributes', [self::class, 'attributes'], 10, 2);
    }

    public static function enter(): void
    {
        self::$depth++;
    }

    public static function leave(): void
    {
        self::$depth = max(0, self::$depth - 1);
    }

    /**
     * @param array<string, string> $attributes
     * @return array<string, string>
     */
    public static function attributes($attributes, $tagName): array
    {
        $attributes = is_array($attributes) ? $attributes : [];
        if (self::$depth === 0 || 'img' !== $tagName) {
            return $attributes;
        }
        unset($attributes['fetchpriority']);
        $attributes['loading'] = 'lazy';
        return $attributes;
    }
}
