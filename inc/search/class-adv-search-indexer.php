<?php

defined( 'ABSPATH' ) || exit;

final class Adv_Search_Indexer {
	const FIELD_TITLE   = 1;
	const FIELD_EXCERPT = 2;
	const FIELD_CONTENT = 3;

	public static function register_hooks() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'delete_post' ) );
		add_action( 'trashed_post', array( __CLASS__, 'delete_post' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'index_post' ) );
		add_action( 'post_updated', array( __CLASS__, 'on_updated' ), 20, 3 );
	}

	public static function on_save( $post_id, $post, $update ) {
		unset( $update );
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::index_post( $post_id, $post );
	}

	public static function on_updated( $post_id, $after, $before ) {
		if ( $after->post_name !== $before->post_name || $after->post_parent !== $before->post_parent ) {
			self::index_post( $post_id, $after );
		}
	}

	public static function should_index( $post ) {
		$options = Adv_Search::options();
		$types   = array_values( array_intersect( (array) $options['post_types'], array_keys( get_post_types( array( 'public' => true ) ) ) ) );
		$exclude = array_map( 'absint', (array) $options['exclude_ids'] );

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return false;
		}
		if ( ! in_array( $post->post_type, $types, true ) || in_array( (int) $post->ID, $exclude, true ) ) {
			return false;
		}

		$excluded_terms = array_merge( (array) $options['exclude_categories'], (array) $options['exclude_tags'] );
		if ( ! empty( $excluded_terms ) && has_term( array_map( 'absint', $excluded_terms ), array( 'category', 'post_tag' ), $post ) ) {
			return false;
		}
		return true;
	}

	public static function index_post( $post_id, $post = null ) {
		global $wpdb;
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );

		if ( ! self::should_index( $post ) ) {
			self::delete_post( $post_id );
			return false;
		}

		$options = Adv_Search::options();
		$title   = wp_strip_all_tags( get_the_title( $post ), true );
		try {
			$content = self::plain_content( $post->post_content );
		} catch ( Throwable $error ) {
			$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ), true );
		}
		$excerpt = ! empty( $options['include_excerpt'] ) ? wp_strip_all_tags( $post->post_excerpt, true ) : '';
		if ( ! empty( $options['include_custom_fields'] ) ) {
			foreach ( (array) $options['custom_field_keys'] as $meta_key ) {
				$values = get_post_meta( $post->ID, $meta_key, false );
				foreach ( $values as $value ) {
					if ( is_scalar( $value ) ) {
						$content .= ' ' . wp_strip_all_tags( (string) $value, true );
					}
				}
			}
		}
		$max     = max( 1000, (int) $options['content_limit'] );
		$content = mb_substr( $content, 0, $max, 'UTF-8' );
		$index   = Adv_Search_Schema::index_table();
		$tokens  = Adv_Search_Schema::tokens_table();
		$now     = current_time( 'mysql', true );

		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->delete( $tokens, array( 'post_id' => (int) $post->ID ), array( '%d' ) );
			$ok = $wpdb->replace(
				$index,
				array(
					'post_id'      => (int) $post->ID,
					'post_type'    => $post->post_type,
					'permalink'    => mb_substr( get_permalink( $post ), 0, 255, 'UTF-8' ),
					'title_raw'    => $title,
					'title_norm'   => Adv_Search_Normalizer::normalize( $title ),
					'content_raw'  => $content,
					'content_norm' => Adv_Search_Normalizer::normalize( $content ),
					'excerpt_raw'  => $excerpt,
					'post_date'    => get_post_time( 'Y-m-d H:i:s', true, $post ),
					'updated_at'   => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $ok ) {
				throw new RuntimeException( 'Could not update search index row.' );
			}

			self::insert_tokens( (int) $post->ID, self::FIELD_TITLE, $title, $tokens, (int) $options['max_tokens'] );
			if ( '' !== $excerpt ) {
				self::insert_tokens( (int) $post->ID, self::FIELD_EXCERPT, $excerpt, $tokens, (int) $options['max_tokens'] );
			}
			self::insert_tokens( (int) $post->ID, self::FIELD_CONTENT, $content, $tokens, (int) $options['max_tokens'] );
			$wpdb->query( 'COMMIT' );
			self::flush_cache();
			return true;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}

	private static function insert_tokens( $post_id, $field, $text, $table, $limit ) {
		global $wpdb;
		$rows = Adv_Search_Normalizer::tokenize( $text, $limit );
		foreach ( array_chunk( $rows, 250 ) as $chunk ) {
			$placeholders = array();
			$values       = array();
			foreach ( $chunk as $row ) {
				$placeholders[] = '(%d,%s,%d,%d)';
				$values[]       = $post_id;
				$values[]       = $row['token'];
				$values[]       = $field;
				$values[]       = $row['position'];
			}
			if ( ! empty( $values ) ) {
				$sql = "INSERT INTO {$table} (post_id,token,field,position) VALUES " . implode( ',', $placeholders ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}

	public static function delete_post( $post_id ) {
		global $wpdb;
		$post_id = absint( $post_id );
		if ( ! $post_id || ! Adv_Search_Schema::ready() ) {
			return;
		}
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->delete( Adv_Search_Schema::tokens_table(), array( 'post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( Adv_Search_Schema::index_table(), array( 'post_id' => $post_id ), array( '%d' ) );
		$wpdb->query( 'COMMIT' );
		self::flush_cache();
	}

	public static function rebuild_batch( $offset = 0, $limit = 50 ) {
		$options = Adv_Search::options();
		$types   = array_values( array_intersect( (array) $options['post_types'], array_keys( get_post_types( array( 'public' => true ) ) ) ) );
		$query   = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => min( 100, max( 1, (int) $limit ) ),
				'offset'                 => max( 0, (int) $offset ),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $query->posts as $post_id ) {
			self::index_post( $post_id );
		}
		$done = ( (int) $offset + count( $query->posts ) ) >= (int) $query->found_posts;
		if ( $done ) {
			update_option( 'adv_search_last_build', current_time( 'mysql' ), false );
		}
		return array(
			'processed' => count( $query->posts ),
			'total'     => (int) $query->found_posts,
			'next'      => (int) $offset + count( $query->posts ),
			'done'      => $done,
		);
	}

	public static function clear_index() {
		global $wpdb;
		$index  = Adv_Search_Schema::index_table();
		$tokens = Adv_Search_Schema::tokens_table();
		$wpdb->query( "TRUNCATE TABLE {$tokens}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$index}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::flush_cache();
	}

	public static function flush_cache() {
		update_option( 'adv_search_cache_version', wp_generate_uuid4(), false );
	}

	private static function plain_content( $content ) {
		$content = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $content );
		$content = preg_replace( '#<([a-z0-9]+)\b[^>]*class=("|\')[^"\']*screen-reader-text[^"\']*\2[^>]*>.*?</\1>#is', ' ', $content );
		$content = function_exists( 'do_blocks' ) ? do_blocks( $content ) : $content;
		$content = apply_filters( 'the_content', $content );
		$content = strip_shortcodes( $content );
		$content = wp_strip_all_tags( $content, true );
		return trim( preg_replace( '/\s+/u', ' ', $content ) );
	}
}
