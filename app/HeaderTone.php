<?php

namespace App;

/**
 * Text colour and dimming for a page header title shown over a blurred copy
 * of its image (resources/views/patterns/02-organisms/sections/page-header).
 *
 * The image is reduced to a few dozen pixels, which is roughly what the
 * blurred backdrop looks like. For white and for dark text, the lightest
 * dimming layer (black under white text, white under dark text) is found
 * that gives every one of those pixels a contrast of at least 4.5:1, apart
 * from the brightest (or darkest) tenth. Large titles need only 3:1, so the
 * rest still pass. The option needing less dimming wins; white is preferred
 * unless dark text needs clearly less.
 *
 * The result is stored on the attachment and recomputed when its file
 * changes, so an image is analysed once.
 */
final class HeaderTone
{
    public const META = '_alps_header_tone';

    /** WCAG AA for normal text; titles (large text) need only 3:1. */
    public const TARGET = 4.5;

    /** Share of the brightest/darkest sample pixels allowed to miss TARGET. */
    public const OUTLIERS = 0.1;

    /** Light (white) text is kept unless dark text needs this much less dimming. */
    public const PREFER_LIGHT = 0.15;

    /** Even a calm image gets a little dimming, so the band reads as a backdrop. */
    public const MIN_ALPHA = 0.12;
    public const MAX_ALPHA = 0.85;

    /** Long edge of the analysed sample, in pixels. */
    private const SAMPLE = 24;

    private const VERSION = 1;

    private const DARK_INK = [17, 17, 17];

    private static bool $styled = false;

    /**
     * The header's CSS (assets/css/page-hero.css), embedded once, only on
     * pages that show such a header: the minified copy, or the readable file
     * under SCRIPT_DEBUG or when the copy is missing.
     */
    public static function stylesheet(): string
    {
        if (self::$styled) {
            return '';
        }
        $dir = get_template_directory() . '/assets/css/';
        $file = $dir . 'page-hero.min.css';
        if ((defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) || !is_readable($file)) {
            $file = $dir . 'page-hero.css';
        }
        $css = is_readable($file) ? trim((string) file_get_contents($file)) : '';
        if ($css === '') {
            return '';
        }
        self::$styled = true;
        return '<style id="alps-page-hero-css">' . str_ireplace('</style', '<\/style', $css) . '</style>';
    }

    /** @return array{ink:string, scrim:string} */
    public static function forAttachment(int $id): array
    {
        $source = self::sourceFile($id);
        if ($source === '') {
            return self::css(self::fallback());
        }
        $signature = self::VERSION . ':' . md5($source . '|' . (int) @filemtime($source) . '|' . (int) @filesize($source));
        $cached = get_post_meta($id, self::META, true);
        if (is_array($cached) && ($cached['sig'] ?? '') === $signature && isset($cached['ink'], $cached['alpha'])) {
            return self::css($cached);
        }
        $pixels = self::samplePixels($source);
        // A file that cannot be read is remembered too, so it is not retried on every view.
        $tone = $pixels ? self::fromPixels($pixels) : self::fallback();
        update_post_meta($id, self::META, $tone + ['sig' => $signature]);
        return self::css($tone);
    }

    /**
     * @param array<int, array{0:int,1:int,2:int}> $pixels sRGB 0-255
     * @return array{ink:string, alpha:float}
     */
    public static function fromPixels(array $pixels): array
    {
        if (!$pixels) {
            return self::fallback();
        }
        $light = self::requiredAlpha($pixels, 'light');
        $dark = self::requiredAlpha($pixels, 'dark');
        return $dark + self::PREFER_LIGHT < $light
            ? ['ink' => 'dark', 'alpha' => $dark]
            : ['ink' => 'light', 'alpha' => $light];
    }

    /**
     * Smallest dimming that lifts all but the worst OUTLIERS share of the
     * pixels to TARGET contrast against the ink.
     *
     * @param array<int, array{0:int,1:int,2:int}> $pixels
     */
    public static function requiredAlpha(array $pixels, string $ink): float
    {
        $needed = [];
        foreach ($pixels as $pixel) {
            $needed[] = self::pixelAlpha($pixel, $ink);
        }
        sort($needed);
        $index = (int) floor((count($needed) - 1) * (1 - self::OUTLIERS));
        return max(self::MIN_ALPHA, $needed[$index]);
    }

    /** @param array{0:int,1:int,2:int} $pixel */
    private static function pixelAlpha(array $pixel, string $ink): float
    {
        for ($step = 0; $step <= 100 * self::MAX_ALPHA; $step++) {
            $alpha = $step / 100;
            if (self::contrast(self::dimmed($pixel, $ink, $alpha), $ink) >= self::TARGET) {
                return $alpha;
            }
        }
        return self::MAX_ALPHA;
    }

