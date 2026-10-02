<?php
namespace App\Core;

/**
 * URLs of the bundled ALPS pattern-library stylesheets and scripts.
 */
class ALPSVersions
{
    const LOCAL_PATH = '/app/local/alps/';

    const THEME_KEYS = array(
        'bluejay',
        'campfire',
        'cave',
        'denim',
        'earth',
        'emperor',
        'forest',
        'grapevine',
        'iris',
        'lily',
        'ming',
        'night',
        'scarlett',
        'treefrog',
        'velvet',
        'winter',
        'nad-amethyst',
        'nad-branch',
        'nad-denim',
        'nad-miracle',
        'nad-nile',
        'nad-spark',
        'nad-vine'
    );

    public static function get()
    {
        return self::getLocalVersion()[0];
    }

    public static function getLocalVersion() {
        $uri = get_template_directory_uri();
        $themes = [];

        foreach (self::THEME_KEYS as $key) {
            $themes[$key] = $uri . self::LOCAL_PATH . 'css/main-' . $key . '.css';
        }

        return [
            [
                'version' => 'alps_local_styles_version',
                'scripts' => [
                    'main' => $uri . self::LOCAL_PATH . 'js/script.min.js',
                    'head' => $uri . self::LOCAL_PATH . 'js/head-script.min.js',
                ],
                'styles' => [
                    'main' => $uri . self::LOCAL_PATH . 'css/main.css',
                    'themes' => $themes,
                ],
            ]
        ];
    }
}
