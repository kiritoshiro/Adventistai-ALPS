<?php

namespace App;

/**
 * Lightweight metadata fallback for sites that do not run a full SEO plugin.
 * A supported SEO provider remains the owner of these tags when active.
 */
final class SeoDefaults
{
    private static ?array $metadata = null;

    public static function register(): void
    {
        add_action('wp_head', [self::class, 'render'], 1);
    }

    public static function render(): void
    {
        if (is_admin() || self::hasProvider() || !apply_filters('adventistai_seo_fallback_enabled', true)) {
            return;
        }

        $metadata = self::metadata();
        if ($metadata['canonical']) {
            echo '<link rel="canonical" href="' . esc_url($metadata['canonical']) . '" />' . "\n";
        }

        if ($metadata['description']) {
            echo '<meta name="description" content="' . esc_attr($metadata['description']) . '" />' . "\n";
        }

        foreach ($metadata['open_graph'] as $property => $content) {
            if ($content === '') {
                continue;
            }
            echo '<meta property="' . esc_attr($property) . '" content="' . esc_attr($content) . '" />' . "\n";
        }

        foreach ($metadata['twitter'] as $name => $content) {
            if ($content === '') {
                continue;
            }
            echo '<meta name="' . esc_attr($name) . '" content="' . esc_attr($content) . '" />' . "\n";
        }
    }

    public static function metadata(): array
    {
        if (self::$metadata !== null) {
            return self::$metadata;
        }

        $title = self::title();
        $description = self::description();
        $url = self::url();
        $image = self::image();
        $locale = str_replace('_', '-', (string) get_locale());
        $siteName = (string) get_bloginfo('name');
        $type = is_singular('post') ? 'article' : 'website';

        self::$metadata = [
            'canonical' => self::canonical(),
            'description' => $description,
            'open_graph' => [
                'og:locale' => $locale,
                'og:site_name' => $siteName,
                'og:type' => $type,
                'og:title' => $title,
                'og:description' => $description,
                'og:url' => $url,
                'og:image' => $image['url'],
                'og:image:width' => $image['width'],
                'og:image:height' => $image['height'],
                'og:image:alt' => $image['alt'],
            ],
            'twitter' => [
                'twitter:card' => $image['url'] ? 'summary_large_image' : 'summary',
                'twitter:title' => $title,
                'twitter:description' => $description,
                'twitter:image' => $image['url'],
                'twitter:image:alt' => $image['alt'],
            ],
        ];

        return self::$metadata;
    }

    private static function hasProvider(): bool
    {
        $provider = defined('WPSEO_VERSION')
            || defined('RANK_MATH_VERSION')
            || class_exists('RankMath')
            || function_exists('seopress_init')
            || function_exists('the_seo_framework');

        return (bool) apply_filters('adventistai_seo_provider_active', $provider);
    }

    private static function title(): string
    {
        if (function_exists('wp_get_document_title')) {
            return wp_strip_all_tags(wp_get_document_title());
        }

        return wp_strip_all_tags(get_the_title() ?: get_bloginfo('name'));
    }

    private static function description(): string
    {
        $postId = get_queried_object_id();
        if (is_singular() && $postId) {
            $post = get_post($postId);
            if ($post instanceof \WP_Post) {
                $source = trim((string) $post->post_excerpt);
                if ($source === '') {
                    $source = (string) $post->post_content;
                }
                return ContentHelpers::trimWords($source, 160);
            }
        }

        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                return ContentHelpers::trimWords((string) $term->description, 160);
            }
        }

        if (is_home() && get_option('page_for_posts')) {
            $postsPage = get_post((int) get_option('page_for_posts'));
            if ($postsPage instanceof \WP_Post) {
                return ContentHelpers::trimWords((string) $postsPage->post_excerpt, 160);
            }
        }

        if (is_front_page()) {
            $frontPage = get_post((int) get_option('page_on_front'));
            if ($frontPage instanceof \WP_Post) {
                $description = ContentHelpers::trimWords((string) $frontPage->post_excerpt, 160);
                if ($description !== '') {
                    return $description;
                }
            }
            return ContentHelpers::trimWords((string) get_bloginfo('description'), 160);
        }

        return '';
    }

    private static function url(): string
    {
        if (is_singular()) {
            return (string) get_permalink(get_queried_object_id());
        }
        return self::canonical();
    }

    private static function canonical(): string
    {
        if (is_category() || is_tag() || is_tax()) {
            if ((int) get_query_var('paged') > 1) {
                return (string) get_pagenum_link((int) get_query_var('paged'));
            }
            $termUrl = get_term_link(get_queried_object());
            return is_wp_error($termUrl) ? '' : (string) $termUrl;
        }

        if (is_home() || is_archive()) {
            return (string) get_pagenum_link(max(1, (int) get_query_var('paged')));
        }

        return '';
    }

    private static function image(): array
    {
        $image = ['url' => '', 'width' => '', 'height' => '', 'alt' => ''];
        $postId = get_queried_object_id();
        $attachmentId = $postId && is_singular() ? (int) get_post_thumbnail_id($postId) : 0;
        if (!$attachmentId) {
            $siteIcon = get_site_icon_url(512);
            if ($siteIcon) {
                $image['url'] = (string) $siteIcon;
            }
            return $image;
        }

        $src = wp_get_attachment_image_src($attachmentId, 'full');
        if (!$src) {
            return $image;
        }

        $image['url'] = (string) $src[0];
        $image['width'] = (string) $src[1];
        $image['height'] = (string) $src[2];
        $image['alt'] = (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true);
        return $image;
    }
}
