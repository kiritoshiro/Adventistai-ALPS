<?php

namespace App;

/** Expire only recoverable sources of attachments managed by UploadImages. */
final class OriginalImageRetention
{
    private const DUE = '_alps_original_delete_after';
    private const SOURCE = '_alps_retained_original';

    public static function register(): void
    {
        add_filter('wp_update_attachment_metadata', [self::class, 'track'], 100, 2);
        add_action('alps_cron', [self::class, 'sweep']);
    }

    public static function track($metadata, $id)
    {
        if (!get_post_meta($id, '_alps_two_size_upload', true)) {
            return $metadata;
        }
        $original = $metadata['original_image'] ?? '';
        if (!$original) {
            delete_post_meta($id, self::DUE);
            delete_post_meta($id, self::SOURCE);
            return $metadata;
        }
        $source = dirname($metadata['file']) . '/' . $original;
        // Metadata refreshes must not extend the same source's retention period.
        if (get_post_meta($id, self::SOURCE, true) !== $source) {
            update_post_meta($id, self::SOURCE, $source);
            update_post_meta($id, self::DUE, time() + WEEK_IN_SECONDS);
        }
        return $metadata;
    }

    public static function sweep(): void
    {
        $ids = get_posts([
            'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids',
            'posts_per_page' => 50, 'meta_key' => self::DUE,
            'meta_value' => time(), 'meta_compare' => '<=', 'meta_type' => 'NUMERIC',
            'orderby' => 'meta_value_num', 'order' => 'ASC',
        ]);
        foreach ($ids as $id) {
            if (!self::deleteExpired($id)) {
                // Retry unsafe/unavailable files later without starving the next batch.
                update_post_meta($id, self::DUE, time() + DAY_IN_SECONDS);
            }
        }
    }

    public static function deleteExpired($id): bool
    {
        $due = (int) get_post_meta($id, self::DUE, true);
        if (!$due || $due > time() || !get_post_meta($id, '_alps_two_size_upload', true)) {
            return false;
        }
        $metadata = wp_get_attachment_metadata($id);
        $original = $metadata['original_image'] ?? '';
        if (!$original) {
            delete_post_meta($id, self::DUE);
            delete_post_meta($id, self::SOURCE);
            return true;
        }
        if (basename($original) !== $original || str_contains($original, '\\')) {
            return false;
        }
        $relative = dirname($metadata['file']) . '/' . $original;
        if ($relative !== get_post_meta($id, self::SOURCE, true)) {
            return false;
        }
        $uploads = wp_get_upload_dir();
        $root = realpath($uploads['basedir']);
        $full = get_attached_file($id, true);
        $directory = realpath(dirname($full));
        if (!$root || !$directory || !str_starts_with(wp_normalize_path($directory) . '/', rtrim(wp_normalize_path($root), '/') . '/')) {
            return false;
        }
        if (realpath($full) !== realpath($uploads['basedir'] . '/' . $metadata['file'])) {
            return false;
        }
        $source = $directory . '/' . $original;
        // Never remove an active file, a symlink, an edited backup or another attachment.
        if (is_link($source) || basename($full) === $original || !is_readable($full) || !wp_getimagesize($full)) {
            return false;
        }
        $backups = get_post_meta($id, '_wp_attachment_backup_sizes', true);
        foreach (array_merge($metadata['sizes'] ?? [], is_array($backups) ? $backups : []) as $size) {
            if (($size['file'] ?? '') === $original) {
                return false;
            }
        }
        $outputs = get_post_meta($id, '_wpcu_image_outputs', true);
        $gap = is_array($outputs) ? max(0, min(8192, (int) ($outputs['small_gap_px'] ?? 0))) : 0;
        $smallLimit = is_array($outputs) && isset($outputs['policy']['small_max'])
            ? (int) $outputs['policy']['small_max'] : 768;
        $longest = max((int) ($metadata['width'] ?? 0), (int) ($metadata['height'] ?? 0));
        if ($longest > $smallLimit && empty($metadata['sizes']['alps-small'])
            && (!$gap || $longest > $smallLimit + $gap)) {
            return false;
        }
        foreach ($metadata['sizes'] ?? [] as $size) {
            $path = $directory . '/' . ($size['file'] ?? '');
            if (!is_readable($path) || !wp_getimagesize($path)) {
                return false;
            }
        }
        if (get_posts([
            'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids',
            'posts_per_page' => 1, 'meta_key' => '_wp_attached_file',
            'meta_value' => ltrim($relative, './'),
        ])) {
            return false;
        }
        if (file_exists($source)) {
            wp_delete_file_from_directory($source, $directory);
            clearstatcache(true, $source);
            if (file_exists($source)) {
                return false;
            }
        }
        unset($metadata['original_image']);
        wp_update_attachment_metadata($id, $metadata);
        delete_post_meta($id, self::DUE);
        delete_post_meta($id, self::SOURCE);
        return true;
    }
}
