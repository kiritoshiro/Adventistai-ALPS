<?php

defined( 'ABSPATH' ) || exit;

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
