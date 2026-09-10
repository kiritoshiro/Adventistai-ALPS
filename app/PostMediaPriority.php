<?php

namespace App;

/** Prioritize media used in the current article without filtering out the library. */
class PostMediaPriority
{
    private static ?array $queryIds = null;

    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_filter('ajax_query_attachments_args', [self::class, 'query']);
        add_filter('posts_orderby', [self::class, 'order'], 10, 2);
        add_filter('wp_prepare_attachment_for_js', [self::class, 'attachment']);
    }

    public static function enqueue($hook): void
    {
        global $post;
        if (!in_array($hook, ['post.php', 'post-new.php'], true) || !$post || !current_user_can('edit_post', $post->ID)) {
            return;
        }
        $path = '/assets/js/post-media-priority.js';
        wp_enqueue_script('alps-post-media-priority', get_template_directory_uri() . $path, ['media-views', 'wp-data'], (string) filemtime(get_template_directory() . $path), true);
        wp_localize_script('alps-post-media-priority', 'alpsPostMedia', [
            'postId' => $post->ID,
            'ids' => self::contentIds($post->post_content, (int) get_post_thumbnail_id($post->ID)),
        ]);
    }

    public static function contentIds(string $content, int $featured = 0): array
    {
        $ids = $featured ? [$featured] : [];
        $walk = function (array $blocks) use (&$walk, &$ids): void {
            foreach ($blocks as $block) {
                $attrs = $block['attrs'] ?? [];
                $key = [
                    'core/image' => 'id', 'core/file' => 'id', 'core/audio' => 'id',
                    'core/video' => 'id', 'core/cover' => 'id', 'core/media-text' => 'mediaId',
                    'alps/wrapped-image-text' => 'imageId',
                ][$block['blockName'] ?? ''] ?? null;
                if ($key && !empty($attrs[$key])) $ids[] = absint($attrs[$key]);
                if (($block['blockName'] ?? '') === 'core/gallery') {
                    $ids = array_merge($ids, (array) ($attrs['ids'] ?? []), array_column((array) ($attrs['images'] ?? []), 'id'));
                }
                $walk($block['innerBlocks'] ?? []);
            }
        };
        $walk(parse_blocks($content));
        preg_match_all('/(?:wp-image-|wp-att-|attachment_)(\d+)/', $content, $matches);
        $ids = array_merge($ids, $matches[1]);
        preg_match_all('/\[gallery[^\]]*\bids=["\']([\d,\s]+)["\']/', $content, $galleries);
        foreach ($galleries[1] as $gallery) $ids = array_merge($ids, explode(',', $gallery));
        preg_match_all('/(?:src|href|imageUrl)=["\']([^"\']+)["\']/', $content, $urls);
        return self::resolveUrls($ids, $urls[1]);
    }

    private static function resolveUrls(array $ids, array $urls): array
    {
        foreach (array_slice(array_unique(array_filter($urls, 'is_string')), 0, 100) as $url) {
            $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
            if (str_starts_with($url, '/')) $url = home_url($url);
            if (wp_parse_url($url, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) continue;
            if (!preg_match('/\.[a-z0-9]{2,8}$/i', (string) wp_parse_url($url, PHP_URL_PATH))) continue;
            $url = strtok($url, '?');
            $id = attachment_url_to_postid($url);
            if (!$id) $id = attachment_url_to_postid(preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url));
            if ($id) $ids[] = $id;
        }
        return array_slice(array_values(array_unique(array_filter(array_map('absint', $ids)))), 0, 500);
    }

    public static function query(array $args): array
    {
        $input = isset($_REQUEST['query']) && is_array($_REQUEST['query']) ? wp_unslash($_REQUEST['query']) : [];
        $postId = absint($input['alps_post_id'] ?? 0);
        if (!$postId || !current_user_can('edit_post', $postId) || ($args['orderby'] ?? 'date') !== 'date' || strtoupper($args['order'] ?? 'DESC') !== 'DESC') return $args;
        $post = get_post($postId);
        if (!$post) return $args;
        if (isset($input['alps_media_ids']) && is_scalar($input['alps_media_ids'])) {
            $ids = explode(',', (string) $input['alps_media_ids']);
            $ids = self::resolveUrls($ids, (array) ($input['alps_media_urls'] ?? []));
        } else {
            $ids = self::contentIds($post->post_content, (int) get_post_thumbnail_id($postId));
        }
        self::$queryIds = array_fill_keys($ids, true);
        $args['alps_priority_ids'] = $ids;
        return $args;
    }

    public static function order(string $order, $query): string
    {
        global $wpdb;
        $ids = array_filter(array_map('absint', (array) $query->get('alps_priority_ids')));
        if (!$ids) return $order;
        // Only validated integers enter the IN clause; retain WP's filtering and pagination.
        return "CASE WHEN {$wpdb->posts}.ID IN (" . implode(',', $ids) . ') THEN 0 ELSE 1 END, ' . ($order ?: "{$wpdb->posts}.post_date DESC");
    }

    public static function attachment(array $response): array
    {
        if (self::$queryIds !== null) $response['alpsUsedInPost'] = isset(self::$queryIds[$response['id']]);
        return $response;
    }
}
