<?php

namespace App;

/**
 * Register and render the Latest Post Slider Gutenberg block.
 */
class LatestPostSliderBlock
{
    public const NAME = 'alps/latest-post-slider';

    /**
     * Register the dynamic block with WordPress.
     */
    public static function register(): void
    {
        register_block_type(self::NAME, [
            'api_version' => 3,
            'attributes' => self::attributes(),
            'supports' => [
                'align' => ['wide', 'full'],
                'anchor' => true,
            ],
            'render_callback' => [self::class, 'render'],
        ]);
    }

    /**
     * Define the attributes shared by the editor and server renderer.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function attributes(): array
    {
        return [
            'columns' => [
                'type' => 'number',
                'default' => 1,
            ],
            'modules' => [
                'type' => 'array',
                'default' => [self::defaultModule()],
            ],
        ];
    }

    /**
     * Render the block on the frontend and in the editor preview.
     *
     * @param array<string, mixed> $attributes Block attributes.
     * @param string                $content    Inner block content.
     * @param mixed                 $block      Block instance.
     * @return string
     */
    public static function render($attributes, $content = '', $block = null): string
    {
        $attributes = is_array($attributes) ? $attributes : [];
        $columns = min(4, max(1, absint($attributes['columns'] ?? 1)));
        $modules = self::normalizeModules($attributes['modules'] ?? []);

        if (empty($modules)) {
            return '';
        }

        $class = 'wp-block-alps-latest-post-slider alps-latest-sliders u-space--double--top';
        $style = sprintf('--alps-latest-slider-columns: %d;', $columns);

        if (function_exists('get_block_wrapper_attributes')) {
            $sectionAttributes = get_block_wrapper_attributes([
                'class' => $class,
                'style' => $style,
            ]);
        } else {
            $sectionAttributes = sprintf(
                'class="%s" style="%s"',
                esc_attr($class),
                esc_attr($style)
            );
        }

        return \view('patterns.02-organisms.sections.latest-post-sliders', [
            'configuredSliderModules' => $modules,
            'sliderColumns' => $columns,
            'sectionAttributes' => $sectionAttributes,
        ])->render();
    }

    /**
     * Convert block attributes to the field shape used by the shared renderer.
     *
     * @param mixed $modules Raw block modules.
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeModules($modules): array
    {
        if (! is_array($modules)) {
            return [self::toRendererModule(self::defaultModule())];
        }

        $normalized = [];

        foreach (array_slice($modules, 0, 4) as $module) {
            if (! is_array($module)) {
                continue;
            }

            $normalized[] = self::toRendererModule(array_merge(self::defaultModule(), $module));
        }

        return $normalized ?: [self::toRendererModule(self::defaultModule())];
    }

    /**
     * Map the block-friendly attribute names to the shared legacy renderer.
     *
     * @param array<string, mixed> $module
     * @return array<string, mixed>
     */
    private static function toRendererModule(array $module): array
    {
        $source = sanitize_key((string) ($module['source'] ?? 'latest'));
        $source = in_array($source, ['latest', 'category', 'custom'], true) ? $source : 'latest';
        $categoryId = absint($module['categoryId'] ?? 0);
        $items = array_values(array_filter(array_map('absint', (array) ($module['items'] ?? []))));

        return [
            'alps_latest_slider_title' => sanitize_text_field((string) ($module['title'] ?? '')),
            'alps_latest_slider_title_url' => esc_url_raw((string) ($module['titleUrl'] ?? ''), ['http', 'https']),
            'alps_latest_slider_source' => $source,
            'alps_latest_slider_category' => $categoryId ? [$categoryId] : [],
            'alps_latest_slider_items' => array_slice($items, 0, 20),
            'alps_latest_slider_count' => min(20, max(1, absint($module['count'] ?? 5))),
            'alps_latest_slider_interval' => min(30, max(2, absint($module['interval'] ?? 5))),
            'alps_latest_slider_autoplay' => ! empty($module['autoplay']),
            'alps_latest_slider_navigation' => ($module['navigationStyle'] ?? '') === 'buttons' ? 'buttons' : 'arrows',
        ];
    }

    /**
     * Return the default module configuration.
     *
     * @return array<string, mixed>
     */
    private static function defaultModule(): array
    {
        return [
            'title' => '',
            'titleUrl' => '',
            'source' => 'latest',
            'categoryId' => 0,
            'items' => [],
            'count' => 5,
            'interval' => 5,
            'autoplay' => true,
            'navigationStyle' => 'arrows',
        ];
    }
}
