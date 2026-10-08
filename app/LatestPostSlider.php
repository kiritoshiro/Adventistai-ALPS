<?php

namespace App;

/**
 * Build the post collections used by the Latest Post Slider page module.
 */
class LatestPostSlider
{
    private static bool $eagerImageClaimed = false;

    /**
     * True only for the first slide image rendered on the page. Every slider
     * shows its first slide, but only the page's first slider can be near the
     * top; the others load their images lazily like the rest of the page.
     */
    public static function claimEagerImage(): bool
    {
        if (self::$eagerImageClaimed) {
            return false;
        }
        self::$eagerImageClaimed = true;
        return true;
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
