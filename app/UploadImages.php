<?php

namespace App;

/**
 * New uploads: a full AVIF, one small AVIF and one JPEG fallback.
 * WordPress retains the recovery original (see OriginalImageRetention).
 *
 * The files are recorded in the `_wpcu_image_outputs` meta, the same record the
 * WP Cleanup plugin writes when it converts older images, so the theme serves
 * both the same way (ImageDelivery) and WP Cleanup treats new uploads as done.
 */
final class UploadImages
{
    private const META = '_alps_two_size_upload';
    private const OUTPUTS = '_wpcu_image_outputs';
    private static array $files = [];
    /** Set while WordPress makes the small size of a marked upload (it passes no file name then). */
    private static bool $makingSmall = false;

    /** Same defaults as WP Cleanup's image policy. */
    private const DEFAULTS = [
        'full_max' => 1920,
        'small_name' => 'alps-small',
        'small_max' => 768,
        'jpeg_max' => 1920,
        'jpeg_quality' => 82,
        'jpeg_fallback' => true,
        'set_flag' => true,
    ];

    public static function register(): void
    {
        add_action('add_attachment', [self::class, 'mark']);
        add_action('after_setup_theme', static function () {
            $policy = self::policy();
            add_image_size('alps-small', $policy['small_max'], $policy['small_max'], false);
        });
        add_filter('big_image_size_threshold', [self::class, 'threshold'], 100, 4);
        add_filter('image_editor_output_format', [self::class, 'format'], 100, 3);
        add_filter('intermediate_image_sizes_advanced', [self::class, 'sizes'], 100, 3);
        add_filter('wp_generate_attachment_metadata', [self::class, 'finish'], 100, 2);
        add_filter('wp_generate_attachment_metadata', [self::class, 'fallback'], 110, 2);
        add_filter('image_downsize', [self::class, 'downsize'], 10, 3);
        add_filter('wp_update_attachment_metadata', [self::class, 'smallDone'], 1, 2);
        add_action('delete_attachment', [self::class, 'deleteFallback']);
    }

    /** WordPress saves the metadata right after each new size; the small one is then done. */
    public static function smallDone($metadata, $id)
    {
        if (self::$makingSmall && !empty($metadata['sizes']['alps-small'])) {
            self::$makingSmall = false;
        }
        return $metadata;
    }

    /**
     * Sizes and JPEG settings. When the WP Cleanup plugin is active and its saved
     * policy fits ALPS (alps-small, at most 1920/768 px), its settings are used so
     * both describe new uploads identically.
     *
     * @return array{full_max:int,small_name:string,small_max:int,jpeg_max:int,jpeg_quality:int,jpeg_fallback:bool,set_flag:bool}
     */
    public static function policy(): array
    {
        $policy = self::DEFAULTS;
        if (class_exists('\WPCleanup\Media_Policy')) {
            $plugin = \WPCleanup\Media_Policy::settings();
            if (is_array($plugin) && \WPCleanup\Media_Policy::alps_compatible($plugin)) {
                $policy = array_merge($policy, array_intersect_key($plugin, $policy));
            }
        }
        if (function_exists('apply_filters')) {
            $policy = (array) apply_filters('alps_upload_image_policy', $policy);
        }
        $policy['full_max'] = max(320, min(1920, (int) $policy['full_max']));
        $policy['small_max'] = max(64, min(768, $policy['full_max'] - 1, (int) $policy['small_max']));
        $policy['jpeg_max'] = max(320, min(8192, (int) $policy['jpeg_max']));
        $policy['jpeg_quality'] = max(40, min(95, (int) $policy['jpeg_quality']));
        $policy['jpeg_fallback'] = (bool) $policy['jpeg_fallback'];
        $policy['small_name'] = 'alps-small';
        $policy['set_flag'] = (bool) $policy['set_flag'];
        return $policy;
    }

    public static function mark($id): void
    {
        // GIF, animated WebP and other formats retain their original processing.
        if (in_array(get_post_mime_type($id), ['image/jpeg', 'image/png', 'image/avif'], true)) {
            update_post_meta($id, self::META, 1);
        }
    }

