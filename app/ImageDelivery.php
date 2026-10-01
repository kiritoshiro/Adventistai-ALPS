<?php

namespace App;

/**
 * Serve images as AVIF with one JPEG fallback, uncropped.
 *
 * Converted images (new uploads, see UploadImages, and images converted by the
 * WP Cleanup plugin) record their files in the `_wpcu_image_outputs` meta:
 * a full AVIF, an optional small AVIF and one JPEG for browsers without AVIF.
 * Both projects read and write that same record.
 *
 * Images are shown whole. A slot with a fixed shape only fills itself (and
 * trims the edges) when the image's shape is close to the slot's; otherwise
 * the whole image is shown inside the slot.
 */
final class ImageDelivery
{
    public const OUTPUTS = '_wpcu_image_outputs';

    /** Fill a fixed-shape slot only when the shapes differ by at most this factor. */
    public const COVER_TOLERANCE = 1.25;

    /** @var array<int, array|null> */
    private static array $cache = [];

    public static function register(): void
    {
        // Priority 9: WP Cleanup wraps at 10 and leaves existing <picture> markup alone.
        add_filter('wp_get_attachment_image', [self::class, 'filterImage'], 9, 3);
        add_filter('the_content', [self::class, 'content'], 19);
    }

    /**
     * Verified files of a converted image, or null for an unconverted one.
     *
     * @return array{avif: array<int, array{0:string,1:int}>, jpeg: ?array{0:string,1:int,2:int}, width:int, height:int}|null
     */
    public static function outputs(int $id): ?array
    {
        if (array_key_exists($id, self::$cache)) {
            return self::$cache[$id];
        }
        $outputs = get_post_meta($id, self::OUTPUTS, true);
        if (!is_array($outputs) || empty($outputs['avif_full'])) {
            return self::$cache[$id] = null;
        }
        $avif = [];
        foreach (['avif_small', 'avif_full'] as $key) {
            $url = self::url((string) ($outputs[$key] ?? ''));
            $width = (int) ($outputs[$key . '_width'] ?? 0);
            if ($url && $width) {
                $avif[] = [$url, $width];
            }
        }
        if (!$avif) {
            return self::$cache[$id] = null;
        }
        $meta = wp_get_attachment_metadata($id);
        $width = (int) ($outputs['avif_full_width'] ?? ($meta['width'] ?? 0));
        $height = (int) ($outputs['avif_full_height'] ?? ($meta['height'] ?? 0));
        $jpeg = null;
        $jpegUrl = self::url((string) ($outputs['jpeg'] ?? ''));
        if ($jpegUrl) {
            $jpegWidth = (int) ($outputs['jpeg_width'] ?? 0);
            $jpegHeight = (int) ($outputs['jpeg_height'] ?? 0);
            if (!$jpegHeight && $jpegWidth && $width) {
                $jpegHeight = (int) round($jpegWidth * $height / $width);
            }
            $jpeg = [$jpegUrl, $jpegWidth, $jpegHeight];
        }
        return self::$cache[$id] = ['avif' => $avif, 'jpeg' => $jpeg, 'width' => $width, 'height' => $height];
    }

    public static function forget(int $id): void
    {
        unset(self::$cache[$id]);
    }

