<?php

namespace App;

/** Image with ordinary paragraph flow, also writable through the WP REST API. */
class WrappedImageTextBlock
{
    public static function register(): void
    {
        register_block_type('alps/wrapped-image-text', [
            'api_version' => 3,
            'attributes' => [
                'imageId' => ['type' => 'number', 'default' => 0],
                'imageUrl' => ['type' => 'string', 'default' => ''],
                'alt' => ['type' => 'string', 'default' => ''],
                'caption' => ['type' => 'string', 'default' => ''],
                'imageWidth' => ['type' => 'number', 'default' => 32],
                'maxImageWidth' => ['type' => 'number', 'default' => 320],
            ],
            'supports' => ['anchor' => true, 'align' => ['wide', 'full'], 'html' => false],
            'render_callback' => [self::class, 'render'],
        ]);
    }

    public static function render($attributes, $content = ''): string
    {
        $attributes = is_array($attributes) ? $attributes : [];
        $width = min(45, max(20, absint($attributes['imageWidth'] ?? 32)));
        $maxWidth = min(600, max(160, absint($attributes['maxImageWidth'] ?? 320)));
        $alt = sanitize_text_field((string) ($attributes['alt'] ?? ''));
        $image = '';
        $imageId = absint($attributes['imageId'] ?? 0);
        if ($imageId) {
            $image = wp_get_attachment_image($imageId, 'full', false, [
                'class' => 'alps-wrapped-text__image',
                'alt' => $alt,
            ]);
        }
        if (!$image && !empty($attributes['imageUrl'])) {
            $url = esc_url((string) $attributes['imageUrl'], ['http', 'https']);
            if ($url) {
                $image = sprintf('<img class="alps-wrapped-text__image" src="%s" alt="%s" loading="lazy" decoding="async">', $url, esc_attr($alt));
            }
        }
        $figure = '';
        if ($image) {
            $caption = sanitize_text_field((string) ($attributes['caption'] ?? ''));
            $figure = '<figure class="alps-wrapped-text__figure">' . $image
                . ($caption !== '' ? '<figcaption>' . esc_html($caption) . '</figcaption>' : '')
                . '</figure>';
        }
        $wrapper = get_block_wrapper_attributes([
            'class' => 'alps-wrapped-text',
            'style' => sprintf('--alps-wrap-width:%d%%;--alps-wrap-max:%dpx;', $width, $maxWidth),
        ]);
        // Inner blocks are already rendered by WordPress; retain their formatting.
        return '<div ' . $wrapper . '><div class="alps-wrapped-text__flow">' . $figure . $content . '</div></div>';
    }
}
