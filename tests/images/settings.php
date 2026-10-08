<?php
/**
 * Checks for the image settings (Appearance → ALPS Theme Settings → Images):
 * AVIF quality and sizes from the theme options, WP Cleanup's policy taking
 * over while it is active, and the AVIF quality filter. WordPress is stubbed.
 * Run: php tests/images/settings.php
 */

namespace WPCleanup {
    class Media_Policy
    {
        public static $settings = null;
        public static function settings() { return self::$settings; }
        public static function alps_compatible($s) { return $s['small_name'] === 'alps-small' && $s['full_max'] <= 1920 && $s['small_max'] <= 768; }
    }
}

namespace {
    $GLOBALS['options'] = [];
    $GLOBALS['filters'] = [];
    function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
    function add_filter($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['filters'][] = [$hook, $priority, is_array($callback) ? $callback[1] : $callback]; }
    function add_action() {}
    function __($text, $domain = '') { return $text; }
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
    function esc_html__($text, $domain = '') { return esc_html($text); }

    require __DIR__ . '/../../app/UploadImages.php';
    use App\UploadImages as Images;

    $failures = 0;
    function check($condition, $message)
    {
        global $failures;
        echo ($condition ? 'PASS ' : 'FAIL ') . $message . "\n";
        $failures += $condition ? 0 : 1;
    }
    function settings(array $values)
    {
        $GLOBALS['options'] = [];
        foreach ($values as $key => $value) {
            $GLOBALS['options']['_' . Images::SETTINGS[$key]] = $value;
        }
    }

    Images::register();
    check(in_array(['wp_editor_set_quality', 100, 'quality'], $GLOBALS['filters'], true), 'AVIF quality filter registered');

    $policy = Images::policy();
    check($policy['avif_quality'] === 82 && $policy['full_max'] === 1920 && $policy['small_max'] === 768, 'defaults: AVIF 82, 1920/768 px');

    settings(['avif_quality' => '60', 'full_max' => '1600', 'small_max' => '640']);
    $policy = Images::policy();
    check($policy['avif_quality'] === 60 && $policy['full_max'] === 1600 && $policy['small_max'] === 640, 'theme settings are used');

    settings(['avif_quality' => '', 'full_max' => ' ', 'small_max' => 'abc']);
    $policy = Images::policy();
    check($policy['avif_quality'] === 82 && $policy['full_max'] === 1920 && $policy['small_max'] === 768, 'empty or non-numeric fields keep the defaults');

    settings(['avif_quality' => '5', 'full_max' => '4000', 'small_max' => '900']);
    $policy = Images::policy();
    check($policy['avif_quality'] === 20 && $policy['full_max'] === 1920 && $policy['small_max'] === 768, 'values are clamped to 20–95, 1920 and 768 px');

    settings(['avif_quality' => '99', 'full_max' => '500', 'small_max' => '700']);
    $policy = Images::policy();
    check($policy['avif_quality'] === 95 && $policy['small_max'] === 499, 'quality at most 95; the small size stays below the full one');

    settings(['avif_quality' => '60']);
    check(Images::quality(82, 'image/avif') === 60, 'AVIF saves use the setting');
    check(Images::quality(82, 'image/jpeg') === 82 && Images::quality(86, 'image/webp') === 86, 'other formats keep their quality');
    check(strpos(Images::settingsHtml(), 'AVIF quality 60, full image at most 1920 px, small image at most 768 px') !== false, 'settings text shows the values in use');
    check(strpos(Images::settingsHtml(), 'Changes apply to new uploads') !== false, 'settings text without WP Cleanup');

    // WP Cleanup active with an ALPS policy: its values win, as before.
    \WPCleanup\Media_Policy::$settings = ['full_max' => 1800, 'small_name' => 'alps-small', 'small_max' => 700, 'jpeg_max' => 1920, 'jpeg_quality' => 80, 'avif_quality' => 55, 'jpeg_fallback' => true, 'set_flag' => true];
    $policy = Images::policy();
    check($policy['avif_quality'] === 55 && $policy['full_max'] === 1800 && $policy['small_max'] === 700 && $policy['jpeg_quality'] === 80, 'WP Cleanup policy wins while active');
    check(Images::quality(82, 'image/avif') === 55, 'AVIF quality from WP Cleanup');
    check(strpos(Images::settingsHtml(), 'WP Cleanup plugin is active') !== false, 'settings text says WP Cleanup decides');

    // An older WP Cleanup without the AVIF setting: the theme's AVIF quality stays.
    unset(\WPCleanup\Media_Policy::$settings['avif_quality']);
    check(Images::policy()['avif_quality'] === 60, 'older WP Cleanup: theme AVIF quality kept');

    // A WP Cleanup policy that does not fit ALPS is ignored.
    \WPCleanup\Media_Policy::$settings = ['full_max' => 2560, 'small_name' => 'medium', 'small_max' => 1024, 'avif_quality' => 40];
    check(Images::policy()['avif_quality'] === 60 && Images::cleanupPolicy() === null, 'incompatible WP Cleanup policy ignored');

    echo $failures ? "$failures check(s) failed\n" : "Image settings: all checks passed\n";
    exit($failures ? 1 : 0);
}
