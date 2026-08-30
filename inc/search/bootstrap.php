<?php

defined( 'ABSPATH' ) || exit;

/*
 * Theme-level admin tooling is loaded here because this bootstrap is required
 * on every theme request from functions.php. Keep it independent from the
 * advanced-search enabled/disabled state.
 */
require_once get_template_directory() . '/app/AdventistaiAlpsReleaseManager.php';
Adventistai_Alps_Release_Manager::bootstrap();

$adv_search_files = array(
	'class-adv-search-normalizer.php',
	'class-adv-search-schema.php',
	'class-adv-search-indexer.php',
	'class-adv-search-query.php',
	'class-adv-search-renderer.php',
	'class-adv-search-rest.php',
	'class-adv-search-admin.php',
	'class-adv-search.php',
);

foreach ( $adv_search_files as $adv_search_file ) {
	require_once __DIR__ . '/' . $adv_search_file;
}

Adv_Search::boot();
