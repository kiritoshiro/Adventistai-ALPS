<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;

// The defaults live with the field list (app/Fields/Registry.php).

/**
 * These boxes now only show on classic edit screens (attachments, post types
 * without the block editor); the block editor has the theme's own panels
 * (app/Fields/PostPanels.php). The boxes of the removed home and posts
 * templates are gone; their stored values were left in place.
 */
function crb_classic_screen($post_type)
{
    return in_array($post_type, \App\Fields\PostPanels::classicTypes(), true);
}
function setDefaultHeader() {
    return \App\Fields\Registry::defaultHeroLayout();
}
function setDefaultRelatedImageCrop() {
    return \App\Fields\Registry::defaultRelatedImageCrop();
}
add_action('carbon_fields_register_fields', 'crb_page_options');
function crb_page_options()
{
	Container
		::make('post_meta', __('ALPS: Header and Page Builder Options', 'alps'))
		->where('post_type', 'CUSTOM', 'crb_classic_screen')
		->add_fields([
			Field
				::make('separator', 'crb_page_options', __('Header and Page Builder Options', 'alps'))
				->set_help_text(__('Use these settings to alter header display and change content spacing if you intend to use a page builder.', 'alps')),
			Field
			::make('checkbox', 'remove_header', __('Remove Page Header', 'alps'))
				->set_help_text(__('Hides the page header for this entry.', 'alps'))
				->set_option_value('true')
				->set_width(33),
				Field
				::make('radio', 'featured_image_hero_layout', __('Display larger banner', 'alps'))
                ->set_help_text(__('Show the whole featured or custom image with the title on a blurred copy of the image. Choose the taller banner for more image height.', 'alps'))
                ->set_default_value(setDefaultHeader())
                ->add_options([
                    'false' => __('Do not show larger banner.', 'alps'),
                    'header-block-featured' => __('Show fitted image with a blurred title background.', 'alps'),
                    'page-header' => __('Show taller fitted image with a blurred title background.', 'alps')
                ])
                ->set_width(33),
			Field
			::make('checkbox', 'remove_spacing', __('Remove Default Spacing', 'alps'))
				->set_help_text(__('Removes classes that create default margins and padding for content area.', 'alps'))
				->set_option_value('true')
				->set_width(33),
			Field
			::make('select', 'head_type', __('Template Head Type', 'alps'))
			->set_help_text(__('Select the template header option.', 'alps'))
			->add_options([
				'default' => __('Default', 'alps'),
				'remove' => __('Remove Head', 'alps'),
				'overlay_gradient' => __('Overlay Head With Background Gradient', 'alps'),
				'overlay' => __('Overlay Head Without Gradient', 'alps')
			])
			->set_width(33),
		]);
}
add_action('carbon_fields_register_fields', 'crb_attach_header');
function crb_attach_header()
{
	Container
		::make('post_meta', __('ALPS: Header Banner', 'alps'))
		->where('post_type', 'CUSTOM', 'crb_classic_screen')
		->add_fields([
			Field
				::make('separator', 'crb_long_header', __('Banner', 'alps'))
				->set_help_text(__('IMPORTANT: Setting an image and title below will override the post title and feature image. <br><span style="color: red;">Banner image will look best if you crop the source image to an aspect ratio of 4:3 before uploading it.</span>', 'alps')),
			Field
				::make('text', 'kicker', __('Header Kicker', 'alps')),
			Field
				::make('text', 'display_title', __('Header Title', 'alps')),
			Field
				::make('text', 'long_header_subtitle', __('Header Subtitle', 'alps')),
            Field
                ::make('text', 'page_sub_title', __('Page Subtitle', 'alps')),
			Field
				::make('image', 'header_background_image', __('Custom Header Image (will override feature image)', 'alps'))
				->set_width(50),
		]);
}

