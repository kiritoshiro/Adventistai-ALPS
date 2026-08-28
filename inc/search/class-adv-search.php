<?php

/**
 * Advanced search bootstrap and master kill switch.
 *
 * @package Adventistai
 */

defined( 'ABSPATH' ) || exit;

final class Adv_Search {
	public static function boot() {
		Adv_Search_Admin::register();

		add_action( 'after_switch_theme', array( 'Adv_Search_Schema', 'install' ) );
		add_action( 'update_option_adv_search_options', array( __CLASS__, 'settings_changed' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'index_notice' ) );

		if ( ! self::enabled() ) {
			return;
		}

		if ( get_option( 'adv_search_schema_version' ) !== Adv_Search_Schema::VERSION ) {
			add_action( 'init', array( 'Adv_Search_Schema', 'install' ), 1 );
		}

		Adv_Search_Indexer::register_hooks();
		Adv_Search_REST::register();
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 1000 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'defer_script' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'noindex_search' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'adventistai search reindex', array( __CLASS__, 'cli_reindex' ) );
		}
	}

	public static function enabled() {
		$options = self::options();
		$switch  = get_option( 'adv_search_enabled', null );
		$enabled = null === $switch ? ! empty( $options['enabled'] ) : (bool) $switch;
		return $enabled && function_exists( 'mb_strtolower' ) && function_exists( 'mb_strlen' );
	}

	public static function defaults() {
		return array(
			'enabled'               => false,
			'post_types'            => array( 'post', 'page' ),
			'exclude_ids'           => array(),
			'exclude_categories'    => array(),
			'exclude_tags'          => array(),
			'include_excerpt'       => true,
			'include_custom_fields' => false,
			'custom_field_keys'     => array(),
			'min_chars'             => 3,
			'min_term_length'       => 2,
			'max_terms'             => 6,
			'operator'              => 'AND',
			'allow_phrases'         => true,
			'stopwords'             => array( 'ir', 'bet', 'arba', 'kad', 'yra', 'su', 'be', 'iš', 'į', 'ant', 'per', 'apie', 'nes', 'tai', 'kaip' ),
			'weights'               => array(
				'exact_title'     => 1000,
				'title_starts'    => 500,
				'title_first'     => 200,
				'title_token'     => 100,
				'excerpt'         => 40,
				'content'         => 10,
				'all_title_bonus' => 150,
				'page_multiplier' => 1.0,
			),
			'per_page_default'      => 10,
			'per_page_options'      => array( 10, 20, 50 ),
			'live_enabled'          => true,
			'live_delay'            => 250,
			'live_limit'            => 7,
			'snippet_length'        => 200,
			'max_snippets'          => 2,
			'highlight_tag'         => 'mark',
			'highlight_class'       => 'adv-search-hl',
			'highlight_color'       => '#fff2a8',
			'show_type'             => true,
			'show_date'             => true,
			'show_matched_in'       => true,
			'show_filters'          => true,
			'zero_message'          => __( 'Pagal užklausą nieko nerasta.', 'alps' ),
			'suggested_links'       => array(
				array( 'label' => __( 'Naujienos', 'alps' ), 'url' => home_url( '/naujienos/' ) ),
				array( 'label' => __( 'Apie mus', 'alps' ), 'url' => home_url( '/apie-mus/' ) ),
			),
			'cache_ttl'             => 300,
			'rate_limit_count'      => 30,
			'rate_limit_window'     => 60,
			'content_limit'         => 100000,
			'max_tokens'            => 5000,
		);
	}

	public static function options() {
		$stored   = get_option( 'adv_search_options', array() );
		$defaults = self::defaults();
		$options  = wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
		$options['weights'] = wp_parse_args( isset( $options['weights'] ) && is_array( $options['weights'] ) ? $options['weights'] : array(), $defaults['weights'] );
		return $options;
	}

	public static function sanitize_options( $input ) {
		$old      = self::options();
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$out      = $old;
		$bools    = array( 'enabled', 'include_excerpt', 'include_custom_fields', 'allow_phrases', 'live_enabled', 'show_type', 'show_date', 'show_matched_in', 'show_filters' );
		foreach ( $bools as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = ! empty( $input[ $key ] );
			}
		}

		if ( isset( $input['post_types'] ) ) {
			$public = array_keys( get_post_types( array( 'public' => true ) ) );
			$out['post_types'] = array_values( array_intersect( array_map( 'sanitize_key', (array) $input['post_types'] ), $public ) );
		}
		foreach ( array( 'exclude_ids', 'exclude_categories', 'exclude_tags' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = self::csv_ints( $input[ $key ] );
			}
		}
		if ( isset( $input['custom_field_keys'] ) ) {
			$out['custom_field_keys'] = array_values( array_filter( array_map( 'sanitize_key', self::csv_strings( $input['custom_field_keys'] ) ) ) );
		}
		if ( isset( $input['stopwords'] ) ) {
			$out['stopwords'] = array_values( array_filter( array_map( array( 'Adv_Search_Normalizer', 'normalize' ), self::csv_strings( $input['stopwords'] ) ) ) );
		}
		if ( isset( $input['operator'] ) ) {
			$out['operator'] = 'OR' === strtoupper( $input['operator'] ) ? 'OR' : 'AND';
		}

		$integers = array(
			'min_chars' => array( 1, 20 ), 'min_term_length' => array( 1, 20 ), 'max_terms' => array( 1, 20 ),
			'per_page_default' => array( 1, 100 ), 'live_delay' => array( 100, 2000 ), 'live_limit' => array( 1, 20 ),
			'snippet_length' => array( 60, 1000 ), 'max_snippets' => array( 1, 3 ), 'cache_ttl' => array( 0, DAY_IN_SECONDS ),
			'rate_limit_count' => array( 1, 1000 ), 'rate_limit_window' => array( 10, HOUR_IN_SECONDS ),
			'content_limit' => array( 1000, 1000000 ), 'max_tokens' => array( 100, 20000 ),
		);
		foreach ( $integers as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = min( $range[1], max( $range[0], absint( $input[ $key ] ) ) );
			}
		}

		if ( isset( $input['per_page_options'] ) ) {
			$out['per_page_options'] = array_values( array_unique( array_filter( self::csv_ints( $input['per_page_options'] ), static function ( $v ) { return $v > 0 && $v <= 100; } ) ) );
			if ( empty( $out['per_page_options'] ) ) {
				$out['per_page_options'] = $defaults['per_page_options'];
			}
		}
		if ( ! in_array( (int) $out['per_page_default'], array_map( 'intval', $out['per_page_options'] ), true ) ) {
			$out['per_page_default'] = (int) $out['per_page_options'][0];
		}

		if ( isset( $input['weights'] ) && is_array( $input['weights'] ) ) {
			foreach ( $defaults['weights'] as $key => $value ) {
				if ( isset( $input['weights'][ $key ] ) ) {
					$out['weights'][ $key ] = max( 0, (float) $input['weights'][ $key ] );
				}
			}
		}
		if ( isset( $input['highlight_tag'] ) ) {
			$tag = sanitize_key( $input['highlight_tag'] );
			$out['highlight_tag'] = in_array( $tag, array( 'mark', 'span', 'em' ), true ) ? $tag : 'mark';
		}
		if ( isset( $input['highlight_class'] ) ) {
			$out['highlight_class'] = sanitize_html_class( $input['highlight_class'] ) ?: 'adv-search-hl';
		}
		if ( isset( $input['highlight_color'] ) ) {
			$out['highlight_color'] = sanitize_hex_color( $input['highlight_color'] ) ?: $defaults['highlight_color'];
		}
		if ( isset( $input['zero_message'] ) ) {
			$out['zero_message'] = sanitize_text_field( $input['zero_message'] );
		}
		if ( isset( $input['suggested_links'] ) ) {
			$out['suggested_links'] = self::parse_links( $input['suggested_links'] );
		}
		return $out;
	}

	public static function settings_changed( $old, $new ) {
		update_option( 'adv_search_enabled', ! empty( $new['enabled'] ), false );
		Adv_Search_Indexer::flush_cache();
		if ( empty( $old['enabled'] ) && ! empty( $new['enabled'] ) ) {
			Adv_Search_Schema::install();
		}
		if ( isset( $old['post_types'], $new['post_types'] ) && $old['post_types'] !== $new['post_types'] && Adv_Search_Schema::ready() ) {
			Adv_Search_Indexer::clear_index();
			update_option( 'adv_search_needs_rebuild', 1, false );
		}
	}

	public static function assets() {
		$options = self::options();
		$css     = get_template_directory() . '/assets/css/adv-search.css';
		$js      = get_template_directory() . '/assets/js/adv-search.js';
		wp_enqueue_style( 'adv-search', get_template_directory_uri() . '/assets/css/adv-search.css', array( 'adventistai-overrides' ), is_readable( $css ) ? filemtime( $css ) : '1' );
		wp_add_inline_style( 'adv-search', ':root{--adv-search-highlight:' . esc_attr( $options['highlight_color'] ) . ';}' );
		if ( ! empty( $options['live_enabled'] ) ) {
			wp_enqueue_script( 'adv-search', get_template_directory_uri() . '/assets/js/adv-search.js', array(), is_readable( $js ) ? filemtime( $js ) : '1', true );
			wp_localize_script(
				'adv-search',
				'AdvSearchConfig',
				array(
					'endpoint' => esc_url_raw( rest_url( 'adventistai/v1/search' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'minChars' => (int) $options['min_chars'],
					'delay'    => (int) $options['live_delay'],
					'limit'    => (int) $options['live_limit'],
					'suggestions' => array_map(
						static function ( $link ) {
							return array( 'label' => sanitize_text_field( $link['label'] ), 'url' => esc_url_raw( $link['url'] ) );
						},
						(array) $options['suggested_links']
					),
					'strings'  => array(
						'found'   => __( 'Rasta %d rezultatų', 'alps' ),
						'none'    => __( 'Nieko nerasta pagal „%s“', 'alps' ),
						'all'     => __( 'Rodyti visus rezultatus (%d)', 'alps' ),
						'loading' => __( 'Ieškoma…', 'alps' ),
					),
				)
			);
		}
	}

	public static function defer_script( $tag, $handle ) {
		return 'adv-search' === $handle ? str_replace( ' src=', ' defer src=', $tag ) : $tag;
	}

	public static function noindex_search( $robots ) {
		if ( is_search() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	public static function index_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options = self::options();
		$switch  = get_option( 'adv_search_enabled', null );
		$requested = null === $switch ? ! empty( $options['enabled'] ) : (bool) $switch;
		if ( $requested && ! function_exists( 'mb_strtolower' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Išplėstinei paieškai būtinas PHP mbstring plėtinys. Kol jis neįjungtas, naudojama įprasta WordPress paieška.', 'alps' ) . '</p></div>';
			return;
		}
		if ( ! self::enabled() ) {
			return;
		}
		$status = Adv_Search_Schema::status();
		if ( $status['ready'] && $status['rows'] > 0 && $status['tokens'] > 0 && ! get_option( 'adv_search_needs_rebuild' ) ) {
			return;
		}
		$url = add_query_arg( array( 'page' => 'adv-search', 'tab' => 'index' ), admin_url( 'themes.php' ) );
		printf( '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>', esc_html__( 'Išplėstinės paieškos indeksą reikia sukurti.', 'alps' ), esc_url( $url ), esc_html__( 'Atidaryti paieškos nustatymus', 'alps' ) );
	}

	public static function cli_reindex() {
		Adv_Search_Schema::install();
		Adv_Search_Indexer::clear_index();
		$offset = 0;
		do {
			$batch = Adv_Search_Indexer::rebuild_batch( $offset, 50 );
			$offset = $batch['next'];
			WP_CLI::log( sprintf( '%d / %d', min( $offset, $batch['total'] ), $batch['total'] ) );
		} while ( ! $batch['done'] );
		delete_option( 'adv_search_needs_rebuild' );
		WP_CLI::success( 'Search index rebuilt.' );
	}

	private static function csv_strings( $value ) {
		return preg_split( '/[\s,]+/u', is_array( $value ) ? implode( ',', $value ) : (string) $value, -1, PREG_SPLIT_NO_EMPTY );
	}

	private static function csv_ints( $value ) {
		return array_values( array_unique( array_filter( array_map( 'absint', self::csv_strings( $value ) ) ) ) );
	}

	private static function parse_links( $value ) {
		$links = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( 2 === count( $parts ) && $parts[0] && $parts[1] ) {
				$links[] = array( 'label' => sanitize_text_field( $parts[0] ), 'url' => esc_url_raw( $parts[1] ) );
			}
		}
		return array_slice( $links, 0, 10 );
	}
}