    public static function threshold($threshold, $dimensions, $file, $id)
    {
        if (!get_post_meta($id, self::META, true)) {
            return $threshold;
        }
        self::$files[$file] = true;
        return false; // Finalize explicitly: older WordPress skips PNG and small-image conversion.
    }

    public static function format($formats, $file, $mime)
    {
        // WordPress 6.x+ makes sub-sizes without a file name, so a known file alone misses the small size.
        $ours = ($file !== null && $file !== '' && isset(self::$files[$file])) || (self::$makingSmall && !$file);
        if ($ours && in_array($mime, ['image/jpeg', 'image/png'], true)
            && wp_image_editor_supports(['mime_type' => 'image/avif'])) {
            $formats[$mime] = 'image/avif';
        }
        return $formats;
    }

    public static function sizes($sizes, $metadata, $id)
    {
        if (!get_post_meta($id, self::META, true)) {
            return $sizes;
        }
        // Also prepare conversion when WordPress retries missing sub-sizes.
        $file = get_attached_file($id);
        if ($file) {
            self::$files[$file] = true;
        }
        $small = self::policy()['small_max'];
        $outputs = get_post_meta($id, self::OUTPUTS, true);
        $gap = is_array($outputs) ? max(0, min(8192, (int) ($outputs['small_gap_px'] ?? 0))) : 0;
        $longest = max((int) ($metadata['width'] ?? 0), (int) ($metadata['height'] ?? 0));
        if ($gap && $longest > 0 && $longest <= $small + $gap) {
            self::$makingSmall = false;
            return [];
        }
        self::$makingSmall = true;
        return ['alps-small' => ['width' => $small, 'height' => $small, 'crop' => false]];
    }

