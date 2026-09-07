<?php

/**
 * Admin release selector / rollback tool for the Adventistai ALPS theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Adventistai_Alps_Release_Manager
{
    private const REPOSITORY = 'kiritoshiro/Adventistai-ALPS';
    private const ASSET_NAME = 'adventistai.zip';
    private const CACHE_KEY  = 'adventistai_alps_release_history';
    private const CACHE_TTL  = HOUR_IN_SECONDS;

    public static function bootstrap()
    {
        add_action( 'admin_notices', [ __CLASS__, 'render_release_selector' ] );
        add_action( 'admin_post_adventistai_alps_install_release', [ __CLASS__, 'install_selected_release' ] );
    }

    public static function render_release_selector()
    {
        if ( ! current_user_can( 'update_themes' ) || ! self::token() ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->id, [ 'update-core', 'themes' ], true ) ) {
            return;
        }

        $theme   = wp_get_theme( get_template() );
        $current = $theme->get( 'Version' );
        $releases = self::releases();

        if ( empty( $releases ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status set by this class after the nonce-protected install action.
        $status = isset( $_GET['adventistai_release_status'] ) ? sanitize_key( wp_unslash( $_GET['adventistai_release_status'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only version label; output is escaped below.
        $version = isset( $_GET['adventistai_release_version'] ) ? sanitize_text_field( wp_unslash( $_GET['adventistai_release_version'] ) ) : '';

        if ( 'success' === $status && $version ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Adventistai tema:</strong> įdiegta versija ' . esc_html( $version ) . '.</p></div>';
        } elseif ( 'error' === $status ) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Adventistai tema:</strong> pasirinktos versijos įdiegti nepavyko.</p></div>';
        }

        echo '<div class="notice notice-info" style="padding:14px 16px;">';
        echo '<p style="margin-top:0;"><strong>Adventistai temos versijos / grąžinimas</strong></p>';
        echo '<p>Dabartinė versija: <strong>' . esc_html( $current ) . '</strong>. Galite pasirinkti naujesnę arba ankstesnę GitHub leidimo versiją. Ankstesnės versijos pasirinkimas veikia kaip rollback.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        wp_nonce_field( 'adventistai_alps_install_release' );
        echo '<input type="hidden" name="action" value="adventistai_alps_install_release">';
        echo '<label for="adventistai-release-version" class="screen-reader-text">Pasirinkite versiją</label>';
        echo '<select id="adventistai-release-version" name="version">';

        foreach ( $releases as $release ) {
            $label = $release['version'];
            if ( version_compare( $release['version'], $current, '==' ) ) {
                $label .= ' (dabartinė)';
            } elseif ( version_compare( $release['version'], $current, '<' ) ) {
                $label .= ' (grąžinti)';
            } else {
                $label .= ' (atnaujinti)';
            }

            echo '<option value="' . esc_attr( $release['version'] ) . '">' . esc_html( $label ) . '</option>';
        }

        echo '</select>';
        submit_button( 'Įdiegti pasirinktą versiją', 'secondary', 'submit', false, [ 'onclick' => "return confirm('Bus pakeisti dabartinės temos failai pasirinkta versija. Tęsti?');" ] );
        echo '</form>';
        echo '<p style="margin-bottom:0;"><small>Pastaba: tai pakeičia temos failus, bet nekeičia WordPress turinio ar duomenų bazės. Jei naujesnė versija būtų pakeitusi duomenų bazės struktūrą, vien temos rollback to neatšauktų.</small></p>';
        echo '</div>';
    }

    public static function install_selected_release()
    {
        if ( ! current_user_can( 'update_themes' ) ) {
            wp_die( 'Neturite teisės atnaujinti temų.' );
        }

        check_admin_referer( 'adventistai_alps_install_release' );

        $version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
        if ( ! $version || ! self::token() ) {
            self::redirect( 'error', $version );
        }

        $release = self::find_release( $version );
        if ( ! $release || empty( $release['package'] ) ) {
            self::redirect( 'error', $version );
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader( $skin );
        $result = $upgrader->install(
            $release['package'],
            [
                'overwrite_package'  => true,
                'clear_update_cache' => true,
            ]
        );

        delete_site_transient( self::CACHE_KEY );
        delete_site_transient( 'adventistai_alps_github_release' );
        wp_clean_themes_cache( true );

        if ( true !== $result ) {
            self::redirect( 'error', $version );
        }

        self::redirect( 'success', $version );
    }

    private static function redirect( $status, $version )
    {
        $url = add_query_arg(
            [
                'adventistai_release_status'  => $status,
                'adventistai_release_version' => $version,
            ],
            admin_url( 'update-core.php' )
        );

        wp_safe_redirect( $url );
        exit;
    }

    private static function find_release( $version )
    {
        foreach ( self::releases( true ) as $release ) {
            if ( hash_equals( (string) $release['version'], (string) $version ) ) {
                return $release;
            }
        }

        return null;
    }

    /**
     * Return recent non-draft GitHub releases that contain adventistai.zip.
     *
     * @return array<int,array<string,string>>
     */
    private static function releases( $force = false )
    {
        if ( ! $force ) {
            $cached = get_site_transient( self::CACHE_KEY );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPOSITORY . '/releases?per_page=15',
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
            return [];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) ) {
            return [];
        }

        $releases = [];
        foreach ( $data as $item ) {
            if ( ! is_array( $item ) || ! empty( $item['draft'] ) || empty( $item['tag_name'] ) || empty( $item['assets'] ) ) {
                continue;
            }

            $package = '';
            foreach ( $item['assets'] as $asset ) {
                if ( isset( $asset['name'], $asset['url'] ) && self::ASSET_NAME === $asset['name'] ) {
                    $package = esc_url_raw( $asset['url'] );
                    break;
                }
            }

            if ( ! $package ) {
                continue;
            }

            $releases[] = [
                'version'  => ltrim( (string) $item['tag_name'], "vV" ),
                'package'  => $package,
                'html_url' => isset( $item['html_url'] ) ? esc_url_raw( $item['html_url'] ) : '',
            ];
        }

        usort( $releases, static function ( $a, $b ) {
            return version_compare( $b['version'], $a['version'] );
        } );

        set_site_transient( self::CACHE_KEY, $releases, self::CACHE_TTL );
        return $releases;
    }

    private static function token()
    {
        if ( defined( 'ADVENTISTAI_ALPS_GITHUB_TOKEN' ) ) {
            return trim( (string) ADVENTISTAI_ALPS_GITHUB_TOKEN );
        }

        if ( defined( 'ADVENTISTAI_THEME_GITHUB_TOKEN' ) ) {
            return trim( (string) ADVENTISTAI_THEME_GITHUB_TOKEN );
        }

        return '';
    }
}
