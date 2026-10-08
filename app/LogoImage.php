<?php

namespace App;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Width and height for the theme's SVG logo and icon <img> tags.
 *
 * The header logo is the page's largest element on phones (PageSpeed's LCP
 * element), and without dimensions the browser can neither reserve its space
 * nor size it before the image arrives. SVG uploads often have no size in
 * their attachment metadata, so the viewBox (or width/height) of the file
 * itself is read as a fallback and the result is cached.
 */
class LogoImage
{
    /**
     * @param int|string $attachmentId Attachment ID from the theme options.
     * @return array{0:int,1:int}|null Width and height, or null when unknown.
     */
    public static function size($attachmentId)
    {
        $id = absint($attachmentId);
        if (! $id) {
            return null;
        }
        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta) && ! empty($meta['width']) && ! empty($meta['height'])) {
            return [(int) $meta['width'], (int) $meta['height']];
        }
        $key = 'adventistai_svg_size_' . $id;
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached ?: null;
        }
        $size = self::svgSize((string) get_attached_file($id));
        set_transient($key, $size ?: [], DAY_IN_SECONDS);
        return $size;
    }

    /** Size from an SVG file's width/height or viewBox; null if not an SVG or unknown. */
    public static function svgSize($file)
    {
        if ('' === $file || '.svg' !== strtolower(substr($file, -4)) || ! is_readable($file)) {
            return null;
        }
        $head = (string) file_get_contents($file, false, null, 0, 4096);
        if (! preg_match('/<svg\b[^>]*>/i', $head, $tag)) {
            return null;
        }
        $width = preg_match('/\swidth\s*=\s*["\']?\s*([\d.]+)\s*(px)?["\'\s>]/i', $tag[0], $w) ? (float) $w[1] : 0;
        $height = preg_match('/\sheight\s*=\s*["\']?\s*([\d.]+)\s*(px)?["\'\s>]/i', $tag[0], $h) ? (float) $h[1] : 0;
        if ((! $width || ! $height) && preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*["\']/i', $tag[0], $v)) {
            $width = (float) $v[1];
            $height = (float) $v[2];
        }
        return $width > 0 && $height > 0 ? [(int) round($width), (int) round($height)] : null;
    }

    /**
     * ` width="…" height="…"` (and the LCP hints for the header logo), ready to
     * print inside an <img> tag; '' when the size is unknown.
     */
    public static function attributes($attachmentId, $priority = false)
    {
        $size = self::size($attachmentId);
        $out = $size ? sprintf(' width="%d" height="%d"', $size[0], $size[1]) : '';
        if ($priority) {
            $out .= ' fetchpriority="high" decoding="async"';
        }
        return $out;
    }
}
