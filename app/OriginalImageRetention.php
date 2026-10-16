<?php

namespace App;

/**
 * Expire only recoverable sources of attachments managed by UploadImages.
 *
 * Appearance → ALPS Theme Settings → Images sets whether they are kept and for
 * how many days (default: kept 7 days). The due date is the time the source was
 * kept plus those days, so a change applies to sources already kept as well.
 */
final class OriginalImageRetention
{
    private const DUE = '_alps_original_delete_after';
    private const SOURCE = '_alps_retained_original';
    private const KEPT = '_alps_original_kept_at';
    /** The settings the due dates were last computed with. */
    private const APPLIED = 'alps_original_retention_applied';
    /** A sweep shortly after an upload when originals are not kept. */
    public const SOON = 'alps_original_sweep';
    /** Set while the settings page writes its options (rows briefly missing). */
    public const SAVING = 'alps_theme_settings_saving';
    private const BATCH = 50;
    /** Appearance → ALPS Theme Settings → Images fields (app/Fields/SettingsPage.php). */
    public const SETTINGS = [
        'keep' => 'alps_image_keep_originals',
        'days' => 'alps_image_original_days',
    ];
    public const DEFAULT_DAYS = 7;

    public static function register(): void
    {
        add_filter('wp_update_attachment_metadata', [self::class, 'track'], 100, 2);
        add_action('alps_cron', [self::class, 'sweep']);
        add_action(self::SOON, [self::class, 'sweep']);
        add_action('alps_theme_settings_saved', [self::class, 'apply']);
    }

    /** Whether originals are kept (a checkbox stored as "_<field>", ticked by default). */
    public static function keeps(): bool
    {
        return get_option('_' . self::SETTINGS['keep'], 'true') === 'true';
    }

    /** Days an original is kept: 1–365, empty or invalid 7. */
    public static function days(): int
    {
        $value = trim((string) get_option('_' . self::SETTINGS['days'], ''));
        return is_numeric($value) ? max(1, min(365, (int) $value)) : self::DEFAULT_DAYS;
    }

    /** When an original was kept; rows from before the setting existed had a fixed week. */
    public static function keptAt($id): int
    {
        $kept = (int) get_post_meta($id, self::KEPT, true);
        if ($kept) {
            return $kept;
        }
        $due = (int) get_post_meta($id, self::DUE, true);
        return $due ? $due - WEEK_IN_SECONDS : 0;
    }

    public static function dueAt($id): int
    {
        return (int) get_post_meta($id, self::DUE, true);
    }

    /** The relative path of the kept original, or ''. */
    public static function source($id): string
    {
        return (string) get_post_meta($id, self::SOURCE, true);
    }

    private static function dueFor(int $kept): int
    {
        return self::keeps() ? $kept + self::days() * DAY_IN_SECONDS : $kept;
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
            delete_post_meta($id, self::KEPT);
            return $metadata;
        }
        $source = dirname($metadata['file']) . '/' . $original;
        // Metadata refreshes must not extend the same source's retention period.
        if (get_post_meta($id, self::SOURCE, true) !== $source) {
            $kept = time();
            update_post_meta($id, self::SOURCE, $source);
            update_post_meta($id, self::KEPT, $kept);
            update_post_meta($id, self::DUE, self::dueFor($kept));
            // WordPress is still making the sizes now; sweep once they exist, not at the
            // next twice-daily run.
            if (!self::keeps() && function_exists('wp_next_scheduled') && !wp_next_scheduled(self::SOON)) {
                wp_schedule_single_event($kept + 5 * MINUTE_IN_SECONDS, self::SOON);
            }
        }
        return $metadata;
    }

    /** Ids of attachments with a kept original, soonest due first. */
    public static function query(array $args = []): array
    {
        // The ID breaks ties, so batches that skip refused rows by offset stay in order.
        return get_posts($args + [
            'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids',
            'posts_per_page' => -1, 'meta_key' => self::DUE, 'meta_type' => 'NUMERIC',
            'orderby' => ['meta_value_num' => 'ASC', 'ID' => 'ASC'],
        ]);
    }

    /** How many originals are kept, without loading their IDs. */
    public static function count(): int
    {
        $query = new \WP_Query([
            'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids',
            'posts_per_page' => 1, 'meta_key' => self::DUE,
        ]);
        return (int) $query->found_posts;
    }

    /**
     * Recomputes the due dates when the settings changed since they were last
     * applied (after saving the settings, and on the list of kept originals).
     */
    public static function apply(): void
    {
        self::recompute(true);
    }

    /**
     * With $earlier false (the sweep) due dates only move later: the sweep can
     * read settings while another tool rewrites them, and must not delete
     * originals early. The settings then count as applied only when no due date
     * was left for apply() to move earlier.
     */
    private static function recompute(bool $earlier): void
    {
        $settings = (self::keeps() ? 'keep' : 'delete') . ':' . self::days();
        if (get_option(self::APPLIED) === $settings) {
            return;
        }
        $skipped = false;
        foreach (self::query() as $id) {
            $kept = self::keptAt($id);
            if (!$kept) {
                continue;
            }
            $due = self::dueFor($kept);
            if (!$earlier && $due < self::dueAt($id)) {
                $skipped = true;
                continue;
            }
            update_post_meta($id, self::KEPT, $kept);
            update_post_meta($id, self::DUE, $due);
        }
        if (!$skipped) {
            update_option(self::APPLIED, $settings, false);
        }
    }

    public static function sweep(): void
    {
        // The settings page is rewriting its options; its saved action applies them.
        if (get_transient(self::SAVING)) {
            return;
        }
        self::recompute(false);
        $due = ['meta_value' => time(), 'meta_compare' => '<='];
        $ids = self::query(['posts_per_page' => self::BATCH] + $due);
        foreach ($ids as $id) {
            if (!self::deleteExpired($id)) {
                // Retry unsafe/unavailable files later without starving the next batch.
                update_post_meta($id, self::DUE, time() + DAY_IN_SECONDS);
            }
        }
        // More are due than one batch takes (e.g. after switching keeping off): go on soon.
        if (count($ids) === self::BATCH && self::query(['posts_per_page' => 1] + $due)
            && !wp_next_scheduled(self::SOON)) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, self::SOON);
        }
    }

    public static function deleteExpired($id): bool
    {
        return self::delete($id, false);
    }

    /** Deletes a kept original before it is due, with every other check of deleteExpired(). */
    public static function deleteNow($id): bool
    {
        return self::delete($id, true);
    }

    private static function delete($id, bool $force): bool
    {
        $due = (int) get_post_meta($id, self::DUE, true);
        if (!$due || (!$force && $due > time()) || !get_post_meta($id, '_alps_two_size_upload', true)) {
            return false;
        }
        $metadata = wp_get_attachment_metadata($id);
        $original = $metadata['original_image'] ?? '';
        if (!$original) {
            self::forget($id);
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
        self::forget($id);
        return true;
    }

    private static function forget($id): void
    {
        delete_post_meta($id, self::DUE);
        delete_post_meta($id, self::SOURCE);
        delete_post_meta($id, self::KEPT);
    }
}
