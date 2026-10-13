-- Adventistai-ALPS: which Carbon Fields fields hold data (read-only).
-- Generated from tests/fields/fixtures/carbon-fields-3.6.11.json. Only SELECTs; nothing is changed.
-- Replace the table prefix `wp_` if the site uses another one
-- (wp-config.php: $table_prefix). Run in phpMyAdmin/Adminer, or:
--   wp db query < tests/fields/audit.sql
-- Complex and association fields are stored as `_name|...` rows, counted under `_name`.

-- 1. Page templates in use (template-home.php and views/template-posts.blade.php no longer exist).
SELECT pm.meta_value AS page_template, p.post_status, COUNT(*) AS pages
FROM wp_postmeta pm JOIN wp_posts p ON p.ID = pm.post_id
WHERE pm.meta_key = '_wp_page_template'
GROUP BY pm.meta_value, p.post_status
ORDER BY pages DESC;

-- 2. Post meta: posts with a non-empty value per field and post type.
SELECT SUBSTRING_INDEX(pm.meta_key, '|', 1) AS field, p.post_type, p.post_status,
       COUNT(DISTINCT pm.post_id) AS posts, COUNT(*) AS meta_rows
FROM wp_postmeta pm JOIN wp_posts p ON p.ID = pm.post_id
WHERE SUBSTRING_INDEX(pm.meta_key, '|', 1) IN (
  '_content_block',
  '_content_block_freeform',
  '_content_block_relationship',
  '_display_title',
  '_featured_image_hero_layout',
  '_grid_two_columns',
  '_head_type',
  '_header_background_image',
  '_hero_carousel',
  '_hero_column',
  '_hero_featured_post',
  '_hero_image',
  '_hero_image_extended',
  '_hero_kicker',
  '_hero_link_button',
  '_hero_link_url',
  '_hero_overlay',
  '_hero_scroll_hint',
  '_hero_title',
  '_hero_type',
  '_hide_featured_image',
  '_hide_sidebar',
  '_is_video',
  '_kicker',
  '_long_header_subtitle',
  '_page_sub_title',
  '_post_feed_archive',
  '_post_feed_archive_category_array',
  '_post_feed_archive_link',
  '_post_feed_archive_offset',
  '_post_feed_archive_title',
  '_post_feed_full',
  '_post_feed_full_category_array',
  '_post_feed_full_featured',
  '_post_feed_full_featured_array',
  '_post_feed_full_link',
  '_post_feed_full_offset',
  '_post_feed_full_title',
  '_post_feed_list',
  '_post_feed_list_category_array',
  '_post_feed_list_count',
  '_post_feed_list_custom_array',
  '_post_feed_list_link',
  '_post_feed_list_offset',
  '_post_feed_list_round_image',
  '_post_feed_list_title',
  '_related',
  '_related_custom_value',
  '_related_grid',
  '_related_grid_3up',
  '_related_image',
  '_related_image_crop',
  '_remove_header',
  '_remove_spacing',
  '_sb_background_image',
  '_sb_body',
  '_sb_cta',
  '_sb_side_image',
  '_sb_subtitle',
  '_sb_thumbnail',
  '_sb_title',
  '_sb_url',
  '_show_hero_featured_post'
)
  AND pm.meta_value NOT IN ('', '_', 'false')
  AND p.post_type <> 'revision'
GROUP BY field, p.post_type, p.post_status
ORDER BY field, p.post_type, p.post_status;

-- 3. Theme options that are set (value shortened to 60 characters).
SELECT option_name, LENGTH(option_value) AS length, LEFT(option_value, 60) AS value_start
FROM wp_options
WHERE SUBSTRING_INDEX(option_name, '|', 1) IN (
  '_alps_image_avif_quality',
  '_alps_image_full_max',
  '_alps_image_small_max',
  '_archive_hide_sidebar',
  '_archive_page_title',
  '_dark_theme',
  '_footer_address',
  '_footer_copyright',
  '_footer_description',
  '_footer_logo_icon',
  '_footer_logo_type',
  '_global_branding_statement',
  '_grid_lines',
  '_index_hide_sidebar',
  '_is_related_stories_image_hidden',
  '_is_wide_logo',
  '_logo',
  '_logo_inverted',
  '_logo_recolor',
  '_posts_grid',
  '_posts_grid_3up',
  '_posts_image',
  '_posts_image_round',
  '_posts_label',
  '_posts_page_title',
  '_sabbath_background',
  '_sabbath_hide',
  '_sabbath_hide_screens',
  '_sabbath_icon',
  '_sabbath_scroll',
  '_site_branding_statement',
  '_square_buttons',
  '_theme_color'
)
  AND option_value NOT IN ('', '_')
ORDER BY option_name;

-- 4. Carbon Fields widgets: saved instances, and which sidebars use them.
SELECT option_name, LENGTH(option_value) AS length
FROM wp_options
WHERE option_name LIKE 'widget\_carbon\_fields\_%';
SELECT option_value AS sidebars_widgets
FROM wp_options
WHERE option_name = 'sidebars_widgets';
