<?php

/**
 * Stubs of the theme helpers around the Latest Post Slider template, for
 * tests/blocks/latest-post-slider.php. imageSizes() shows the width and height
 * it was given and whether it was asked for a card, so the test sees what the
 * template passes; tests/scripts/run.php checks the real imageSizes() values.
 */

namespace App;

class LatestPostSlider
{
    public static function posts(array $module): array
    {
        return array_map(fn ($id) => (object) ['ID' => $id], $module['ids']);
    }

    public static function claimEagerSection(): bool
    {
        return false;
    }

    public static function priorityIndex(array $sizes): int
    {
        return -1;
    }

    public static function associationIds($items): array
    {
        return (array) $items;
    }

    public static function imageSizes(int $columns, int $width, int $height, bool $card = false): string
    {
        return 'sizes-' . $width . 'x' . $height . ($card ? '-card' : '');
    }

    public static function withoutAutoSizes(string $html): string
    {
        return $html;
    }

    public static function queuePreload(string $html): string
    {
        return '';
    }
}

class ContentHelpers
{
    public static function categoryName(int $id): string
    {
        return $GLOBALS['posts'][$id]['category'];
    }
}

class DeferredImages
{
    public static function defer($html)
    {
        return $html;
    }
}
