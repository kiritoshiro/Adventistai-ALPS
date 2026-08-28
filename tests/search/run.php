<?php

/**
 * Dependency-free unit checks: php tests/search/run.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__, 2 ) . '/inc/search/class-adv-search-normalizer.php';
require_once dirname( __DIR__, 2 ) . '/inc/search/class-adv-search-query.php';

$failures = 0;

function advs_assert_same( $expected, $actual, $message ) {
	global $failures;
	if ( $expected !== $actual ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
	}
}

function advs_prefix_matches( $query, $text ) {
	$term = Adv_Search_Normalizer::normalize( $query );
	foreach ( Adv_Search_Normalizer::tokenize( $text ) as $token ) {
		if ( 0 === strpos( $token['token'], $term ) ) {
			return true;
		}
	}
	return false;
}

advs_assert_same( 'ziniu apzvalga', Adv_Search_Normalizer::normalize( 'Žinių apžvalga' ), 'Lithuanian folding' );
advs_assert_same( 'ziniu', Adv_Search_Normalizer::normalize( 'ŽINIŲ' ), 'Uppercase Lithuanian folding' );
advs_assert_same( 'azuolas', Adv_Search_Normalizer::normalize( 'ąžuolas' ), 'ąžuolas folding' );
advs_assert_same( 'strasse oeuvre', Adv_Search_Normalizer::normalize( 'Straße œuvre' ), '1:N Latin folding' );
advs_assert_same( true, advs_prefix_matches( 'gies', 'Giesmynas' ), 'Prefix matches word start' );
advs_assert_same( false, advs_prefix_matches( 'mynas', 'Giesmynas' ), 'Mid-word does not match' );
advs_assert_same( false, advs_prefix_matches( 'gies', 'Bendragiesmininkas' ), 'Mid-word occurrence does not match' );
advs_assert_same( true, advs_prefix_matches( '2024', 'Stovykla 2024' ), 'Digits are indexed' );

$hyphen_tokens = array_column( Adv_Search_Normalizer::tokenize( 'bendra-darbis' ), 'token' );
advs_assert_same( array( 'bendra', 'darbis', 'bendradarbis' ), $hyphen_tokens, 'Hyphen compound parts and joined form' );

$options = array(
	'allow_phrases'  => true,
	'stopwords'      => array( 'ir', 'yra' ),
	'min_term_length'=> 2,
	'max_terms'      => 6,
);
$parsed = Adv_Search_Query::parse( '"Žinių apžvalga" ir GIESMĖ', $options );
advs_assert_same( array( 'ziniu', 'apzvalga', 'giesme' ), $parsed['terms'], 'Parser folds and removes stopwords' );
advs_assert_same( array( array( 'ziniu', 'apzvalga' ) ), $parsed['phrases'], 'Quoted phrase parsed' );
$unsafe = Adv_Search_Query::parse( '% <script>alert(1)</script>', $options );
advs_assert_same( array( 'script', 'alert' ), $unsafe['terms'], 'Punctuation cannot become a wildcard' );

if ( $failures ) {
	exit( 1 );
}

echo "All advanced-search unit checks passed.\n";
