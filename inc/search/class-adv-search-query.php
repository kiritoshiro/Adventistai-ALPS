<?php

defined( 'ABSPATH' ) || exit;

final class Adv_Search_Query {
	public static function parse( $raw_query, $options = null ) {
		$options = is_array( $options ) ? $options : Adv_Search::options();
		$raw     = mb_substr( trim( (string) $raw_query ), 0, 100, 'UTF-8' );
		$phrases = array();

		if ( ! empty( $options['allow_phrases'] ) && preg_match_all( '/"([^"]+)"/u', $raw, $matches ) ) {
			foreach ( $matches[1] as $phrase ) {
				$parts = array_values( array_filter( explode( ' ', Adv_Search_Normalizer::normalize( $phrase ) ) ) );
				if ( count( $parts ) > 1 ) {
					$phrases[] = $parts;
				}
			}
		}

		$normalized = Adv_Search_Normalizer::normalize( str_replace( '"', ' ', $raw ) );
		$terms      = array_values( array_unique( array_filter( explode( ' ', $normalized ) ) ) );
		$stopwords  = array_map( array( 'Adv_Search_Normalizer', 'normalize' ), (array) $options['stopwords'] );
		$minimum    = max( 1, (int) $options['min_term_length'] );
		$filtered   = array();

		foreach ( $terms as $term ) {
			if ( mb_strlen( $term, 'UTF-8' ) >= $minimum && ! in_array( $term, $stopwords, true ) ) {
				$filtered[] = $term;
			}
		}
		if ( empty( $filtered ) && ! empty( $terms ) ) {
			$filtered = $terms;
		}

		return array(
			'raw'        => $raw,
			'normalized' => $normalized,
			'terms'      => array_slice( $filtered, 0, max( 1, (int) $options['max_terms'] ) ),
			'phrases'    => $phrases,
		);
	}

	public static function search( $raw_query, $args = array() ) {
		global $wpdb;
		$started = microtime( true );
		$options = Adv_Search::options();
		$unsafe_markup = preg_match( '/[<>]/u', (string) $raw_query );
		$parsed  = self::parse( sanitize_text_field( (string) $raw_query ), $options );
		$allowed = array_map( 'intval', (array) $options['per_page_options'] );
		$per_page = isset( $args['per_page'] ) && in_array( (int) $args['per_page'], $allowed, true )
			? (int) $args['per_page']
			: (int) $options['per_page_default'];
		$page    = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$type    = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : '';
		$types   = array_values( array_intersect( (array) $options['post_types'], array_keys( get_post_types( array( 'public' => true ) ) ) ) );
		if ( $type && in_array( $type, $types, true ) ) {
			$types = array( $type );
		}

		if ( $unsafe_markup || empty( $parsed['terms'] ) || empty( $types ) ) {
			return self::response( $parsed, array(), 0, $page, $per_page, $started );
		}
		if ( ! Adv_Search_Schema::ready() || ! self::has_index_rows() ) {
			return self::stock_search( $parsed, $types, $page, $per_page, $started );
		}

		$index       = Adv_Search_Schema::index_table();
		$tokens      = Adv_Search_Schema::tokens_table();
		$type_marks  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$operator    = apply_filters( 'adv_search_operator', strtoupper( (string) $options['operator'] ), $parsed );
		$term_likes  = array_map( static function ( $term ) use ( $wpdb ) { return $wpdb->esc_like( $term ) . '%'; }, $parsed['terms'] );

		if ( 'OR' === $operator ) {
			$lead_conditions = implode( ' OR ', array_fill( 0, count( $term_likes ), 'lead_token.token LIKE %s' ) );
			$sql = "SELECT DISTINCT i.* FROM {$tokens} lead_token INNER JOIN {$index} i ON i.post_id = lead_token.post_id WHERE ({$lead_conditions}) AND i.post_type IN ({$type_marks})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql_values = array_merge( $term_likes, $types );
		} else {
			$sql = "SELECT DISTINCT i.* FROM {$tokens} lead_token INNER JOIN {$index} i ON i.post_id = lead_token.post_id WHERE lead_token.token LIKE %s AND i.post_type IN ({$type_marks})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql_values = array_merge( array( $term_likes[0] ), $types );
			foreach ( array_slice( $term_likes, 1 ) as $key => $like ) {
				$alias = 't' . ( (int) $key + 1 );
				$sql .= " AND EXISTS (SELECT 1 FROM {$tokens} {$alias} WHERE {$alias}.post_id = i.post_id AND {$alias}.token LIKE %s)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sql_values[] = $like;
			}
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $sql_values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $rows ) ) {
			return self::response( $parsed, array(), 0, $page, $per_page, $started );
		}

		$match_terms = $parsed['terms'];
		foreach ( $parsed['phrases'] as $phrase ) {
			$match_terms = array_merge( $match_terms, $phrase );
		}
		$matches = self::load_matches( wp_list_pluck( $rows, 'post_id' ), array_values( array_unique( $match_terms ) ), $tokens );
		$ranked  = array();
		foreach ( $rows as $row ) {
			$post_matches = isset( $matches[ $row['post_id'] ] ) ? $matches[ $row['post_id'] ] : array();
			if ( ! self::phrases_match( $parsed['phrases'], $post_matches ) ) {
				continue;
			}
			$ranked[] = self::score( $row, $post_matches, $parsed, $options );
		}

		usort(
			$ranked,
			static function ( $a, $b ) {
				if ( $a['tier'] !== $b['tier'] ) {
					return $b['tier'] <=> $a['tier'];
				}
				if ( $a['score'] !== $b['score'] ) {
					return $b['score'] <=> $a['score'];
				}
				return strcmp( $b['post_date'], $a['post_date'] );
			}
		);

