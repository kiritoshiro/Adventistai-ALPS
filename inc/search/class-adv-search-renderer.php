<?php

defined( 'ABSPATH' ) || exit;

final class Adv_Search_Renderer {
	private static $form_count = 0;

	public static function render_search_form() {
		self::$form_count++;
		$id      = 'adv-search-' . self::$form_count;
		$query   = get_search_query();
		$enabled = Adv_Search::enabled();

		if ( ! $enabled ) {
			return sprintf(
				'<form role="search" method="get" class="search-form" action="%1$s"><label><span class="screen-reader-text">%2$s</span><input type="search" class="search-field" placeholder="%3$s" value="%4$s" name="s"></label><button type="submit" class="search-submit">%5$s</button></form>',
				esc_url( home_url( '/' ) ),
				esc_html__( 'Ieškoti:', 'alps' ),
				esc_attr__( 'Ieškoti…', 'alps' ),
				esc_attr( $query ),
				esc_html__( 'Ieškoti', 'alps' )
			);
		}

		return sprintf(
			'<div class="adv-search" data-adv-search><form role="search" method="get" class="search-form adv-search__form" action="%1$s"><label class="screen-reader-text" for="%2$s">%3$s</label><div class="adv-search__control"><input id="%2$s" type="search" class="search-field adv-search__input" value="%4$s" name="s" placeholder="%5$s" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="%2$s-listbox" aria-autocomplete="list" aria-activedescendant=""><button type="submit" class="search-submit adv-search__submit">%6$s</button></div><ul id="%2$s-listbox" class="adv-search__dropdown" role="listbox" hidden></ul><div class="screen-reader-text" data-adv-search-live aria-live="polite" aria-atomic="true"></div></form></div>',
			esc_url( home_url( '/' ) ),
			esc_attr( $id ),
			esc_html__( 'Ieškoti svetainėje', 'alps' ),
			esc_attr( $query ),
			esc_attr__( 'Ieškoti…', 'alps' ),
			esc_html__( 'Ieškoti', 'alps' )
		);
	}

