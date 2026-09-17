<?php

namespace App;

/**
 * Native breadcrumb fallback that keeps the existing ALPS markup classes.
 */
final class Breadcrumbs
{
    public static function render(): string
    {
        $items = self::items();
        if (count($items) < 2) {
            return '';
        }

        $html = '<ul class="c-breadcrumbs__list">';
        $last = count($items) - 1;
        foreach ($items as $index => $item) {
            $html .= '<li class="c-breadcrumbs__item">';
            if ($index === $last || empty($item['url'])) {
                $html .= '<span aria-current="page">' . esc_html($item['label']) . '</span>';
            } else {
                $html .= '<a href="' . esc_url($item['url']) . '">' . esc_html($item['label']) . '</a>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    private static function items(): array
    {
        $items = [
            ['label' => get_bloginfo('name'), 'url' => home_url('/')],
        ];

        if (is_page()) {
            $ancestors = array_reverse(get_post_ancestors(get_queried_object_id()));
            foreach ($ancestors as $ancestorId) {
                $items[] = [
                    'label' => get_the_title($ancestorId),
                    'url' => get_permalink($ancestorId),
                ];
            }
            $items[] = ['label' => get_the_title(), 'url' => ''];
            return $items;
        }

        if (is_singular('post')) {
            $category = ContentHelpers::primaryCategory(get_queried_object_id());
            if ($category) {
                $items[] = [
                    'label' => $category->name,
                    'url' => self::termUrl($category),
                ];
            }
            $items[] = ['label' => get_the_title(), 'url' => ''];
            return $items;
        }

        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $ancestors = is_tax() || is_category()
                    ? array_reverse(get_ancestors($term->term_id, $term->taxonomy))
                    : [];
                foreach ($ancestors as $ancestorId) {
                    $ancestor = get_term($ancestorId, $term->taxonomy);
                    if ($ancestor instanceof \WP_Term) {
                        $items[] = [
                            'label' => $ancestor->name,
                            'url' => self::termUrl($ancestor),
                        ];
                    }
                }
                $items[] = ['label' => $term->name, 'url' => ''];
            }
            return $items;
        }

        if (is_post_type_archive()) {
            $items[] = ['label' => post_type_archive_title('', false), 'url' => ''];
        }

        return $items;
    }

    private static function termUrl(\WP_Term $term): string
    {
        $url = get_term_link($term);

        return is_wp_error($url) ? '' : (string) $url;
    }
}