		$total = count( $ranked );
		$items = array_slice( $ranked, ( $page - 1 ) * $per_page, $per_page );
		return self::response( $parsed, $items, $total, $page, $per_page, $started );
	}

	private static function has_index_rows() {
		global $wpdb;
		$index  = Adv_Search_Schema::index_table();
		$tokens = Adv_Search_Schema::tokens_table();
		return (bool) $wpdb->get_var( "SELECT post_id FROM {$index} LIMIT 1" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			&& (bool) $wpdb->get_var( "SELECT post_id FROM {$tokens} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function load_matches( $post_ids, $terms, $table ) {
		global $wpdb;
		$post_ids = array_map( 'absint', $post_ids );
		$id_marks = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$likes    = array();
		$values   = $post_ids;
		foreach ( $terms as $term ) {
			$likes[]  = 'token LIKE %s';
			$values[] = $wpdb->esc_like( $term ) . '%';
		}
		$sql  = "SELECT post_id,token,field,position FROM {$table} WHERE post_id IN ({$id_marks}) AND (" . implode( ' OR ', $likes ) . ') ORDER BY post_id,field,position'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ $row['post_id'] ][] = array(
				'token'    => $row['token'],
				'field'    => (int) $row['field'],
				'position' => (int) $row['position'],
			);
		}
		return $out;
	}

	private static function phrases_match( $phrases, $matches ) {
		if ( empty( $phrases ) ) {
			return true;
		}
		$by_field = array();
		foreach ( $matches as $match ) {
			$by_field[ $match['field'] ][ $match['position'] ] = $match['token'];
		}
		foreach ( $phrases as $phrase ) {
			$found = false;
			foreach ( $by_field as $tokens ) {
				foreach ( $tokens as $position => $token ) {
					$sequence = true;
					foreach ( $phrase as $offset => $term ) {
						if ( ! isset( $tokens[ $position + $offset ] ) || 0 !== strpos( $tokens[ $position + $offset ], $term ) ) {
							$sequence = false;
							break;
						}
					}
					if ( $sequence ) {
						$found = true;
						break 2;
					}
				}
			}
			if ( ! $found ) {
				return false;
			}
		}
		return true;
	}

	private static function score( $row, $matches, $parsed, $options ) {
		$weights     = $options['weights'];
		$score       = 0.0;
		$title_terms = array();
		$title_match = false;
		$matched_in  = 'content';

		if ( $row['title_norm'] === $parsed['normalized'] ) {
			$score += (float) $weights['exact_title'];
		} elseif ( 0 === strpos( $row['title_norm'], $parsed['normalized'] ) ) {
			$score += (float) $weights['title_starts'];
		}

		foreach ( $matches as $match ) {
			$term = self::matching_term( $match['token'], $parsed['terms'] );
			if ( null === $term ) {
				continue;
			}
			$multiplier = $match['token'] === $term ? 1.5 : 1.0;
			if ( Adv_Search_Indexer::FIELD_TITLE === $match['field'] ) {
				$title_match = true;
				$matched_in  = 'title';
				$title_terms[ $term ] = true;
				$score += $multiplier * ( 0 === $match['position'] ? (float) $weights['title_first'] : (float) $weights['title_token'] );
			} elseif ( Adv_Search_Indexer::FIELD_EXCERPT === $match['field'] ) {
				$score += $multiplier * (float) $weights['excerpt'];
			} else {
				$score += $multiplier * (float) $weights['content'];
			}
		}

		if ( count( $title_terms ) === count( $parsed['terms'] ) ) {
			$score += (float) $weights['all_title_bonus'];
		}
		if ( 'page' === $row['post_type'] ) {
			$score *= max( 0.01, (float) $weights['page_multiplier'] );
		}

		$row['tier']       = $title_match ? 1 : 0;
		$row['score']      = (float) apply_filters( 'adv_search_score', $score, $row, $matches, $parsed );
		$row['matched_in'] = $matched_in;
		return $row;
	}

	private static function matching_term( $token, $terms ) {
		foreach ( $terms as $term ) {
			if ( 0 === strpos( $token, $term ) ) {
				return $term;
			}
		}
		return null;
	}

	private static function stock_search( $parsed, $types, $page, $per_page, $started ) {
		$query = new WP_Query(
			array(
				's'              => $parsed['raw'],
				'post_type'      => $types,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => $per_page,
				'paged'          => $page,
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'post_id'      => $post->ID,
				'post_type'    => $post->post_type,
				'permalink'    => get_permalink( $post ),
				'title_raw'    => get_the_title( $post ),
				'content_raw'  => wp_strip_all_tags( $post->post_content ),
				'excerpt_raw'  => wp_strip_all_tags( $post->post_excerpt ),
				'post_date'    => $post->post_date_gmt,
				'matched_in'   => 'content',
				'tier'         => 0,
				'score'        => 0,
			);
		}
		$response = self::response( $parsed, $items, (int) $query->found_posts, $page, $per_page, $started );
		$response['fallback'] = true;
		return $response;
	}

	private static function response( $parsed, $items, $total, $page, $per_page, $started ) {
		return array(
			'query'      => $parsed['raw'],
			'normalized' => $parsed['normalized'],
			'terms'      => $parsed['terms'],
			'total'      => (int) $total,
			'page'       => (int) $page,
			'per_page'   => (int) $per_page,
			'pages'      => $per_page ? (int) ceil( $total / $per_page ) : 0,
			'took_ms'    => round( ( microtime( true ) - $started ) * 1000, 2 ),
			'items'      => $items,
			'fallback'   => false,
		);
	}
}