    public static function finish($metadata, $id)
    {
        if (!get_post_meta($id, self::META, true) || empty($metadata['file'])) {
            return $metadata;
        }
        $file = get_attached_file($id);
        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            return $metadata;
        }
        $max = self::policy()['full_max'];
        $mime = get_post_mime_type($id);
        $output = wp_image_editor_supports(['mime_type' => 'image/avif']) ? 'image/avif' : $mime;
        $large = max($metadata['width'], $metadata['height']) > $max;
        // WordPress 6.x+ already saved a full-size converted copy (name.avif) as the attached
        // file and kept the upload as original_image. That copy is ours to replace or reuse.
        $copy = !empty($metadata['original_image']) && basename((string) $file) !== $metadata['original_image']
            ? (string) $file : '';
        $fileMime = $copy && function_exists('wp_get_image_mime') ? (string) wp_get_image_mime($file) : $mime;
        if (!$large && $fileMime === $output) {
            if ($fileMime !== $mime) {
                wp_update_post(['ID' => $id, 'post_mime_type' => $fileMime]); // The copy is the display image.
            }
            return $metadata;
        }
        $rotated = $editor->maybe_exif_rotate();
        if (is_wp_error($rotated)) {
            return $metadata;
        }
        if ($large && is_wp_error($editor->resize($max, $max, false))) {
            return $metadata;
        }
        $extension = $output === 'image/avif' ? 'avif' : pathinfo($file, PATHINFO_EXTENSION);
        $destination = $editor->generate_filename('display', null, $extension);
        $destination = dirname($destination) . '/' . wp_unique_filename(dirname($destination), basename($destination));
        $saved = $editor->save($destination, $output);
        if (is_wp_error($saved)) {
            return $metadata;
        }
        // Keep the source as WordPress's recoverable original, never delete it here.
        $metadata['original_image'] = $metadata['original_image'] ?? basename($file);
        update_attached_file($id, $saved['path']);
        $metadata['file'] = _wp_relative_upload_path($saved['path']);
        $metadata['width'] = $saved['width'];
        $metadata['height'] = $saved['height'];
        $metadata['filesize'] = $saved['filesize'] ?? wp_filesize($saved['path']);
        if (true === $rotated) {
            $metadata['image_meta']['orientation'] = 1;
        }
        wp_update_post(['ID' => $id, 'post_mime_type' => $saved['mime-type']]);
        // The full-size copy WordPress made is now unused; the upload itself stays as original_image.
        if ($copy && $copy !== $saved['path'] && is_file($copy) && !is_link($copy)) {
            wp_delete_file($copy);
        }
        return $metadata;
    }

    /**
     * After finish(): when the display image is AVIF, make (or keep) one JPEG
     * for browsers without AVIF and record all display files.
     */
    public static function fallback($metadata, $id)
    {
        if (!get_post_meta($id, self::META, true) || empty($metadata['file'])
            || strtolower(pathinfo((string) $metadata['file'], PATHINFO_EXTENSION)) !== 'avif') {
            return $metadata; // Without AVIF the JPEG/PNG display file is its own fallback.
        }
        $uploads = wp_upload_dir(null, false);
        $base = trailingslashit(wp_normalize_path($uploads['basedir']));
        $display = $base . ltrim((string) $metadata['file'], '/');
        $dir = dirname($display);
        $relativeDir = trim(dirname((string) $metadata['file']), './');
        $relative = static fn (string $name): string => ltrim(($relativeDir !== '' ? $relativeDir . '/' : '') . $name, '/');
        $policy = self::policy();

        $previous = get_post_meta($id, self::OUTPUTS, true);
        $previous = is_array($previous) ? $previous : [];
        $jpeg = null;
        if ($policy['jpeg_fallback']) {
            $kept = (string) ($previous['jpeg'] ?? '');
            if ($kept !== '' && is_file($base . $kept) && dirname($base . $kept) === $dir) {
                $info = @getimagesize($base . $kept); // phpcs:ignore -- Local uploads file.
                if ($info && ($info['mime'] ?? '') === 'image/jpeg') {
                    $jpeg = ['file' => $kept, 'width' => (int) $info[0], 'height' => (int) $info[1]];
                }
            }
            if (!$jpeg) {
                $source = $display;
                if (!empty($metadata['original_image']) && is_file($dir . '/' . basename($metadata['original_image']))) {
                    $source = $dir . '/' . basename($metadata['original_image']);
                }
                $made = self::makeJpeg($source, $dir, $display, $policy);
                if ($made) {
                    $jpeg = ['file' => $relative(basename($made['path'])), 'width' => $made['width'], 'height' => $made['height']];
                }
            }
        }

        $small = $metadata['sizes'][$policy['small_name']] ?? null;
        $smallIsAvif = is_array($small) && !empty($small['file'])
            && (($small['mime-type'] ?? '') === 'image/avif' || str_ends_with(strtolower((string) $small['file']), '.avif'));

        update_post_meta($id, self::OUTPUTS, [
            'jpeg' => $jpeg ? $jpeg['file'] : '',
            'jpeg_width' => $jpeg ? $jpeg['width'] : 0,
            'jpeg_height' => $jpeg ? $jpeg['height'] : 0,
            'avif_full' => ltrim((string) $metadata['file'], '/'),
            'avif_full_width' => (int) $metadata['width'],
            'avif_full_height' => (int) $metadata['height'],
            'avif_small' => $smallIsAvif ? $relative((string) $small['file']) : '',
            'avif_small_width' => $smallIsAvif ? (int) $small['width'] : 0,
            'avif_small_height' => $smallIsAvif ? (int) $small['height'] : 0,
            'avif_error' => '',
            // A WP Cleanup selection stays single-size if WordPress regenerates thumbnails.
            'small_gap_px' => $smallIsAvif ? 0 : max(0, min(8192, (int) ($previous['small_gap_px'] ?? 0))),
            'policy' => $policy,
            'by' => 'alps-theme',
        ]);
        if (class_exists(ImageDelivery::class)) {
            ImageDelivery::forget((int) $id);
        }
        return $metadata;
    }

    /**
     * One JPEG of at most jpeg_max px. Transparent PNG/AVIF sources are placed
     * on white, as JPEG has no transparency.
     *
     * @return array{path:string,width:int,height:int}|null
     */
    private static function makeJpeg(string $source, string $dir, string $display, array $policy): ?array
    {
        if (!wp_image_editor_supports(['mime_type' => 'image/jpeg'])) {
            return null;
        }
        $editor = wp_get_image_editor($source);
        if (is_wp_error($editor)) {
            return null;
        }
        if (method_exists($editor, 'maybe_exif_rotate') && is_wp_error($editor->maybe_exif_rotate())) {
            return null;
        }
        $size = $editor->get_size();
        if (max($size['width'], $size['height']) > $policy['jpeg_max']
            && is_wp_error($editor->resize($policy['jpeg_max'], $policy['jpeg_max'], false))) {
            return null;
        }
        $editor->set_quality($policy['jpeg_quality']);
        $name = preg_replace('/-display(?:-\d+)?$/', '', pathinfo($display, PATHINFO_FILENAME)) . '-fallback.jpg';
        $path = $dir . '/' . wp_unique_filename($dir, $name);
        // The format filter above must not turn this JPEG into AVIF.
        $guarded = self::$files;
        self::$files = [];
        $saved = $editor->save($path, 'image/jpeg');
        self::$files = $guarded;
        if (is_wp_error($saved) || ($saved['mime-type'] ?? '') !== 'image/jpeg' || !is_file($saved['path'])) {
            return null;
        }

        $mime = function_exists('wp_get_image_mime') ? wp_get_image_mime($source) : '';
        $load = $mime === 'image/png' ? 'imagecreatefrompng' : ($mime === 'image/avif' ? 'imagecreatefromavif' : '');
        if ($load && function_exists($load)) {
            $image = @$load($source); // phpcs:ignore -- Local uploads file, already decoded by the editor.
            if ($image) {
                $canvas = imagecreatetruecolor((int) $saved['width'], (int) $saved['height']);
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
                imagealphablending($canvas, true);
                imagecopyresampled($canvas, $image, 0, 0, 0, 0, (int) $saved['width'], (int) $saved['height'], imagesx($image), imagesy($image));
                imagejpeg($canvas, $saved['path'], $policy['jpeg_quality']);
                imagedestroy($canvas);
                imagedestroy($image);
            }
        }
        return ['path' => wp_normalize_path($saved['path']), 'width' => (int) $saved['width'], 'height' => (int) $saved['height']];
    }

    /** WordPress deletes the main file and sizes; the JPEG fallback is ours to remove. */
    public static function deleteFallback($id): void
    {
        $outputs = get_post_meta($id, self::OUTPUTS, true);
        $relative = is_array($outputs) ? ltrim(str_replace('\\', '/', (string) ($outputs['jpeg'] ?? '')), '/') : '';
        if ($relative === '' || str_contains($relative, ':') || preg_match('#(^|/)\.\.(/|$)#', $relative)) {
            return;
        }
        $uploads = wp_upload_dir(null, false);
        $path = trailingslashit(wp_normalize_path($uploads['basedir'])) . $relative;
        if (!is_file($path) || is_link($path)) {
            return;
        }
        // Never remove a file that another attachment uses as its own.
        $other = get_posts([
            'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids',
            'post__not_in' => [(int) $id], 'meta_key' => '_wp_attached_file', 'meta_value' => $relative,
        ]);
        if (!$other) {
            wp_delete_file($path);
        }
    }

    public static function downsize($result, $id, $size)
    {
        if ($result || !is_string($size) || !get_post_meta($id, self::META, true)
            || in_array($size, ['full', 'alps-small'], true)) {
            return $result;
        }
        // Keep legacy template and editor names usable without generating their files.
        if (preg_match('/^(featured__hero|horiz__16x9|horiz__4x3|vert__3x4|flex-height|thumbnail)--(s|m|l|xl)$/', $size, $match)) {
            $target = $match[2] === 's' ? 'alps-small' : 'full';
        } elseif (in_array($size, ['thumbnail', 'medium', 'medium_large'], true)) {
            $target = 'alps-small';
        } elseif (in_array($size, ['large', '1536x1536', '2048x2048'], true)) {
            $target = 'full';
        } else {
            return $result;
        }
        return image_downsize($id, $target);
    }
}
