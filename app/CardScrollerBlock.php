<?php

namespace App;

/**
 * Horizontally scrolling row of linked cards (book covers, partner logos).
 *
 * Replaces hand-written HTML/CSS/JS snippets pasted into page content: cards
 * are edited in the block editor, images come from the media library with
 * srcset and dimensions, and the stylesheet and script load only on pages that
 * contain the block.
 */
class CardScrollerBlock
{
    public const NAME = 'alps/card-scroller';
    public const LAYOUTS = ['cover', 'logo'];

    public static function register(): void
    {
        $uri = get_template_directory_uri();
        $style = '/assets/css/card-scroller.css';
        $script = '/assets/js/card-scroller.js';

        wp_register_style('alps-card-scroller', $uri . $style, [], theme_asset_version($uri . $style));
        wp_register_script('alps-card-scroller', $uri . $script, [], theme_asset_version($uri . $script), [
            'in_footer' => true,
            'strategy' => 'defer',
        ]);

        register_block_type(self::NAME, [
            'api_version' => 3,
            'attributes' => self::attributes(),
            'supports' => ['anchor' => true, 'align' => ['wide', 'full'], 'html' => false],
            'style_handles' => ['alps-card-scroller'],
            'view_script_handles' => ['alps-card-scroller'],
            'render_callback' => [self::class, 'render'],
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function attributes(): array
    {
        return [
            'layout' => ['type' => 'string', 'default' => 'cover'],
            'heading' => ['type' => 'string', 'default' => ''],
            'headingUrl' => ['type' => 'string', 'default' => ''],
            'items' => ['type' => 'array', 'default' => []],
            'moreLabel' => ['type' => 'string', 'default' => ''],
            'moreUrl' => ['type' => 'string', 'default' => ''],
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function render($attributes, $content = ''): string
    {
        $attributes = is_array($attributes) ? $attributes : [];
        $layout = in_array($attributes['layout'] ?? '', self::LAYOUTS, true) ? $attributes['layout'] : 'cover';
        $items = self::normalizeItems($attributes['items'] ?? []);
        $moreLabel = sanitize_text_field((string) ($attributes['moreLabel'] ?? ''));
        $moreUrl = esc_url((string) ($attributes['moreUrl'] ?? ''), ['http', 'https']);

        if (!$items && !($moreLabel && $moreUrl)) {
            return '';
        }

        $ids = self::attachmentIds($items);
        $cards = '';
        foreach ($items as $item) {
            $cards .= '<div class="alps-card-scroller__item" role="listitem">' . self::card($item, $ids, $layout) . '</div>';
        }

        if ($moreLabel && $moreUrl) {
            $cards .= sprintf(
                '<div class="alps-card-scroller__item" role="listitem"><a class="alps-card-scroller__card alps-card-scroller__card--more" href="%s">%s<span class="alps-card-scroller__more-arrow" aria-hidden="true">›</span></a></div>',
                $moreUrl,
                esc_html($moreLabel)
            );
        }

        $heading = sanitize_text_field((string) ($attributes['heading'] ?? ''));
        $headingUrl = esc_url((string) ($attributes['headingUrl'] ?? ''), ['http', 'https']);
        $headingHtml = '';
        if ($heading !== '') {
            $headingHtml = '<h2 class="alps-card-scroller__heading">'
                . ($headingUrl ? '<a href="' . $headingUrl . '">' . esc_html($heading) . '</a>' : esc_html($heading))
                . '</h2>';
        }

        $wrapper = get_block_wrapper_attributes([
            'class' => 'alps-card-scroller alps-card-scroller--' . $layout,
        ]);
        $label = $heading !== '' ? $heading : __('Kortelės', 'alps');

        // Arrows start hidden: without JavaScript the row still scrolls natively,
        // and the script reveals them without moving any content.
        return '<div ' . $wrapper . '>' . $headingHtml
            . '<div class="alps-card-scroller__frame">'
            . '<div class="alps-card-scroller__track" role="list" aria-label="' . esc_attr($label) . '" data-card-scroller-track>' . $cards . '</div>'
            . '<button type="button" class="alps-card-scroller__arrow alps-card-scroller__arrow--prev" aria-label="' . esc_attr__('Slinkti į kairę', 'alps') . '" data-card-scroller-prev hidden><span aria-hidden="true">‹</span></button>'
            . '<button type="button" class="alps-card-scroller__arrow alps-card-scroller__arrow--next" aria-label="' . esc_attr__('Slinkti į dešinę', 'alps') . '" data-card-scroller-next hidden><span aria-hidden="true">›</span></button>'
            . '</div></div>';
    }

    /**
     * Keep cards that have something to show. A card without a (safe) link is
     * still shown, as a plain card: dropping it made a half-filled block render
     * nothing at all, so it looked broken in the editor's preview.
     *
     * @param mixed $items
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeItems($items): array
    {
        $normalized = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $card = [
                'imageId' => absint($item['imageId'] ?? 0),
                'imageUrl' => esc_url_raw((string) ($item['imageUrl'] ?? ''), ['http', 'https']),
                'alt' => sanitize_text_field((string) ($item['alt'] ?? '')),
                'title' => sanitize_text_field((string) ($item['title'] ?? '')),
                'description' => sanitize_text_field((string) ($item['description'] ?? '')),
                'url' => esc_url_raw((string) ($item['url'] ?? ''), ['http', 'https']),
                'newTab' => !empty($item['newTab']),
            ];

            if ($card['title'] === '' && !$card['imageId'] && $card['imageUrl'] === '') {
                continue;
            }

            $normalized[] = $card;
        }

        return $normalized;
    }

    /**
     * Attachment IDs for cards that only carry an image URL (for example
     * content pasted from the old snippets), cached as one transient per block.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<string, int>
     */
    public static function attachmentIds(array $items): array
    {
        $urls = [];
        foreach ($items as $item) {
            if (!$item['imageId'] && $item['imageUrl'] !== '') {
                $urls[] = $item['imageUrl'];
            }
        }

        if (!$urls) {
            return [];
        }

        $key = 'alps_card_scroller_' . md5(implode('|', $urls));
        $ids = get_transient($key);
        if (is_array($ids)) {
            return $ids;
        }

        $ids = [];
        foreach ($urls as $url) {
            $ids[$url] = (int) attachment_url_to_postid($url);
        }
        set_transient($key, $ids, WEEK_IN_SECONDS);

        return $ids;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, int>   $ids
     */
    private static function card(array $item, array $ids, string $layout): string
    {
        $alt = $item['alt'] !== '' ? $item['alt'] : $item['title'];
        $id = $item['imageId'] ?: ($ids[$item['imageUrl']] ?? 0);
        // Rendered width: covers are at most 170px wide, logos 156px.
        $sizes = $layout === 'logo' ? '156px' : '(max-width: 600px) 149px, 170px';

        $image = '';
        if ($id) {
            $image = wp_get_attachment_image($id, 'medium', false, [
                'class' => 'alps-card-scroller__image',
                'alt' => $alt,
                'sizes' => $sizes,
                'loading' => 'lazy',
                'decoding' => 'async',
                'draggable' => 'false',
            ]);
        }
        if (!$image && $item['imageUrl'] !== '') {
            $image = sprintf(
                '<img class="alps-card-scroller__image" src="%s" alt="%s" loading="lazy" decoding="async" draggable="false">',
                esc_url($item['imageUrl']),
                esc_attr($alt)
            );
        }

        $inner = ($image ? '<span class="alps-card-scroller__media">' . $image . '</span>' : '')
            . ($item['title'] !== '' ? '<span class="alps-card-scroller__title">' . esc_html($item['title']) . '</span>' : '')
            . ($item['description'] !== '' ? '<span class="alps-card-scroller__description">' . esc_html($item['description']) . '</span>' : '');

        if ($item['url'] === '') {
            return '<div class="alps-card-scroller__card">' . $inner . '</div>';
        }

        $target = $item['newTab'] ? ' target="_blank" rel="noopener"' : '';

        return '<a class="alps-card-scroller__card" href="' . esc_url($item['url']) . '"' . $target . '>' . $inner . '</a>';
    }
}
