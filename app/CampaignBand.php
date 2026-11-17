<?php
namespace App;

/**
 * The "Visa darau nauja" band at the top of the front page (3.42.0):
 * Appearance → ALPS Theme Settings → VISA DARAU NAUJA. Off by default, so
 * other sites on this theme see nothing until it is switched on.
 *
 * Two photos side by side, "old" (left) and "new" (right), the wordmark over
 * them as the page's one h1, then the tagline, the verse and up to two
 * buttons on the "new" half. Markup: resources/views/partials/campaign-band.blade.php,
 * styles: assets/css/campaign-band.css (embedded, front page only, with the
 * Figtree faces below).
 */
final class CampaignBand
{
    /** Texts shown when a field is left empty (or nothing was saved yet). */
    public const DEFAULTS = [
        'campaign_band_line_old' => 'Visa darau',
        'campaign_band_line_new' => 'nauja',
        'campaign_band_tagline' => 'Viltis prasideda čia.',
        'campaign_band_verse' => '„Štai aš visa darau nauja!“',
        'campaign_band_verse_reference' => 'Apr 21, 5',
        'campaign_band_button_1_label' => 'Sužinokite daugiau',
        'campaign_band_button_2_label' => 'Rasti bendruomenę',
    ];

    /** The theme's own photos (assets/images/visa-darau-nauja/), by side. */
    private const IMAGES = [
        'old' => ['file' => 'old', 'width' => 470, 'height' => 360, 'phone' => [390, 780]],
        'new' => ['file' => 'new', 'width' => 764, 'height' => 360, 'phone' => [390, 780]],
    ];

    /**
     * What each image is drawn at above 699px: its share (38 % / 62 %) of the
     * content left of the Sabbath column (6/7 of the window), a little under,
     * so a 1440px window takes the 470/764px files at 1x. Phones get their
     * own crops through <source>, drawn full width.
     */
    private const SIZES = ['old' => '32.6vw', 'new' => '53vw'];

    /** The faces the band uses, from assets/fonts/figtree/ (Latin + Lithuanian subsets). */
    private const FONTS = [800 => 'Figtree-ExtraBold.woff2', 500 => 'Figtree-Medium.woff2'];

    public static function enabled(): bool
    {
        return (bool) self::option('campaign_band_show');
    }

    /**
     * Whether this request shows the band: the front page, with the band on.
     * The one decision for both the markup (page-header-hero.blade.php for a
     * static front page, whatever its header or hero layout;
     * page-header-v2.blade.php for a latest-posts front page) and the
     * styles and font preloads (app/setup.php).
     */
    public static function shown(): bool
    {
        return function_exists('is_front_page') && is_front_page() && self::enabled();
    }

    /**
     * The band's texts, buttons and images, cleaned for output: plain text
     * (Blade escapes it), safe URLs only. A button without a URL is left out;
     * an emptied wordmark line or button label falls back to its default,
     * an emptied tagline or verse is not shown.
     */
    public static function data(): array
    {
        $text = function (string $name, bool $fallback) {
            $value = self::option($name);
            $value = $value === null ? self::DEFAULTS[$name] : trim(wp_strip_all_tags((string) $value));
            return $value === '' && $fallback ? self::DEFAULTS[$name] : $value;
        };

        $buttons = [];
        foreach ([1, 2] as $i) {
            $url = self::safeUrl((string) self::option("campaign_band_button_{$i}_url"));
            if ($url !== '') {
                $buttons[] = ['label' => $text("campaign_band_button_{$i}_label", true), 'url' => $url, 'primary' => $i === 1];
            }
        }

        return [
            'line_old' => $text('campaign_band_line_old', true),
            'line_new' => $text('campaign_band_line_new', true),
            'tagline' => $text('campaign_band_tagline', false),
            'verse' => $text('campaign_band_verse', false),
            'reference' => $text('campaign_band_verse_reference', false),
            'buttons' => $buttons,
            'images' => ['old' => self::image('old'), 'new' => self::image('new')],
        ];
    }

