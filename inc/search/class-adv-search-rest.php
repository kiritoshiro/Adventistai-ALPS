<?php

defined( 'ABSPATH' ) || exit;

final class Adv_Search_REST {
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'adventistai/v1',
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
				'args'                => array(
					'q'        => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'per_page' => array( 'sanitize_callback' => 'absint' ),
					'page'     => array( 'sanitize_callback' => 'absint' ),
					'type'     => array( 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		register_rest_route(
			'adventistai/v1',
			'/search/reindex',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'reindex' ),
				'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
				'args'                => array( 'offset' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
	}

	public static function public_permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	public static function search( WP_REST_Request $request ) {
		$options = Adv_Search::options();
		if ( ! Adv_Search::enabled() || empty( $options['live_enabled'] ) ) {
			return new WP_Error( 'adv_search_disabled', __( 'Tiesioginė paieška išjungta.', 'alps' ), array( 'status' => 404 ) );
		}
		if ( ! self::within_rate_limit( (int) $options['rate_limit_count'], (int) $options['rate_limit_window'] ) ) {
			return new WP_Error( 'adv_search_rate_limited', __( 'Per daug paieškos užklausų. Bandykite dar kartą po minutės.', 'alps' ), array( 'status' => 429 ) );
		}

		$q        = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$requested_limit = $request->get_param( 'per_page' );
		$limit    = min( (int) $options['live_limit'], max( 1, $requested_limit ? (int) $requested_limit : (int) $options['live_limit'] ) );
		$per_page = (int) $options['per_page_default'];
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$type     = sanitize_key( (string) $request->get_param( 'type' ) );
		$type     = in_array( $type, array( 'post', 'page' ), true ) ? $type : '';
		$normal   = Adv_Search_Normalizer::normalize( $q );
		if ( mb_strlen( $normal, 'UTF-8' ) < (int) $options['min_chars'] ) {
			return rest_ensure_response(
				array(
					'query'    => $q,
					'total'    => 0,
					'took_ms'  => 0,
					'results'  => array(),
					'more_url' => add_query_arg( 's', $q, home_url( '/' ) ),
				)
			);
		}
		$args     = compact( 'per_page', 'page', 'type' );
		$version  = get_option( 'adv_search_cache_version', '1' );
		$key      = 'advs_' . md5( $version . '|' . $normal . '|' . wp_json_encode( $args ) );
		$payload  = get_transient( $key );

		if ( false !== $payload ) {
			return rest_ensure_response( $payload );
		}

		$search  = Adv_Search_Query::search( $q, $args );
		$results = array();
		foreach ( array_slice( $search['items'], 0, $limit ) as $item ) {
			$results[] = Adv_Search_Renderer::result_payload( $item, $search['terms'] );
		}
		$payload = array(
			'query'    => $search['query'],
			'total'    => $search['total'],
			'took_ms'  => $search['took_ms'],
			'results'  => $results,
			'more_url' => add_query_arg( array_filter( array( 's' => $search['query'], 'type' => $type ) ), home_url( '/' ) ),
		);
		if ( (int) $options['cache_ttl'] > 0 ) {
			set_transient( $key, $payload, (int) $options['cache_ttl'] );
		}
		return rest_ensure_response( $payload );
	}

	public static function reindex( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Neturite teisės atlikti šio veiksmo.', 'alps' ), array( 'status' => 403 ) );
		}
		$offset = absint( $request->get_param( 'offset' ) );
		if ( 0 === $offset ) {
			Adv_Search_Schema::install();
			Adv_Search_Indexer::clear_index();
		}
		$result = Adv_Search_Indexer::rebuild_batch( $offset, 50 );
		if ( $result['done'] ) {
			delete_option( 'adv_search_needs_rebuild' );
		}
		return rest_ensure_response( $result );
	}

	private static function within_rate_limit( $maximum, $window ) {
		$maximum = max( 1, $maximum );
		$window  = max( 10, $window );
		$ip      = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : '';
		if ( '' === $ip && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		$key   = 'advs_rl_' . hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
		$count = (int) get_transient( $key );
		if ( $count >= $maximum ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}
}
