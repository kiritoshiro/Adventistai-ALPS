<?php
/**
 * App\HeaderTone: title colour and dimming over the blurred header image.
 *
 *   php tests/images/tone.php
 */
$meta = [];
$writes = 0;
$root = sys_get_temp_dir() . '/alps-tone-' . bin2hex(random_bytes(6));
mkdir($root);
mkdir($root . '/assets/css', 0777, true);
function get_post_meta($id, $key, $single) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['writes']++; $GLOBALS['meta'][$id][$key] = $value; }
function wp_upload_dir($time = null, $create = true) { return ['basedir' => $GLOBALS['root'], 'baseurl' => 'https://example.test/uploads']; }
function trailingslashit($path) { return rtrim($path, '/\\') . '/'; }
function get_attached_file($id) { return $GLOBALS['root'] . '/attached-' . $id . '.jpg'; }
function get_template_directory() { return $GLOBALS['root']; }
require __DIR__ . '/../../app/ImageDelivery.php';
require __DIR__ . '/../../app/HeaderTone.php';
use App\HeaderTone as Tone;

$checks = 0;
function check($condition, $message) { global $checks; ++$checks; if (!$condition) { throw new RuntimeException($message); } }
function fill(array $rgb, int $count = 40): array { return array_fill(0, $count, $rgb); }
/** Share of pixels at or above the target once the chosen dimming is applied. */
function passing(array $pixels, array $tone): float
{
    $ok = 0;
    foreach ($pixels as $pixel) {
        $ok += Tone::contrast(Tone::dimmed($pixel, $tone['ink'], $tone['alpha']), $tone['ink']) >= Tone::TARGET ? 1 : 0;
    }
    return $ok / count($pixels);
}