    /**
     * The pixel seen through the dimming layer, composited the way browsers
     * do (in sRGB): black under light text, white under dark text.
     *
     * @param array{0:int,1:int,2:int} $pixel
     * @return array{0:float,1:float,2:float}
     */
    public static function dimmed(array $pixel, string $ink, float $alpha): array
    {
        $layer = $ink === 'light' ? 0 : 255;
        return [
            $pixel[0] * (1 - $alpha) + $layer * $alpha,
            $pixel[1] * (1 - $alpha) + $layer * $alpha,
            $pixel[2] * (1 - $alpha) + $layer * $alpha,
        ];
    }

    /** @param array{0:float,1:float,2:float} $background */
    public static function contrast(array $background, string $ink): float
    {
        $text = $ink === 'light' ? 1.0 : self::luminance(self::DARK_INK);
        $back = self::luminance($background);
        return (max($text, $back) + 0.05) / (min($text, $back) + 0.05);
    }

    /** WCAG relative luminance of an sRGB colour (0-255 channels). */
    public static function luminance(array $rgb): float
    {
        $linear = array_map(static function ($channel): float {
            $c = $channel / 255;
            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, array_slice($rgb, 0, 3));
        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /** @param array{ink:string, alpha:float} $tone */
    public static function css(array $tone): array
    {
        $alpha = rtrim(rtrim(number_format((float) $tone['alpha'], 2, '.', ''), '0'), '.');
        return $tone['ink'] === 'dark'
            ? ['ink' => '#111', 'scrim' => 'rgba(255,255,255,' . $alpha . ')']
            : ['ink' => '#fff', 'scrim' => 'rgba(0,0,0,' . $alpha . ')'];
    }

    /** White text on a fairly strong dimming: safe for any image. */
    private static function fallback(): array
    {
        return ['ink' => 'light', 'alpha' => 0.55];
    }

    /**
     * Pixels of a GD image, reduced so its long edge is SAMPLE pixels.
     *
     * @param \GdImage $image
     * @return array<int, array{0:int,1:int,2:int}>
     */
    public static function pixelsFromGd($image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 1 || $height < 1) {
            return [];
        }
        $scale = min(1, self::SAMPLE / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $small = imagecreatetruecolor($w, $h);
        // Averages the source pixels, like the blur does.
        imagecopyresampled($small, $image, 0, 0, 0, 0, $w, $h, $width, $height);
        $pixels = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $pixels[] = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
            }
        }
        return $pixels;
    }

    /**
     * A small readable copy of the image: GD reads JPEG/PNG/WebP itself; any
     * other format (AVIF) goes through WordPress' image editor first.
     *
     * @return array<int, array{0:int,1:int,2:int}>
     */
    private static function samplePixels(string $file): array
    {
        if (!function_exists('imagecreatefromstring')) {
            return [];
        }
        $image = null;
        if (preg_match('/\.(jpe?g|png|webp)$/i', $file)) {
            $image = @imagecreatefromstring((string) file_get_contents($file));
        } elseif (function_exists('wp_get_image_editor')) {
            $editor = wp_get_image_editor($file);
            if (!is_wp_error($editor) && !is_wp_error($editor->resize(self::SAMPLE * 4, self::SAMPLE * 4))) {
                $temp = wp_tempnam('alps-tone') . '.png';
                $saved = $editor->save($temp, 'image/png');
                if (!is_wp_error($saved) && is_file($saved['path'])) {
                    $image = @imagecreatefromstring((string) file_get_contents($saved['path']));
                    wp_delete_file($saved['path']);
                }
                wp_delete_file($temp);
            }
        }
        if (!$image) {
            return [];
        }
        return self::pixelsFromGd($image);
    }

    /** The smallest local file of the image: JPEG fallback, small AVIF, then the attachment. */
    private static function sourceFile(int $id): string
    {
        $outputs = get_post_meta($id, ImageDelivery::OUTPUTS, true);
        $uploads = wp_upload_dir(null, false);
        if (is_array($outputs) && !empty($uploads['basedir'])) {
            foreach (['jpeg', 'avif_small', 'avif_full'] as $key) {
                $relative = ltrim(str_replace('\\', '/', (string) ($outputs[$key] ?? '')), '/');
                if ($relative === '' || str_contains($relative, ':') || preg_match('#(^|/)\.\.(/|$)#', $relative)) {
                    continue;
                }
                $file = trailingslashit($uploads['basedir']) . $relative;
                if (is_file($file)) {
                    return $file;
                }
            }
        }
        $file = (string) get_attached_file($id);
        return $file !== '' && is_file($file) ? $file : '';
    }
}
