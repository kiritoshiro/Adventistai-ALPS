<?php

/**
 * Private GitHub release updater for the Adventistai ALPS theme.
 *
 * Add a fine-grained, read-only GitHub token to wp-config.php:
 * define( 'ADVENTISTAI_ALPS_GITHUB_TOKEN', 'github_pat_...' );
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Adventistai_Alps_GitHub_Updater
{
    private const REPOSITORY = 'kiritoshiro/Adventistai-ALPS';
    private const ASSET_NAME = 'adventistai.zip';
    private const CACHE_KEY = 'adventistai_alps_github_release';
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /** @var array<string, mixed>|null */
    private static $release = null;

    public static function bootstrap()
    {
        add_filter( 'pre_set_site_transient_update_themes', [ __CLASS__, 'check_for_update' ] );
        add_filter( 'http_request_args', [ __CLASS__, 'authenticate_github_asset_request' ], 10, 2 );
        add_action( 'admin_notices', [ __CLASS__, 'token_notice' ] );
        add_action( 'upgrader_process_complete', [ __CLASS__, 'clear_cache_after_update' ], 10, 2 );
    }

    /**
     * Add this theme to WordPress' normal theme update list.
     *
     * @param object $transient WordPress update transient.
     * @return object
     */
    public static function check_for_update( $transient )
    {
        if ( ! is_object( $transient ) || empty( $transient->checked ) || ! self::token() ) {
            return $transient;
        }

        $theme_slug = get_template();
        $theme      = wp_get_theme( $theme_slug );
        $current    = $theme->get( 'Version' );
        $release    = self::latest_release();

        if ( ! $release || empty( $release['version'] ) || empty( $release['package'] ) ) {
            return $transient;
        }

        if ( version_compare( $release['version'], $current, '>' ) ) {
            $transient->response[ $theme_slug ] = [
                'theme'       => $theme_slug,
                'new_version' => $release['version'],
                'url'         => $release['html_url'],
                'package'     => $release['package'],
                'requires'    => '6.4.1',
                'requires_php'=> '8.1',
            ];
        } else {
            unset( $transient->response[ $theme_slug ] );
            $transient->no_update[ $theme_slug ] = [
                'theme'       => $theme_slug,
                'new_version' => $current,
                'url'         => $release['html_url'],
                'package'     => '',
                'requires'    => '6.4.1',
                'requires_php'=> '8.1',
            ];
        }

        return $transient;
    }

    /**
     * Authenticate only the private release-asset endpoint used as the package URL.
     *
     * @param array<string, mixed> $args HTTP request arguments.
     * @param string               $url  Request URL.
     * @return array<string, mixed>
     */
    public static function authenticate_github_asset_request( $args, $url )
    {
        $path_prefix = '/repos/' . self::REPOSITORY . '/releases/assets/';
        $host        = wp_parse_url( $url, PHP_URL_HOST );
        $path        = wp_parse_url( $url, PHP_URL_PATH );
        $token       = self::token();

        if ( $token && 'api.github.com' === $host && is_string( $path ) && 0 === strpos( $path, $path_prefix ) ) {
            $args['headers']                  = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : [];
            $args['headers']['Authorization'] = 'Bearer ' . $token;
            $args['headers']['Accept']        = 'application/octet-stream';
            $args['headers']['X-GitHub-Api-Version'] = '2022-11-28';
        }

        return $args;
    }

    public static function token_notice()
    {
        if ( ! current_user_can( 'update_themes' ) || self::token() ) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>Adventistai ALPS tema:</strong> privatūs GitHub atnaujinimai nebus tikrinami, kol <code>wp-config.php</code> faile nenustatytas <code>ADVENTISTAI_ALPS_GITHUB_TOKEN</code>.</p></div>';
    }

    /**
     * Clear the cached release after any theme update.
     *
     * @param object              $upgrader WordPress upgrader instance.
     * @param array<string,mixed> $options  Upgrade details.
     */
    public static function clear_cache_after_update( $upgrader, $options )
    {
        if ( isset( $options['type'] ) && 'theme' === $options['type'] ) {
            delete_site_transient( self::CACHE_KEY );
            self::$release = null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function latest_release()
    {
        if ( null !== self::$release ) {
            return self::$release ?: null;
        }

        $cached = get_site_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            self::$release = $cached;
            return self::$release;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization'        => 'Bearer ' . self::token(),
                    'Accept'               => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            self::$release = [];
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['tag_name'] ) || empty( $data['assets'] ) ) {
            self::$release = [];
            return null;
        }

        $package = '';
        foreach ( $data['assets'] as $asset ) {
            if ( isset( $asset['name'], $asset['url'] ) && self::ASSET_NAME === $asset['name'] ) {
                $package = esc_url_raw( $asset['url'] );
                break;
            }
        }

        if ( ! $package ) {
            self::$release = [];
            return null;
        }

        self::$release = [
            'version'  => ltrim( (string) $data['tag_name'], "vV" ),
            'html_url' => isset( $data['html_url'] ) ? esc_url_raw( $data['html_url'] ) : 'https://github.com/' . self::REPOSITORY,
            'package'  => $package,
        ];

        set_site_transient( self::CACHE_KEY, self::$release, self::CACHE_TTL );

        return self::$release;
    }

    private static function token()
    {
        if ( defined( 'ADVENTISTAI_ALPS_GITHUB_TOKEN' ) ) {
            return trim( (string) ADVENTISTAI_ALPS_GITHUB_TOKEN );
        }

        // Backwards compatibility with the token constant used by the previous theme.
        if ( defined( 'ADVENTISTAI_THEME_GITHUB_TOKEN' ) ) {
            return trim( (string) ADVENTISTAI_THEME_GITHUB_TOKEN );
        }

        return '';
    }
}
