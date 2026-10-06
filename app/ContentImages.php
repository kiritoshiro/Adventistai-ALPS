<?php

namespace App;

/**
 * Hand-written <img> tags in post content (for example a Custom HTML block)
 * have no wp-image-N class and no width or height. WordPress then adds no
 * srcset, sizes or loading attributes, so every such image downloads at full
 * size as soon as the page opens; the front page's book showcase alone was
 * 1.5 MB on a phone.
 *
 * Before core processes the content (wp_filter_content_tags, priority 12),
 * give each such image of this site's uploads its attachment's width and
 * height, plus srcset and sizes. Core then adds loading="lazy" and
 * decoding="async" in page order, keeping the first images eager as it does
 * for images inserted from the media library.
 */
class ContentImages
{
    /** At most this many images are looked up per content string. */
    private const LIMIT = 200;

    private const CACHE_GROUP = 'alps-content-images';

    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    public static function register(): void
    {
        // Priority 11 runs after do_shortcode (also 11, registered by core first)
        // and before wp_filter_content_tags (12).
        add_filter('the_content', [self::class, 'filter'], 11);
    }

    public static function filter($content)
    {
        if (is_admin() || is_feed() || !is_string($content) || stripos($content, '<img') === false) {
            return $content;
        }
        if (!preg_match_all('/<img\b[^>]*>/i', $content, $matches)) {
            return $content;
        }

        $tags = [];
        foreach (array_unique($matches[0]) as $tag) {
            if (count($tags) >= self::LIMIT) {
                break;
            }
            // Images WordPress already knows about, or that already say their size.
            if (preg_match('/\bwp-image-\d+\b|\s(?:width|height|srcset)\s*=/i', $tag)) {
                continue;
            }
            if (!preg_match('/\ssrc\s*=\s*(["\'])([^"\']+)\1/i', $tag, $src)) {
                continue;
            }
            $url = html_entity_decode($src[2], ENT_QUOTES, 'UTF-8');
            $relative = self::relative($url);
            if ($relative !== '') {
                $tags[$tag] = [$url, $relative];
            }
        }
        if (!$tags) {
            return $content;
        }

        $paths = [];
        foreach ($tags as [, $relative]) {
            $paths[] = $relative;
            $paths[] = self::original($relative);
        }
        $ids = self::ids($paths);

        $replace = [];
        foreach ($tags as $tag => [$url, $relative]) {
            $id = $ids[$relative] ?? $ids[self::original($relative)] ?? 0;
            $new = $id ? self::describe($tag, $url, $id) : '';
            if ($new !== '' && $new !== $tag) {
                $replace[$tag] = $new;
            }
        }
        return $replace ? strtr($content, $replace) : $content;
    }

    /** The tag with width, height, srcset and sizes, or '' when the file is not one of the attachment's. */
    private static function describe(string $tag, string $url, int $id): string
    {
        $meta = wp_get_attachment_metadata($id);
        if (!is_array($meta) || empty($meta['width']) || empty($meta['height'])) {
            return '';
        }
        // Matches the URL's file name against the full image and its sizes.
        $dimensions = wp_image_src_get_dimensions($url, $meta, $id);
        if (!$dimensions) {
            return '';
        }
        $tag = (string) preg_replace(
            '/^<img\b/i',
            sprintf('<img width="%d" height="%d"', (int) $dimensions[0], (int) $dimensions[1]),
            $tag,
            1
        );
        return wp_image_add_srcset_and_sizes($tag, $meta, $id);
    }

    /** Path below the uploads directory for a URL of this site's uploads, else ''. */
    public static function relative(string $url): string
    {
        $uploads = wp_get_upload_dir();
        $base = (string) wp_parse_url((string) $uploads['baseurl'], PHP_URL_PATH);
        $host = (string) wp_parse_url((string) $uploads['baseurl'], PHP_URL_HOST);
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            return '';
        }
        if (isset($parts['host']) && strcasecmp($parts['host'], $host) !== 0) {
            return '';
        }
        if (!isset($parts['host']) && (isset($parts['scheme']) || !str_starts_with($parts['path'], '/'))) {
            return '';
        }
        $prefix = rtrim($base, '/') . '/';
        if (!str_starts_with($parts['path'], $prefix)) {
            return '';
        }
        $relative = rawurldecode(substr($parts['path'], strlen($prefix)));
        if ($relative === '' || str_contains($relative, "\0") || preg_match('#(^|/)\.\.(/|$)#', $relative)) {
            return '';
        }
        return preg_match('/\.(?:avif|webp|jpe?g|png|gif)$/i', $relative) ? $relative : '';
    }

    /** The full-size file for a WordPress size's file name ("photo-768x512.jpg" → "photo.jpg"). */
    public static function original(string $relative): string
    {
        return (string) preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $relative);
    }

    /**
     * Attachment IDs by uploads-relative file, in one query for all of a page's images.
     *
     * @param string[] $paths
     * @return array<string, int>
     */
    public static function ids(array $paths): array
    {
        $paths = array_values(array_unique(array_filter($paths, 'strlen')));
        sort($paths);
        if (!$paths) {
            return [];
        }
        $key = 'alps_ci_' . md5(implode("\n", $paths));
        $map = wp_cache_get($key, self::CACHE_GROUP);
        if (!is_array($map)) {
            $map = get_transient($key);
        }
        if (is_array($map)) {
            return $map;
        }

        global $wpdb;
        $placeholders = implode(', ', array_fill(0, count($paths), '%s'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only the %s placeholder list is interpolated; every value is prepared.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ($placeholders) ORDER BY post_id ASC",
                $paths
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $map = [];
        foreach ((array) $rows as $row) {
            $file = (string) ($row['meta_value'] ?? '');
            if ($file !== '' && !isset($map[$file])) {
                $map[$file] = (int) $row['post_id'];
            }
        }
        wp_cache_set($key, $map, self::CACHE_GROUP, self::CACHE_TTL);
        set_transient($key, $map, self::CACHE_TTL);
        return $map;
    }
}