    /** Public URL of an uploads-relative path whose file exists, else ''. */
    private static function url(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, ':') || preg_match('#(^|/)\.\.(/|$)#', $relative)) {
            return '';
        }
        $uploads = wp_upload_dir(null, false);
        if (!is_file(trailingslashit($uploads['basedir']) . $relative)) {
            return '';
        }
        return trailingslashit($uploads['baseurl']) . str_replace('%2F', '/', rawurlencode($relative));
    }

    /**
     * "cover" when the image's shape is close to the slot's, else "contain".
     *
     * @param float $image Image width / height.
     * @param float $slot  Slot width / height.
     */
    public static function fit(float $image, float $slot): string
    {
        if ($image <= 0 || $slot <= 0) {
            return 'contain';
        }
        $difference = max($image, $slot) / min($image, $slot);
        return $difference <= self::COVER_TOLERANCE ? 'cover' : 'contain';
    }

    /**
     * picture() options for a template slot named after an old ALPS crop size.
     *
     * Card and list slots keep their shape (16:9, 4:3, 3:4, square) so grids
     * stay even; headers and heroes show the image at its own shape, capped in
     * height, instead of the old 3.5:1 hero crops.
     *
     * @param array{header?:bool,round?:bool,alt?:?string,class?:string,sizes?:string} $context
     */
    public static function slot(string $thumbSize, array $context = []): array
    {
        $family = (string) preg_replace('/--(s|m|l|xl)$/', '', $thumbSize);
        $ratios = ['horiz__16x9' => 16 / 9, 'horiz__4x3' => 4 / 3, 'vert__3x4' => 3 / 4, 'thumbnail' => 1.0];
        $options = [];
        if (!empty($context['round'])) {
            // A circle is a deliberate crop.
            $options['ratio'] = 1.0;
            $options['fit'] = 'cover';
        } elseif (empty($context['header']) && isset($ratios[$family])) {
            $options['ratio'] = $ratios[$family];
        } else {
            $options['max'] = !empty($context['header']) ? 'min(80vh, 56rem)' : 'min(90vh, 64rem)';
        }
        if (!empty($context['header'])) {
            $options['priority'] = true;
            $options['sizes'] = '100vw';
        }
        foreach (['alt', 'class', 'sizes'] as $key) {
            if (isset($context[$key]) && $context[$key] !== null && $context[$key] !== '') {
                $options[$key] = $context[$key];
            }
        }
        return $options;
    }

    /**
     * Image markup for the media-block pattern from its template variables,
     * or '' when there is no image attachment (the template then keeps its
     * old markup).
     *
     * @param mixed $thumbId
     * @param mixed $thumbSize
     */
    public static function blockImage($thumbId, $thumbSize = '', array $context = []): string
    {
        $id = is_numeric($thumbId) ? (int) $thumbId : 0;
        if (!$id || !wp_attachment_is_image($id)) {
            return '';
        }
        return self::picture($id, self::slot(is_string($thumbSize) ? $thumbSize : '', $context));
    }

    /**
     * How a page header with a background image should size itself so the
     * image is not cut to a thin band: its own shape for landscape images,
     * whole-image ("contain") for square and portrait ones.
     *
     * @return array{ratio:float,mode:string}|null
     */
    public static function headerFit(int $id): ?array
    {
        [$width, $height] = self::dimensions($id);
        if (!$width || !$height) {
            return null;
        }
        $ratio = $width / $height;
        return ['ratio' => round($ratio, 4), 'mode' => $ratio < 1.2 ? 'contain' : 'cover'];
    }

    /** @return array{0:int,1:int} Width and height of the main image. */
    public static function dimensions(int $id): array
    {
        $outputs = self::outputs($id);
        if ($outputs && $outputs['width'] && $outputs['height']) {
            return [$outputs['width'], $outputs['height']];
        }
        $meta = wp_get_attachment_metadata($id);
        return [(int) ($meta['width'] ?? 0), (int) ($meta['height'] ?? 0)];
    }

    /**
     * Picture markup for one attachment.
     *
     * Options:
     * - class:    extra classes on the <picture>
     * - img_class: classes on the <img>
     * - alt:      alt text (default: the attachment's alt text)
     * - sizes:    sizes attribute (default 100vw)
     * - loading:  "lazy" (default) or "eager"
     * - priority: true for the main image of a page (fetchpriority=high)
     * - ratio:    width/height of a fixed-shape slot; omit to show the image at its own shape
     * - fit:      force "cover" or "contain" in a fixed slot
     * - max:      optional cap on the slot's height (CSS length) when no ratio is given
     */
    public static function picture(int $id, array $options = []): string
    {
        if (!$id || !wp_attachment_is_image($id)) {
            return '';
        }
        [$width, $height] = self::dimensions($id);
        $alt = array_key_exists('alt', $options)
            ? (string) $options['alt']
            : (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        $sizes = (string) ($options['sizes'] ?? '100vw');
        $loading = ($options['loading'] ?? 'lazy') === 'eager' ? 'eager' : 'lazy';

        $classes = ['alps-image'];
        $style = [];
        $ratio = isset($options['ratio']) ? (float) $options['ratio'] : 0.0;
        if ($ratio > 0) {
            $fit = in_array($options['fit'] ?? '', ['cover', 'contain'], true)
                ? $options['fit']
                : self::fit($height ? $width / $height : 0, $ratio);
            $classes[] = 'alps-image--slot';
            $classes[] = 'alps-image--' . $fit;
            $style[] = '--alps-image-ratio:' . round($ratio, 4);
        } else {
            $classes[] = 'alps-image--natural';
            if ($width) {
                // Fill the slot's width, but never stretch past the file's own width.
                $style[] = '--alps-image-w:' . $width . 'px';
            }
            if (!empty($options['max'])) {
                $style[] = '--alps-image-max:' . preg_replace('/[^0-9a-z.,%()+\- ]/i', '', (string) $options['max']);
            }
        }
        if (!empty($options['class'])) {
            $classes[] = (string) $options['class'];
        }

        $img = [
            'class' => trim('alps-image__img ' . ($options['img_class'] ?? '')),
            'alt' => $alt,
            'loading' => $loading,
            'decoding' => 'async',
            'sizes' => $sizes,
        ];
        if (!empty($options['priority'])) {
            $img['loading'] = 'eager';
            $img['fetchpriority'] = 'high';
        }

        $outputs = self::outputs($id);
        if ($outputs) {
            $fallback = $outputs['jpeg'];
            if ($fallback) {
                $img['src'] = $fallback[0];
                $img['srcset'] = $fallback[0] . ($fallback[1] ? ' ' . $fallback[1] . 'w' : '');
            } else {
                // No JPEG: the full AVIF is the only file.
                $full = end($outputs['avif']);
                $img['src'] = $full[0];
            }
            $img['width'] = $width;
            $img['height'] = $height;
            $source = implode(', ', array_map(static fn ($c) => esc_url($c[0]) . ' ' . $c[1] . 'w', $outputs['avif']));
            $html = '<source type="image/avif" srcset="' . esc_attr($source) . '" sizes="' . esc_attr($sizes) . '">'
                . self::tag('img', $img);
        } else {
            // Unconverted image: WordPress's own responsive markup (srcset of its existing sizes).
            $html = wp_get_attachment_image($id, 'full', false, $img);
            if (!$html) {
                return '';
            }
            // Another filter (WP Cleanup) may already have produced a <picture>.
            if (str_contains($html, '<picture')) {
                $html = (string) preg_replace('~^\s*<picture[^>]*>|</picture>\s*$~i', '', $html);
            }
        }

        return '<picture class="' . esc_attr(implode(' ', $classes)) . '"'
            . ($style ? ' style="' . esc_attr(implode(';', $style)) . '"' : '')
            . '>' . $html . '</picture>';
    }

    /** @param array<string, scalar> $attributes */
    private static function tag(string $name, array $attributes): string
    {
        $html = '<' . $name;
        foreach ($attributes as $key => $value) {
            if ($value === '' && $key !== 'alt') {
                continue;
            }
            $value = in_array($key, ['src'], true) ? esc_url((string) $value) : esc_attr((string) $value);
            $html .= ' ' . $key . '="' . $value . '"';
        }
        return $html . '>';
    }

    /**
     * Background image rules for a selector: AVIF where supported, JPEG otherwise,
     * the small AVIF on narrow screens. Browsers without image-set() type() keep the JPEG line.
     */
    public static function backgroundCss(string $selector, int $id): string
    {
        $outputs = self::outputs($id);
        if (!$outputs) {
            // Uncropped; WordPress returns the full image when this size does not exist.
            $url = wp_get_attachment_image_url($id, '1536x1536');
            return $url ? $selector . '{background-image:url("' . esc_url($url) . '")}' : '';
        }
        $full = end($outputs['avif']);
        $small = count($outputs['avif']) > 1 ? $outputs['avif'][0] : null;
        $plain = $outputs['jpeg'] ? $outputs['jpeg'][0] : $full[0];
        $rule = static function (string $avif) use ($selector, $plain): string {
            return $selector . '{background-image:url("' . esc_url($plain) . '");'
                . 'background-image:image-set(url("' . esc_url($avif) . '") type("image/avif"),url("' . esc_url($plain) . '") type("image/jpeg"))}';
        };
        $css = $rule($small ? $small[0] : $full[0]);
        if ($small) {
            $css .= '@media (min-width:' . ((int) $small[1] + 1) . 'px){' . $rule($full[0]) . '}';
        }
        return $css;
    }

    /**
     * Image for social previews (Open Graph): JPEG when there is one, since
     * not every network reads AVIF.
     *
     * @return array{0:string,1:int,2:int}|null
     */
    public static function socialImage(int $id): ?array
    {
        $outputs = self::outputs($id);
        if ($outputs && $outputs['jpeg'] && $outputs['jpeg'][1]) {
            return $outputs['jpeg'];
        }
        $src = wp_get_attachment_image_src($id, 'full');
        return $src ? [(string) $src[0], (int) $src[1], (int) $src[2]] : null;
    }

    /**
     * Wrap wp_get_attachment_image() output (featured images, blocks) in an
     * AVIF + JPEG picture for converted images.
     */
    public static function filterImage($html, $id, $size)
    {
        if (is_admin() || !is_string($html) || !str_contains($html, '<img') || str_contains($html, '<picture')) {
            return $html;
        }
        $outputs = self::outputs((int) $id);
        if (!$outputs) {
            return $html;
        }
        return self::wrap($html, $outputs, $size);
    }

    /** Give converted images saved in post content the same picture markup. */
    public static function content($content)
    {
        if (is_admin() || is_feed() || !is_string($content) || !str_contains($content, 'wp-image-')) {
            return $content;
        }
        return (string) preg_replace_callback('~<picture\b.*?</picture>|<img\b[^>]*>~is', static function ($m) {
            $tag = $m[0];
            if (stripos($tag, '<picture') === 0 || !preg_match('/\bwp-image-(\d+)\b/', $tag, $id)) {
                return $tag;
            }
            $outputs = self::outputs((int) $id[1]);
            if (!$outputs) {
                return $tag;
            }
            $size = preg_match('/\bsize-([a-z0-9_-]+)\b/i', $tag, $named) ? $named[1] : 'full';
            return self::wrap($tag, $outputs, $size);
        }, $content);
    }

    /** @param string|array $size */
    private static function wrap(string $html, array $outputs, $size): string
    {
        $sizes = '100vw';
        if (preg_match('/\bsizes="([^"]+)"/', $html, $match)) {
            $sizes = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/\bwidth="(\d+)"/', $html, $match)) {
            $sizes = '(max-width: ' . (int) $match[1] . 'px) 100vw, ' . (int) $match[1] . 'px';
        }
        if (is_array($size) && !empty($size[0])) {
            $sizes = '(max-width: ' . (int) $size[0] . 'px) 100vw, ' . (int) $size[0] . 'px';
        }
        if ($outputs['jpeg']) {
            $jpeg = $outputs['jpeg'];
            $html = (string) preg_replace('/\ssrc="[^"]*"/i', ' src="' . esc_url($jpeg[0]) . '"', $html, 1);
            $html = (string) preg_replace('/\ssrcset="[^"]*"/i', '', $html);
            // Lazy-load plugins keep the real address in data-* attributes.
            $html = (string) preg_replace('/\sdata-(lazy-)?src="[^"]*"/i', ' data-$1src="' . esc_url($jpeg[0]) . '"', $html);
            $html = (string) preg_replace('/\sdata-(lazy-)?srcset="[^"]*"/i', '', $html);
            $candidate = esc_url($jpeg[0]) . ($jpeg[1] ? ' ' . $jpeg[1] . 'w' : '');
            $html = (string) preg_replace('/<img\s/i', '<img srcset="' . esc_attr($candidate) . '" ', $html, 1);
        }
        $source = implode(', ', array_map(static fn ($c) => esc_url($c[0]) . ' ' . $c[1] . 'w', $outputs['avif']));
        return '<picture class="alps-image alps-image--inline"><source type="image/avif" srcset="' . esc_attr($source)
            . '" sizes="' . esc_attr($sizes) . '">' . $html . '</picture>';
    }
}
