<?php

defined( 'ABSPATH' ) || exit;

final class Adv_Search_Schema {
	const VERSION = '1.0.0';

	public static function index_table() {
		global $wpdb;
		return $wpdb->prefix . 'adv_search_index';
	}

	public static function tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'adv_search_tokens';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$index   = self::index_table();
		$tokens  = self::tokens_table();

		dbDelta( "CREATE TABLE {$index} (
			post_id bigint(20) unsigned NOT NULL,
			post_type varchar(20) NOT NULL,
			permalink varchar(255) NOT NULL,
			title_raw text NOT NULL,
			title_norm text NOT NULL,
			content_raw longtext NOT NULL,
			content_norm longtext NOT NULL,
			excerpt_raw text NOT NULL,
			post_date datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (post_id),
			KEY post_type (post_type),
			KEY post_date (post_date)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$tokens} (
			post_id bigint(20) unsigned NOT NULL,
			token varchar(64) NOT NULL,
			field tinyint(3) unsigned NOT NULL,
			position int(10) unsigned NOT NULL,
			PRIMARY KEY  (post_id,field,position),
			KEY token_field (token(20),field),
			KEY post_field (post_id,field)
		) {$charset};" );

		update_option( 'adv_search_schema_version', self::VERSION, false );
	}

	public static function ready() {
		global $wpdb;
		$index  = self::index_table();
		$tokens = self::tokens_table();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $index ) ) ) === $index
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tokens ) ) ) === $tokens;
	}

	public static function status() {
		global $wpdb;
		$status = array(
			'ready'       => self::ready(),
			'rows'        => 0,
			'tokens'      => 0,
			'size_bytes'  => 0,
			'last_build'  => get_option( 'adv_search_last_build', '' ),
			'db_version'  => $wpdb->db_version(),
			'collation'   => $wpdb->collate,
			'mbstring'    => extension_loaded( 'mbstring' ),
			'intl'        => extension_loaded( 'intl' ),
		);

		if ( ! $status['ready'] ) {
			return $status;
		}

		$index         = self::index_table();
		$tokens        = self::tokens_table();
		$status['rows']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$status['tokens'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tokens}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$tables = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT data_length + index_length AS bytes FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name IN (%s,%s)',
				$index,
				$tokens
			)
		);
		foreach ( (array) $tables as $table ) {
			$status['size_bytes'] += (int) $table->bytes;
		}
		return $status;
	}

	/**
	 * Explicit cleanup helper. Theme deletion does not call this automatically.
	 */
	public static function uninstall() {
		global $wpdb;
		$index  = self::index_table();
		$tokens = self::tokens_table();
		$wpdb->query( "DROP TABLE IF EXISTS {$tokens}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$index}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( 'adv_search_options' );
		delete_option( 'adv_search_enabled' );
		delete_option( 'adv_search_schema_version' );
		delete_option( 'adv_search_last_build' );
		delete_option( 'adv_search_cache_version' );
		delete_option( 'adv_search_needs_rebuild' );
	}
}
