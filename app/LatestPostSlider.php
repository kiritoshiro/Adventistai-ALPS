<?php

namespace App;

/**
 * Build the post collections used by the Latest Post Slider page module.
 */
class LatestPostSlider
{
    public const MODULES_FIELD = 'alps_latest_slider_modules';
    public const COLUMNS_FIELD = 'alps_latest_slider_columns';

    /**
     * Return configured slider modules for a page.
     *
     * @param int|null $postId Page ID. The queried object is used when omitted.
     * @return array<int, array<string, mixed>>
     */
    public static function modules(?int $postId = null): array
    {
        $postId = $postId ?: get_queried_object_id();
        $modules = function_exists('carbon_get_post_meta')
            ? carbon_get_post_meta($postId, self::MODULES_FIELD)
            : [];

        if (! is_array($modules)) {
            return [];
        }

        return array_values(array_filter($modules, 'is_array'));
    }

    /**
     * Return the configured number of modules per row.
     */
    public static function columns(?int $postId = null): int
    {
        $postId = $postId ?: get_queried_object_id();
        $columns = function_exists('carbon_get_post_meta')
            ? carbon_get_post_meta($postId, self::COLUMNS_FIELD)
            : 1;

        return min(4, max(1, absint($columns ?: 1)));
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