    /**
     * A link target the band may print: http(s) addresses and links within
     * the site ("/path", "#anchor", "?query"). Anything else, e.g.
     * "javascript:", "data:", "mailto:", "//host" or "/\\host", gives ''. Used
     * when the settings are saved and again before printing; the result is
     * the decoded address, printed with ordinary (escaped) Blade output.
     */
    public static function safeUrl(string $url): string
    {
        // Character references first: a browser reads "/&#92;&#92;host/" or
        // "/&sol;host/" in an href as "//host". Decode until nothing changes,
        // so double-encoded ones ("&amp;#92;") are caught too.
        $rounds = 0;
        do {
            if (++$rounds > 5) {
                return '';
            }
            $previous = $url;
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } while ($url !== $previous);

        $url = trim($url, " \t\n\r\0\x0B\f");
        // Nothing that can hide or bend the address: control characters,
        // spaces or other invisible characters ("java\tscript:"), backslashes
        // (read as "/"), or a reference a browser would still decode ("&#92"
        // without its ";"). Invalid UTF-8 fails the match too.
        if ($url === '' || str_contains($url, '\\') || str_contains($url, '&#') || str_starts_with($url, '//')
            || preg_match('/[\p{C}\p{Z}]/u', $url) !== 0) {
            return '';
        }
        if (preg_match('#^[/?\#]#', $url)) {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return '';
        }
        return $url;
    }

    /** A Media Library image ID the band may use, or 0 (saving and output). */
    public static function imageId($value): int
    {
        $id = is_scalar($value) ? absint($value) : 0;
        return $id && function_exists('wp_attachment_is_image') && wp_attachment_is_image($id) ? $id : 0;
    }

    /**
     * One side's image: the Media Library image chosen in the settings, or
     * the theme's own crops. Keys: src, srcset, sizes, phone (srcset of the
     * phone crop, '' for a Media Library image), custom (a Media Library
     * image), width, height.
     */
    private static function image(string $side): array
    {
        $id = self::imageId(self::option("campaign_band_image_{$side}"));
        if ($id) {
            $src = wp_get_attachment_image_src($id, 'full');
            if (is_array($src) && !empty($src[0])) {
                return [
                    'src' => $src[0],
                    'srcset' => (string) wp_get_attachment_image_srcset($id, 'full'),
                    'sizes' => '(max-width: 699px) 100vw, ' . self::SIZES[$side],
                    'phone' => '',
                    // Unknown brightness: the stronger shade (campaign-band.css).
                    'custom' => true,
                    'width' => (int) $src[1],
                    'height' => (int) $src[2],
                ];
            }
        }

        $spec = self::IMAGES[$side];
        $base = get_template_directory_uri() . '/assets/images/visa-darau-nauja/' . $spec['file'];
        return [
            'src' => "{$base}-{$spec['width']}.webp",
            'srcset' => "{$base}-{$spec['width']}.webp {$spec['width']}w, {$base}-" . (2 * $spec['width']) . '.webp ' . (2 * $spec['width']) . 'w',
            'sizes' => self::SIZES[$side],
            'phone' => implode(', ', array_map(fn ($w) => "{$base}-phone-{$w}.webp {$w}w", $spec['phone'])),
            'custom' => false,
            'width' => $spec['width'],
            'height' => $spec['height'],
        ];
    }

    /** The font files to preload, as URLs. */
    public static function fontUrls(): array
    {
        $base = get_template_directory_uri() . '/assets/fonts/figtree/';
        return array_map(fn ($file) => $base . $file, array_values(self::FONTS));
    }

    /**
     * The band's styles: the two Figtree faces (absolute URLs, since the
     * rules are embedded in the page) and assets/css/campaign-band.css.
     */
    public static function css(): string
    {
        $css = '';
        foreach (self::FONTS as $weight => $file) {
            $css .= '@font-face{font-family:"Figtree";font-style:normal;font-weight:' . $weight
                . ';font-display:swap;src:url("' . esc_url(get_template_directory_uri() . '/assets/fonts/figtree/' . $file) . '") format("woff2")}';
        }
        return $css . "\n" . embedded_css(get_template_directory() . '/assets/css/campaign-band.css');
    }

    /** A theme setting; none outside WordPress (tests). */
    private static function option(string $name)
    {
        return defined('ABSPATH') && class_exists(Fields\Store::class) ? Fields\Store::option($name) : null;
    }
}
