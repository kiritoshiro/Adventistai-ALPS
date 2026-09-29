<?php

namespace App;

/** Two display sizes for new uploads; WordPress retains the recovery original. */
final class UploadImages
{
    private const META = '_alps_two_size_upload';
    private static array $files = [];

    public static function register(): void
    {
        add_action('add_attachment', [self::class, 'mark']);
        add_action('after_setup_theme', static function () {
            add_image_size('alps-small', 768, 768, false);
        });
        add_filter('big_image_size_threshold', [self::class, 'threshold'], 100, 4);
        add_filter('image_editor_output_format', [self::class, 'format'], 100, 3);
        add_filter('intermediate_image_sizes_advanced', [self::class, 'sizes'], 100, 3);
        add_filter('wp_generate_attachment_metadata', [self::class, 'finish'], 100, 2);
        add_filter('image_downsize', [self::class, 'downsize'], 10, 3);
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
        if (isset(self::$files[$file]) && in_array($mime, ['image/jpeg', 'image/png'], true)
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
        return ['alps-small' => ['width' => 768, 'height' => 768, 'crop' => false]];
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
        $mime = get_post_mime_type($id);
        $output = wp_image_editor_supports(['mime_type' => 'image/avif']) ? 'image/avif' : $mime;
        $large = max($metadata['width'], $metadata['height']) > 1920;
        if (!$large && $mime === $output) {
            return $metadata;
        }
        $rotated = $editor->maybe_exif_rotate();
        if (is_wp_error($rotated)) {
            return $metadata;
        }
        if ($large && is_wp_error($editor->resize(1920, 1920, false))) {
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
        return $metadata;
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