	public static function render_page() {
		$options  = Adv_Search::options();
		$raw_query = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ? (string) wp_unslash( $_GET['s'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query    = sanitize_text_field( $raw_query );
		$page     = max( 1, get_query_var( 'paged' ), isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = isset( $_GET['per_page'] ) && is_scalar( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : (int) $options['per_page_default']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type     = isset( $_GET['type'] ) && is_scalar( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type     = in_array( $type, array( 'post', 'page' ), true ) ? $type : '';
		$result   = Adv_Search_Query::search( $raw_query, compact( 'page', 'per_page', 'type' ) );

		ob_start();
		?>
		<section class="adv-search-page l-main__content l-grid l-grid--7-col u-shift--left--1-col--at-large l-grid-wrap--6-of-7">
			<div class="adv-search-page__inner l-grid-item l-grid-item--l--5-col">
				<?php echo self::render_search_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<header class="adv-search-page__header">
					<h1><?php echo esc_html( sprintf( __( 'Paieškos rezultatai: „%1$s“ — rasta %2$d', 'alps' ), $result['query'], $result['total'] ) ); ?></h1>
					<?php if ( $result['fallback'] ) : ?>
						<p class="adv-search-notice"><?php esc_html_e( 'Paieškos indeksas dar neparuoštas; laikinai naudojama įprasta WordPress paieška.', 'alps' ); ?></p>
					<?php endif; ?>
				</header>

				<div class="adv-search-page__tools">
					<?php if ( ! empty( $options['show_filters'] ) ) : ?>
						<nav class="adv-search-filters" aria-label="<?php esc_attr_e( 'Rezultatų tipas', 'alps' ); ?>">
							<?php echo self::filter_link( __( 'Visi', 'alps' ), '', $type, $query, $result['per_page'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo self::filter_link( __( 'Straipsniai', 'alps' ), 'post', $type, $query, $result['per_page'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo self::filter_link( __( 'Puslapiai', 'alps' ), 'page', $type, $query, $result['per_page'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</nav>
					<?php endif; ?>
					<form class="adv-search-per-page" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
						<input type="hidden" name="s" value="<?php echo esc_attr( $query ); ?>">
						<?php if ( $type ) : ?><input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>"><?php endif; ?>
						<label><?php esc_html_e( 'Rezultatų puslapyje', 'alps' ); ?>
							<select name="per_page" data-adv-per-page>
								<?php foreach ( $options['per_page_options'] as $amount ) : ?>
									<option value="<?php echo esc_attr( $amount ); ?>" <?php selected( (int) $amount, (int) $result['per_page'] ); ?>><?php echo esc_html( $amount ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</form>
				</div>

				<div class="adv-search-results" data-adv-results>
					<?php if ( $result['items'] ) : ?>
						<?php foreach ( $result['items'] as $item ) : ?>
							<?php echo self::render_result( $item, $result['terms'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					<?php else : ?>
						<div class="adv-search-empty">
							<p><?php echo esc_html( $options['zero_message'] . ' „' . $query . '“' ); ?></p>
							<p><?php esc_html_e( 'Ieškoma pagal žodžių pradžią.', 'alps' ); ?></p>
							<?php echo self::suggested_links(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					<?php endif; ?>
				</div>

				<?php echo self::pagination( $result, $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	public static function result_payload( $item, $terms ) {
		$options = Adv_Search::options();
		return array(
			'id'           => (int) $item['post_id'],
			'title_html'   => self::highlight( $item['title_raw'], $terms ),
			'url'          => esc_url_raw( $item['permalink'] ),
			'type'         => $item['post_type'],
			'type_label'   => 'page' === $item['post_type'] ? __( 'Puslapis', 'alps' ) : __( 'Straipsnis', 'alps' ),
			'date'         => mysql2date( get_option( 'date_format' ), get_date_from_gmt( $item['post_date'] ) ),
			'snippet_html' => self::snippet( $item, $terms, (int) $options['snippet_length'] ),
			'matched_in'   => $item['matched_in'],
		);
	}

	public static function render_result( $item, $terms, $compact = false ) {
		$options = Adv_Search::options();
		$data    = self::result_payload( $item, $terms );
		$meta    = array();
		if ( ! empty( $options['show_type'] ) ) {
			$meta[] = $data['type_label'];
		}
		if ( ! empty( $options['show_date'] ) ) {
			$meta[] = $data['date'];
		}
		if ( ! empty( $options['show_matched_in'] ) ) {
			$meta[] = 'title' === $data['matched_in'] ? __( 'Rasta pavadinime', 'alps' ) : __( 'Rasta turinyje', 'alps' );
		}
		$categories = 'post' === $data['type'] ? get_the_category( $data['id'] ) : array();
		if ( $categories ) {
			$meta[] = implode( ', ', wp_list_pluck( $categories, 'name' ) );
		}

		$classes = 'adv-search-result';
		$classes .= $compact ? ' adv-search-result--compact' : '';
		$thumb    = $compact ? '' : self::render_result_thumb( $data );
		$classes .= $thumb ? ' adv-search-result--has-thumb' : '';

		return sprintf(
			'<article class="%1$s">%2$s<div class="adv-search-result__body"><h2 class="adv-search-result__title"><a href="%3$s">%4$s</a></h2>%5$s<div class="adv-search-result__snippet">%6$s</div></div></article>',
			esc_attr( $classes ),
			$thumb,
			esc_url( $data['url'] ),
			wp_kses( $data['title_html'], self::allowed_highlight_html() ),
			$meta ? '<p class="adv-search-result__meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>' : '',
			wp_kses( $data['snippet_html'], self::allowed_highlight_html() )
		);
	}

	/**
	 * Featured image for a result, shown beside the text.
	 *
	 * The title link that follows points at the same URL, so this one is hidden
	 * from assistive technology rather than announced twice.
	 */
	private static function render_result_thumb( $data ) {
		if ( ! has_post_thumbnail( $data['id'] ) ) {
			return '';
		}

		$image = get_the_post_thumbnail(
			$data['id'],
			'medium',
			array(
				'alt'      => '',
				'loading'  => 'lazy',
				'decoding' => 'async',
			)
		);

		if ( ! $image ) {
			return '';
		}

		return sprintf(
			'<div class="adv-search-result__thumb"><a href="%1$s" tabindex="-1" aria-hidden="true">%2$s</a></div>',
			esc_url( $data['url'] ),
			$image
		);
	}

	public static function highlight( $raw, $terms ) {
		$ranges = self::match_ranges( $raw, $terms );
		if ( empty( $ranges ) ) {
			return esc_html( $raw );
		}
		$options = Adv_Search::options();
		$tag     = in_array( $options['highlight_tag'], array( 'mark', 'span', 'em' ), true ) ? $options['highlight_tag'] : 'mark';
		$class   = sanitize_html_class( $options['highlight_class'] );
		$chars   = preg_split( '//u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$out     = '';
		$cursor  = 0;
		foreach ( $ranges as $range ) {
			$out .= esc_html( implode( '', array_slice( $chars, $cursor, $range[0] - $cursor ) ) );
			$out .= '<' . $tag . ' class="' . esc_attr( $class ) . '">' . esc_html( implode( '', array_slice( $chars, $range[0], $range[1] - $range[0] ) ) ) . '</' . $tag . '>';
			$cursor = $range[1];
		}
		$out .= esc_html( implode( '', array_slice( $chars, $cursor ) ) );
		return $out;
	}

	private static function snippet( $item, $terms, $length ) {
		$raw    = $item['content_raw'] ? $item['content_raw'] : $item['excerpt_raw'];
		$length = max( 60, $length );
		$ranges = self::match_ranges( $raw, $terms );
		$chars  = preg_split( '//u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$total  = count( $chars );
		$centers = ( 'title' !== $item['matched_in'] && $ranges ) ? array_column( $ranges, 0 ) : array( 0 );
		$maximum = max( 1, (int) Adv_Search::options()['max_snippets'] );
		$snippets = array();
		$previous_end = -1;
		foreach ( $centers as $center ) {
			$window = self::snippet_window( $chars, $total, $center, $length );
			if ( $window[0] <= $previous_end ) {
				continue;
			}
			$fragment  = implode( '', array_slice( $chars, $window[0], $window[1] - $window[0] ) );
			$snippets[] = ( $window[0] > 0 ? '…' : '' ) . self::highlight( $fragment, $terms ) . ( $window[1] < $total ? '…' : '' );
			$previous_end = $window[1];
			if ( count( $snippets ) >= $maximum ) {
				break;
			}
		}
		return implode( ' … ', $snippets );
	}

	private static function snippet_window( $chars, $total, $center, $length ) {
		$start  = max( 0, $center - (int) floor( $length / 3 ) );
		$end    = min( $total, $start + $length );

		while ( $start > 0 && preg_match( '/[\p{L}\p{N}]/u', $chars[ $start ] ) ) {
			$start--;
		}
		while ( $end < $total && preg_match( '/[\p{L}\p{N}]/u', $chars[ $end - 1 ] ) ) {
			$end++;
		}
		return array( $start, $end );
	}

	private static function match_ranges( $raw, $terms ) {
		list( $normal, $map ) = Adv_Search_Normalizer::normalize_with_map( (string) $raw );
		$ranges = array();
		foreach ( array_filter( (array) $terms ) as $term ) {
			$pattern = '/(?:^| )(' . preg_quote( $term, '/' ) . ')/u';
			if ( preg_match_all( $pattern, $normal, $found, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $found[1] as $match ) {
					$normal_start = mb_strlen( substr( $normal, 0, $match[1] ), 'UTF-8' );
					$normal_end   = $normal_start + mb_strlen( $match[0], 'UTF-8' ) - 1;
					if ( isset( $map[ $normal_start ], $map[ $normal_end ] ) ) {
						$ranges[] = array( $map[ $normal_start ], $map[ $normal_end ] + 1 );
					}
				}
			}
		}
		usort( $ranges, static function ( $a, $b ) { return $a[0] <=> $b[0]; } );
		$merged = array();
		foreach ( $ranges as $range ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $range[0] <= $merged[ $last ][1] ) {
				$merged[ $last ][1] = max( $merged[ $last ][1], $range[1] );
			} else {
				$merged[] = $range;
			}
		}
		return $merged;
	}

	private static function allowed_highlight_html() {
		return array( 'mark' => array( 'class' => true ), 'span' => array( 'class' => true ), 'em' => array( 'class' => true ) );
	}

	private static function filter_link( $label, $value, $current, $query, $per_page ) {
		$url = add_query_arg( array_filter( array( 's' => $query, 'type' => $value, 'per_page' => $per_page ) ), home_url( '/' ) );
		return sprintf( '<a class="adv-search-filter%1$s" href="%2$s">%3$s</a>', $value === $current ? ' is-active' : '', esc_url( $url ), esc_html( $label ) );
	}

	private static function pagination( $result, $type ) {
		if ( $result['pages'] < 2 ) {
			return '';
		}
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'page', '%#%', home_url( '/' ) ),
				'format'    => '',
				'current'   => $result['page'],
				'total'     => $result['pages'],
				'type'      => 'list',
				'add_args'  => array_filter( array( 's' => $result['query'], 'per_page' => $result['per_page'], 'type' => $type ) ),
				'prev_text' => __( 'Ankstesnis', 'alps' ),
				'next_text' => __( 'Kitas', 'alps' ),
			)
		);
		return '<nav class="adv-search-pagination" aria-label="' . esc_attr__( 'Paieškos rezultatų puslapiai', 'alps' ) . '">' . wp_kses_post( $links ) . '</nav>';
	}

	private static function suggested_links() {
		$links = Adv_Search::options()['suggested_links'];
		if ( empty( $links ) ) {
			return '';
		}
		$html = '<ul class="adv-search-suggestions">';
		foreach ( $links as $link ) {
			if ( empty( $link['url'] ) || empty( $link['label'] ) ) {
				continue;
			}
			$html .= '<li><a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a></li>';
		}
		return $html . '</ul>';
	}
}