add_action('carbon_fields_register_fields', 'crb_attach_hero');
function crb_attach_hero()
{
	Container
		::make('post_meta', __('ALPS: Hero', 'alps'))
		->where('post_type', 'CUSTOM', 'crb_classic_screen')
		->add_fields([
			Field
				::make('separator', 'crb_hero_banner', __('Hero Banner', 'alps'))
				->set_help_text(__('IMPORTANT: Setting a hero style below will completely override anything you have entered for your ALPS Header Banner or your Featured Image.', 'alps')),
			Field
				::make('radio', 'hero_type', __('Hero Style', 'alps'))
				->set_help_text(__('Select the hero configuration.', 'alps'))
				->add_options([
					'false' => __('None', 'alps'),
					'default' => __('Half screen image with text overlay (Min/Max Images: 1)', 'alps'),
					'full' => __('Full screen image with gradient (Min/Max Images: 1)', 'alps'),
					'full_without_gradient' => __('Full screen image without gradient (Min/Max Images: 1)', 'alps'),
					'full_overlay' => __('Full screen image with text and header overlay (Min/Max Images: 1)', 'alps'),
					'column' => __('Three column image format with text overlays (Min/Max Images: 3)', 'alps'),
					'carousel' => __('Half screen image gallery with text overlay (Max Images: 9)', 'alps'),
					'carousel_without_text_overlay' => __('Half screen image gallery without text overlay (Max Images: 9)', 'alps'),
				]),
			Field
                ::make('radio', 'hero_overlay', __('Hero Overlay', 'alps'))
                ->set_help_text(__('Control the dark fade overlay on hero images.', 'alps'))
                ->add_options([
                   'on' => __('Hero fade overlay On', 'alps'),
                   'off' => __('Hero fade overlay Off', 'alps'),
                ])
                ->set_default_value('on'),
			Field
				::make('image', 'hero_image', __('Hero Image', 'alps'))
				->set_conditional_logic([
					'relation' => 'OR', // Optional, defaults to "AND"
					[
						'field' => 'hero_type',
						'value' => 'default',
					],
					[
						'field' => 'hero_type',
						'value' => 'full',
					],
					[
                        'field' => 'hero_type',
                        'value' => 'full_without_gradient',
                    ],
					[
						'field' => 'hero_type',
						'value' => 'full_overlay',
					]
				]),
			Field
				::make('textarea', 'hero_title', __('Hero Title', 'alps'))
				->set_help_text(__('The title of the hero image.', 'alps'))
				->set_rows(2)
				->set_width(50)
				->set_conditional_logic([
					'relation' => 'OR', // Optional, defaults to "AND"
					[
						'field' => 'hero_type',
						'value' => 'default',
					],
					[
						'field' => 'hero_type',
						'value' => 'full',
					],
					[
						'field' => 'hero_type',
						'value' => 'full_overlay',
					]
				]),
			Field
				::make('text', 'hero_kicker', __('Hero Kicker', 'alps'))
				->set_help_text(__('Displays below the title in the hero image.', 'alps'))
				->set_width(50)
				->set_conditional_logic([
					'relation' => 'OR', // Optional, defaults to "AND"
					[
						'field' => 'hero_type',
						'value' => 'default',
					],
					[
						'field' => 'hero_type',
						'value' => 'full',
					],
					[
						'field' => 'hero_type',
						'value' => 'full_overlay',
					]
				]),
			Field
				::make('text', 'hero_link_url', __('Hero Link URL', 'alps'))
				->set_help_text(__('Enter an URL to link the hero image.', 'alps'))
				->set_conditional_logic([
					'relation' => 'OR', // Optional, defaults to "AND"
					[
						'field' => 'hero_type',
						'value' => 'default',
					],
					[
						'field' => 'hero_type',
						'value' => 'full',
					],
					[
						'field' => 'hero_type',
						'value' => 'full_overlay',
					]
				]),
			Field
				::make('text', 'hero_link_button', __('Hero Link Button', 'alps'))
				->set_help_text(__('Enter text here to show on the hero button. Filling this out sets the link on the button and not the hero image.', 'alps'))
				->set_conditional_logic([
					'relation' => 'OR', // Optional, defaults to "AND"
					[
						'field' => 'hero_type',
						'value' => 'full',
					],
					[
						'field' => 'hero_type',
						'value' => 'full_overlay',
					]
				]),
			Field
				::make('checkbox', 'hero_image_extended', __('Hero Image Extended', 'alps'))
				->set_option_value('true')
				->set_help_text(__('Check to extend the hero image over the sabbath column.', 'alps'))
				->set_width(50)
				->set_conditional_logic([
					'relation' => 'OR', // Optional, defaults to "AND"
					[
						'field' => 'hero_type',
						'value' => 'default',
					],
					[
						'field' => 'hero_type',
						'value' => 'full',
					]
				]),
			Field
				::make('checkbox', 'hero_scroll_hint', __('Hero Scroll Hint', 'alps'))
				->set_option_value('true')
				->set_help_text(__('Check to extend the hero image over the sabbath column.', 'alps'))
				->set_width(50)
				->set_conditional_logic([
					[
						'field' => 'hero_type',
						'value' => 'full',
					]
				]),

			Field
				::make('complex', 'hero_column', __('Hero Column (3 Columns)', 'alps'))
				->set_conditional_logic([
					[
						'field' => 'hero_type',
						'value' => 'column',
					]
				])
				->add_fields([
					Field
						::make('image', 'hero_image_column', __('Hero Image', 'alps')),
					Field
						::make('textarea', 'hero_title_column', __('Hero Title', 'alps'))
						->set_help_text(__('The title of the hero image.', 'alps'))
						->set_rows(2)
						->set_width(50),
					Field
						::make('text', 'hero_kicker_column', __('Hero Kicker', 'alps'))
						->set_help_text(__('Displays below the title in the hero image.', 'alps'))
						->set_width(50),
					Field
						::make('text', 'hero_link_url', __('Hero Link URL', 'alps'))
						->set_help_text(__('Enter an URL to link the hero image.', 'alps'))
				])
				->set_min(3)
				->set_max(3),
			Field
				::make('complex', 'hero_carousel', __('Hero Carousel', 'alps'))
				->set_conditional_logic([
    				'relation' => 'OR', // Optional, defaults to "AND"
					[
						'field' => 'hero_type',
						'value' => 'carousel',
					],
					[
                        'field' => 'hero_type',
                        'value' => 'carousel_without_text_overlay',
                    ]
				])
				// Field names refers to https://github.com/adventistchurch/alps/blob/v3.x/source/_patterns/02-organisms/sections/hero-carousel.json
				->add_fields([
					Field
						::make('image', 'slide_image', __('Hero Image', 'alps')),
					Field
						::make('text', 'slide_heading', __('Hero Title', 'alps'))
						->set_help_text(__('The title of the hero image slide.', 'alps'))
						->set_width(50),
					Field
						::make('textarea', 'slide_dek', __('Hero Description', 'alps'))
						->set_help_text(__('Displays below the sub title in the hero image slide.', 'alps'))
						->set_width(100),
					Field
						::make('text', 'slide_url', __('Hero Link URL', 'alps'))
						->set_help_text(__('Enter an URL to link the hero image slide.', 'alps'))
						->set_width(50),
					Field
						::make('text', 'slide_cta', __('Hero Link CTA', 'alps'))
						->set_help_text(__('Displays title in the hero link.', 'alps'))
						->set_width(50),
				])
				->set_min(1)
				->set_max(9)
		]);
}