try {
    // Reference values from WCAG: white on black 21:1, #777 on white about 4.48:1.
    check(abs(Tone::contrast([0, 0, 0], 'light') - 21) < 0.01, 'white on black is 21:1');
    check(abs(Tone::luminance([119, 119, 119]) - 0.1845) < 0.001, 'luminance of #777');

    $dark = Tone::fromPixels(fill([10, 20, 30]));
    check($dark === ['ink' => 'light', 'alpha' => Tone::MIN_ALPHA], 'a dark image: white text, light dimming');
    $white = Tone::fromPixels(fill([255, 255, 255]));
    check($white['ink'] === 'dark' && $white['alpha'] === Tone::MIN_ALPHA, 'a white image: dark text');
    $grey = Tone::fromPixels(fill([128, 128, 128]));
    check($grey['ink'] === 'light' && $grey['alpha'] < 0.3, 'mid grey: white text with some dimming, ' . json_encode($grey));
    $pale = Tone::fromPixels(fill([215, 210, 200]));
    check($pale['ink'] === 'dark', 'a pale image: dark text needs clearly less dimming, ' . json_encode($pale));

    // The brightest tenth may stay below the target (a sun in the sky), not more.
    $sun = array_merge(fill([30, 30, 40], 92), fill([255, 250, 230], 8));
    check(Tone::fromPixels($sun) === ['ink' => 'light', 'alpha' => Tone::MIN_ALPHA], 'a small bright patch is ignored');
    $sky = array_merge(fill([30, 30, 40], 70), fill([255, 250, 230], 30));
    $skyTone = Tone::fromPixels($sky);
    check($skyTone['alpha'] > 0.5 && passing($sky, $skyTone) >= 0.9, 'a large bright area gets strong dimming, ' . json_encode($skyTone));

    // Any image: at least 90% of the sample reaches 4.5:1, within the limits.
    mt_srand(7);
    for ($i = 0; $i < 300; $i++) {
        $base = [mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)];
        $pixels = [];
        for ($p = 0; $p < 60; $p++) {
            $spread = mt_rand(0, 120);
            $pixels[] = array_map(static fn ($c) => max(0, min(255, $c + mt_rand(-$spread, $spread))), $base);
        }
        $tone = Tone::fromPixels($pixels);
        check($tone['alpha'] >= Tone::MIN_ALPHA && $tone['alpha'] <= Tone::MAX_ALPHA, 'alpha within limits');
        if ($tone['alpha'] < Tone::MAX_ALPHA) {
            check(passing($pixels, $tone) >= 1 - Tone::OUTLIERS, 'random image ' . $i . ' reaches the target: ' . json_encode($tone));
        }
    }

    check(Tone::css(['ink' => 'light', 'alpha' => 0.12]) === ['ink' => '#fff', 'scrim' => 'rgba(0,0,0,0.12)'], 'light CSS');
    check(Tone::css(['ink' => 'dark', 'alpha' => 0.5]) === ['ink' => '#111', 'scrim' => 'rgba(255,255,255,0.5)'], 'dark CSS');

    if (function_exists('imagecreatetruecolor')) {
        // Left half black, right half white: sampled to 24 x 12, both halves kept.
        $image = imagecreatetruecolor(400, 200);
        imagefilledrectangle($image, 200, 0, 399, 199, imagecolorallocate($image, 255, 255, 255));
        $pixels = Tone::pixelsFromGd($image);
        check(count($pixels) === 24 * 12, 'sampled to 24 x 12: ' . count($pixels));
        check($pixels[0] === [0, 0, 0] && end($pixels) === [255, 255, 255], 'sample keeps the colours');

        // An attachment: analysed from its JPEG fallback once, then from the cache.
        $photo = imagecreatetruecolor(300, 200);
        imagefill($photo, 0, 0, imagecolorallocate($photo, 240, 240, 235));
        imagejpeg($photo, $root . '/photo-fallback.jpg', 90);
        $meta[5][App\ImageDelivery::OUTPUTS] = ['avif_full' => 'photo.avif', 'jpeg' => 'photo-fallback.jpg'];
        check(Tone::forAttachment(5) === ['ink' => '#111', 'scrim' => 'rgba(255,255,255,0.12)'], 'pale photo: dark title');
        check($writes === 1 && str_starts_with($meta[5][Tone::META]['sig'], '1:'), 'result stored on the attachment');
        Tone::forAttachment(5);
        check($writes === 1, 'second view uses the stored result');
        imagefill($photo, 0, 0, imagecolorallocate($photo, 15, 15, 20));
        imagejpeg($photo, $root . '/photo-fallback.jpg', 70);
        clearstatcache();
        check(Tone::forAttachment(5)['ink'] === '#fff' && $writes === 2, 'a replaced file is analysed again');

        // Unconverted image: the attached file.
        imagejpeg($photo, $root . '/attached-6.jpg', 90);
        check(Tone::forAttachment(6)['ink'] === '#fff', 'unconverted image read from the attached file');
    } else {
        echo "GD not available: sampling checks skipped\n";
    }

    // Missing or unreadable files: white text on strong dimming.
    check(Tone::forAttachment(7) === ['ink' => '#fff', 'scrim' => 'rgba(0,0,0,0.55)'], 'missing file: safe default');
    $meta[8][App\ImageDelivery::OUTPUTS] = ['jpeg' => '../outside.jpg'];
    check(Tone::forAttachment(8)['scrim'] === 'rgba(0,0,0,0.55)', 'paths outside uploads are ignored');
    file_put_contents($root . '/broken.jpg', 'not an image');
    $meta[9][App\ImageDelivery::OUTPUTS] = ['jpeg' => 'broken.jpg'];
    check(Tone::forAttachment(9)['scrim'] === 'rgba(0,0,0,0.55)' && isset($meta[9][Tone::META]), 'unreadable file: default, remembered');

    // The CSS is embedded once per page, minified when the copy exists.
    file_put_contents($root . '/assets/css/page-hero.css', '.readable{}');
    file_put_contents($root . '/assets/css/page-hero.min.css', '.alps-hero{a:b}');
    check(Tone::stylesheet() === '<style id="alps-page-hero-css">.alps-hero{a:b}</style>', 'minified copy embedded');
    check(Tone::stylesheet() === '', 'only once per page');

    // The shipped CSS and its minified copy exist and stay small.
    $shipped = __DIR__ . '/../../assets/css/page-hero.min.css';
    check(is_readable($shipped) && filesize($shipped) < 2100 && str_contains((string) file_get_contents($shipped), '--alps-hero-scrim'), 'shipped minified CSS');
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach (glob($root . '/{,*/,*/*/}*', GLOB_BRACE) ?: [] as $file) {
        if (is_file($file)) { unlink($file); }
    }
    @rmdir($root . '/assets/css');
    @rmdir($root . '/assets');
    @rmdir($root);
}
echo "Header tone: {$checks} checks passed\n";
