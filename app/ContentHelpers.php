<?php

namespace App;

/**
 * Small, defensive helpers shared by theme templates.
 */
final class ContentHelpers
{
    /**
     * Return the post's configured Yoast primary category when it is valid,
     * otherwise the first assigned category, or null when none is available.
     */
    public static function primaryCategory(int $postId): ?\WP_Term
    {
        $categories = get_the_category($postId);
        if (empty($categories) || is_wp_error($categories)) {
            return null;
        }

        if (class_exists('WPSEO_Primary_Term')) {
            $primaryId = (new \WPSEO_Primary_Term('category', $postId))->get_primary_term();
            if ($primaryId) {
                $primary = get_term((int) $primaryId, 'category');
                if ($primary instanceof \WP_Term && !is_wp_error($primary)) {
                    return $primary;
                }
            }
        }

        return $categories[0] instanceof \WP_Term ? $categories[0] : null;
    }

    public static function categoryName(int $postId): string
    {
        $category = self::primaryCategory($postId);

        return $category ? (string) $category->name : '';
    }

    public static function categorySlug(int $postId): string
    {
        $category = self::primaryCategory($postId);

        return $category ? (string) $category->slug : '';
    }

    /**
     * Clean visible text without executing dynamic blocks or shortcodes.
     */
    public static function cleanText(string $value): string
    {
        $value = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $value) ?? $value;
        $value = preg_replace('/<!--.*?-->/s', ' ', $value) ?? $value;
        $value = strip_shortcodes($value);
        $value = wp_strip_all_tags($value, true);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Truncate at a word boundary while preserving multibyte characters.
     */
    public static function trimWords(string $value, int $maxCharacters): string
    {
        $value = self::cleanText($value);
        if ($value === '') {
            return '';
        }

        $length = self::length($value);
        if ($length <= $maxCharacters) {
            return $value;
        }

        $words = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result = '';
        foreach ($words as $word) {
            $candidate = $result === '' ? $word : $result . ' ' . $word;
            $candidateLength = self::length($candidate);
            if ($candidateLength > $maxCharacters - 1) {
                break;
            }
            $result = $candidate;
        }

        if ($result === '') {
            $result = self::substr($value, 0, max(1, $maxCharacters - 1));
        }

        return rtrim($result, " \t\n\r\0\x0B.,;:!?…") . '…';
    }

    public static function trimWordCount(string $value, int $maxWords): string
    {
        return wp_trim_words(self::cleanText($value), max(1, $maxWords), '…');
    }

    private static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return count(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private static function substr(string $value, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $start, $length, 'UTF-8');
        }

        return implode('', array_slice(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [], $start, $length));
    }
}
