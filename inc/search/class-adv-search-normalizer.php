<?php

/**
 * Lithuanian-aware, offset-safe search normalisation.
 *
 * @package Adventistai
 */

defined( 'ABSPATH' ) || exit;

final class Adv_Search_Normalizer {
	/** @var array<string,string> */
	private static $map = array(
		'ą' => 'a', 'č' => 'c', 'ę' => 'e', 'ė' => 'e', 'į' => 'i', 'š' => 's', 'ų' => 'u', 'ū' => 'u', 'ž' => 'z',
		'Ą' => 'a', 'Č' => 'c', 'Ę' => 'e', 'Ė' => 'e', 'Į' => 'i', 'Š' => 's', 'Ų' => 'u', 'Ū' => 'u', 'Ž' => 'z',
		'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
		'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Ã' => 'a', 'Å' => 'a',
		'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
		'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
		'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Ö' => 'o', 'Õ' => 'o',
		'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
		'ç' => 'c', 'Ç' => 'c', 'ñ' => 'n', 'Ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'y',
		'ł' => 'l', 'Ł' => 'l', 'ø' => 'o', 'Ø' => 'o', 'æ' => 'ae', 'Æ' => 'ae',
		'ß' => 'ss', 'œ' => 'oe', 'Œ' => 'oe',
	);

	/**
	 * Normalise text using exactly the same path for indexing and querying.
	 */
	public static function normalize( $text ) {
		$result = self::normalize_with_map( (string) $text );
		return $result[0];
	}

	/**
	 * Return normalised text and a normalised-character -> raw-character map.
	 *
	 * @return array{0:string,1:array<int,int>}
	 */
	public static function normalize_with_map( $text ) {
		$text       = (string) $text;
		$characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$normal     = array();
		$offsets    = array();
		$last_space = true;

		if ( false === $characters ) {
			return array( '', array() );
		}

		foreach ( $characters as $raw_offset => $character ) {
			$mapped = isset( self::$map[ $character ] ) ? self::$map[ $character ] : mb_strtolower( $character, 'UTF-8' );
			$parts  = preg_split( '//u', $mapped, -1, PREG_SPLIT_NO_EMPTY );

			if ( false === $parts ) {
				continue;
			}

			foreach ( $parts as $part ) {
				if ( preg_match( '/^[\p{L}\p{N}]$/u', $part ) ) {
					$normal[]  = $part;
					$offsets[] = (int) $raw_offset;
					$last_space = false;
				} elseif ( ! $last_space && ! empty( $normal ) ) {
					$normal[]  = ' ';
					$offsets[] = (int) $raw_offset;
					$last_space = true;
				}
			}
		}

		if ( $last_space && ! empty( $normal ) ) {
			array_pop( $normal );
			array_pop( $offsets );
		}

		return array( implode( '', $normal ), $offsets );
	}

	/**
	 * Tokenise text. Hyphenated compounds are indexed as parts and joined.
	 *
	 * @return array<int,array{token:string,position:int}>
	 */
	public static function tokenize( $text, $limit = 5000 ) {
		$text     = (string) $text;
		$limit    = max( 1, (int) $limit );
		$tokens   = array();
		$compounds = array();
		$position = 0;
		$words    = preg_split( '/[^\p{L}\p{N}\-–]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $words ) {
			return array();
		}

		foreach ( $words as $word ) {
			$parts = preg_split( '/[\-–]+/u', $word, -1, PREG_SPLIT_NO_EMPTY );
			if ( false === $parts ) {
				continue;
			}

			$joined = '';
			foreach ( $parts as $part ) {
				$token = self::normalize( $part );
				if ( '' === $token || mb_strlen( $token, 'UTF-8' ) > 64 ) {
					continue;
				}
				$tokens[] = array( 'token' => $token, 'position' => $position++ );
				$joined  .= $token;
				if ( count( $tokens ) >= $limit ) {
					return $tokens;
				}
			}

			if ( count( $parts ) > 1 && '' !== $joined && mb_strlen( $joined, 'UTF-8' ) <= 64 ) {
				$compounds[] = $joined;
			}
		}
		foreach ( $compounds as $compound ) {
			if ( count( $tokens ) >= $limit ) {
				break;
			}
			$tokens[] = array( 'token' => $compound, 'position' => $position++ );
		}

		return $tokens;
	}
}