add_action('carbon_fields_register_fields', 'crb_attach_page_template_fields');
function crb_attach_page_template_fields()
{
	Container
		::make('post_meta', __('ALPS: Entry Sidebar / Featured Image', 'alps'))
		->where('post_type', 'CUSTOM', 'crb_classic_screen')
		->add_fields([
			Field
				::make('separator', 'crb_hide_sidebar', __('Hide Sidebar', 'alps'))
				->set_width(50),
			Field
				::make('separator', 'crb_hide_img', __('Hide Featured Image', 'alps'))
				->set_width(50),
			Field
				::make('checkbox', 'hide_sidebar', __('Hide Sidebar', 'alps'))
				->set_help_text(__('Hides the sidebar for this entry if it is active.', 'alps'))
				->set_option_value('true')
				->set_width(50),
			Field
				::make('checkbox', 'hide_featured_image', __('Hide Featured Image', 'alps'))
				->set_help_text(__('Hides the featured image on the page/post header for this entry.', 'alps'))
				->set_option_value('true')
				->set_width(50),
		]);
}

add_action('carbon_fields_register_fields', 'crb_attach_related_pages');
function crb_attach_related_pages()
{
	Container
		::make('post_meta', __('ALPS: Related Pages & Posts', 'alps'))
		->where('post_type', '=', 'page')
		->where('post_type', 'CUSTOM', 'crb_classic_screen')
		->add_fields([
			Field
				::make('radio', 'related', __('Related Pages Format', 'alps'))
				->set_help_text(__('Select the format of the related pages.', 'alps'))
				->add_options([
					'false' => __('None', 'alps'),
					'related_top_level' => __('Show first level child pages only', 'alps'),
					'related_all' => __('Show child and grandchild pages', 'alps'),
					'related_custom' => __('Show custom pages', 'alps'),
				]),
			Field
				::make('association', 'related_custom_value', __('Assign Pages', 'alps'))
				->set_types([
					[
						'type' => 'post',
						'post_type' => 'page',
					],
					[
						'type' => 'post',
						'post_type' => 'post',
					]
				])
				->set_conditional_logic([
					[
						'field' => 'related',
						'value' => 'related_custom',
					]
				]),
			Field
				::make('checkbox', 'related_grid', __('Related Pages Grid', 'alps'))
				->set_option_value('true')
				->set_help_text(__('Select to display the related pages side-by-side.', 'alps')),
			Field
				::make('checkbox', 'related_grid_3up', __('Related Pages Grid (3up)', 'alps'))
				->set_option_value('true')
				->set_help_text(__('Select to display the related pages in 3 columns on large screens. This will only display if the Sabbath column is hidden.', 'alps'))
				->set_conditional_logic([
					[
						'field' => 'related_grid',
						'value' => true,
					]
				]),
			Field
				::make('checkbox', 'related_image', __('Related Pages Image', 'alps'))
				->set_option_value('true')
				->set_help_text(__('Select to display the feature image for the related pages.', 'alps'))
                ->set_width(50),
            Field
                ::make('radio', 'related_image_crop', __('Related Page Image Cropping', 'alps'))
                ->add_options([
                    'square' => __('Square', 'alps'),
                    'landscape' => __('Landscape', 'alps'),
                    'portrait' => __('Portrait', 'alps'),
                    'circle' => __('Circle', 'alps'),
                ])
                ->set_default_value(setDefaultRelatedImageCrop())
                ->set_conditional_logic([
                    [
                        'field' => 'related_image',
                        'value' => true,
                    ]
                ])
            ->set_width(50),
		]);
}
