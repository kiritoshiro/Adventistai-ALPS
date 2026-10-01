<?php
/**
 * Real-WordPress checks for new uploads (two AVIF + one JPEG) and their delivery.
 * DESTRUCTIVE to its own fixtures only; run on a throwaway local site with this theme active:
 *
 *   ALPS_TESTS=1 wp eval-file wp-content/themes/<theme>/tests/images/wordpress.php
 *
 * Needs an AVIF-capable image editor. When the WP Cleanup plugin is active, also checks
 * that it treats new uploads as already converted and finds no data problems.
 */

use App\ImageDelivery;
use App\UploadImages;

if (!defined('ABSPATH')) {
    exit(1);
}
$host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (getenv('ALPS_TESTS') !== '1' || !preg_match('/^(localhost|127\.0\.0\.1|\[::1\]|.+\.(test|localhost))$/', $host)) {
    WP_CLI::error('Refusing to run: set ALPS_TESTS=1 and use a local site.');
}
if (!class_exists(ImageDelivery::class) || !wp_image_editor_supports(['mime_type' => 'image/avif'])) {
    WP_CLI::error('Needs this theme active and AVIF support.');
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$GLOBALS['alps_f'] = 0;
$GLOBALS['alps_p'] = 0;
function alps_ok($condition, string $message): void
{
    $GLOBALS[$condition ? 'alps_p' : 'alps_f']++;
    WP_CLI::log(($condition ? '  ok   ' : WP_CLI::colorize('  %rFAIL%n ')) . $message);
}

function alps_upload(string $name, int $w, int $h, string $type, bool $alpha = false): int
{
    $tmp = wp_tempnam($name);
    $im = imagecreatetruecolor($w, $h);
    if ($alpha) {
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), intdiv($w, 2), intdiv($h, 2), imagecolorallocatealpha($im, 200, 30, 30, 0));
    } else {
        for ($x = 0; $x < $w; $x += 10) {
            imagefilledrectangle($im, $x, 0, $x + 9, $h, imagecolorallocate($im, intdiv($x * 255, $w), 120, 200));
        }
    }
    if ($type === 'png') {
        imagepng($im, $tmp);
    } elseif ($type === 'avif') {
        imageavif($im, $tmp, 60);
    } else {
        imagejpeg($im, $tmp, 90);
    }
    imagedestroy($im);
    $id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0);
    if (is_wp_error($id)) {
        WP_CLI::error($name . ': ' . $id->get_error_message());
    }
    update_post_meta($id, '_alps_image_test', 1);
    return (int) $id;
}

function alps_path(string $relative): string
{
    return trailingslashit(wp_normalize_path(wp_upload_dir()['basedir'])) . $relative;
}

foreach (get_posts(['post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_alps_image_test']) as $old) {
    get_post_type($old) === 'attachment' ? wp_delete_attachment($old, true) : wp_delete_post($old, true);
}

$policy = UploadImages::policy();

WP_CLI::log('Large landscape JPEG');
$big = alps_upload('alps-test-landscape.jpg', 3000, 2000, 'jpg');
$meta = wp_get_attachment_metadata($big);
$out = get_post_meta($big, '_wpcu_image_outputs', true);
alps_ok(get_post_mime_type($big) === 'image/avif' && str_ends_with($meta['file'], '.avif') && $meta['width'] === 1920 && $meta['height'] === 1280, 'main file is a 1920×1280 AVIF');
alps_ok(array_keys($meta['sizes']) === ['alps-small'] && $meta['sizes']['alps-small']['mime-type'] === 'image/avif' && $meta['sizes']['alps-small']['width'] === 768, 'one small AVIF, 768 px');
alps_ok(!empty($meta['original_image']) && is_file(dirname(alps_path($meta['file'])) . '/' . $meta['original_image']), 'the original is kept for recovery');
alps_ok(is_array($out) && $out['by'] === 'alps-theme' && $out['policy'] === $policy, 'the shared output record is written with the policy');
$jpeg = is_array($out) ? alps_path($out['jpeg']) : '';
$info = $jpeg && is_file($jpeg) ? getimagesize($jpeg) : false;
alps_ok($info && $info['mime'] === 'image/jpeg' && $info[0] === 1920 && $out['jpeg_width'] === 1920 && $out['jpeg_height'] === 1280, 'one 1920×1280 JPEG fallback: ' . ($out['jpeg'] ?? '-'));
alps_ok(str_ends_with((string) $out['jpeg'], '-fallback.jpg') && $out['avif_full'] === $meta['file'] && basename((string) $out['avif_small']) === $meta['sizes']['alps-small']['file'], 'record lists the full AVIF, small AVIF and JPEG');
alps_ok(get_post_meta($big, '_alps_two_size_upload', true) && get_post_meta($big, '_alps_original_delete_after', true), 'ALPS marker set and original queued for its seven-day expiry');

$picture = ImageDelivery::picture($big, ['sizes' => '50vw']);
alps_ok(str_contains($picture, '<source type="image/avif"') && str_contains($picture, ' 768w') && str_contains($picture, ' 1920w'), 'picture offers both AVIF widths');
alps_ok((bool) preg_match('~<img[^>]+src="[^"]+-fallback\.jpg"~', $picture) && str_contains($picture, 'width="1920"') && str_contains($picture, 'height="1280"'), 'img falls back to the JPEG, with dimensions');
alps_ok(str_contains($picture, 'alps-image--natural') && str_contains($picture, '--alps-image-w:1920px'), 'without a slot the image keeps its own shape');
$card = ImageDelivery::blockImage($big, 'horiz__16x9');
alps_ok(str_contains($card, 'alps-image--slot') && str_contains($card, 'alps-image--cover'), '3:2 image in a 16:9 card fills it (close shapes)');
$square = ImageDelivery::blockImage($big, 'thumbnail--s');
alps_ok(str_contains($square, 'alps-image--contain'), '3:2 image in a square slot is shown whole');
$round = ImageDelivery::blockImage($big, 'thumbnail--s', ['round' => true]);
alps_ok(str_contains($round, 'alps-image--cover'), 'round thumbnails stay a filled circle');
$header = ImageDelivery::blockImage($big, 'horiz__4x3--s', ['header' => true]);
alps_ok(str_contains($header, 'alps-image--natural') && str_contains($header, 'fetchpriority="high"'), 'header images are natural shape and high priority');
$css = ImageDelivery::backgroundCss('.x', $big);
alps_ok(str_contains($css, 'image-set(') && str_contains($css, 'type("image/avif")') && str_contains($css, '-fallback.jpg') && str_contains($css, '@media (min-width:769px)'), 'background CSS: AVIF via image-set, JPEG first, full AVIF above 768 px');
alps_ok(ImageDelivery::headerFit($big) === ['ratio' => 1.5, 'mode' => 'cover'], 'landscape header takes the image shape');
$social = ImageDelivery::socialImage($big);
alps_ok($social && str_ends_with($social[0], '-fallback.jpg') && $social[1] === 1920, 'Open Graph image is the JPEG');
$wpImage = wp_get_attachment_image($big, 'medium');
alps_ok(str_contains($wpImage, '<picture') && str_contains($wpImage, 'image/avif') && str_contains($wpImage, '-fallback.jpg'), 'wp_get_attachment_image() gets the AVIF + JPEG picture');
$post = wp_insert_post(['post_title' => 'alps image test', 'post_status' => 'publish', 'post_content' => '<!-- wp:image --><figure class="wp-block-image"><img src="' . esc_url(wp_get_attachment_url($big)) . '" class="wp-image-' . $big . '"/></figure><!-- /wp:image -->']);
update_post_meta($post, '_alps_image_test', 1);
$content = apply_filters('the_content', get_post_field('post_content', $post));
alps_ok(substr_count($content, '<picture') === 1 && str_contains($content, 'image/avif'), 'content images get exactly one picture');

WP_CLI::log('Transparent portrait PNG');
$png = alps_upload('alps-test-portrait.png', 1200, 1800, 'png', true);
$pout = get_post_meta($png, '_wpcu_image_outputs', true);
$pjpeg = imagecreatefromjpeg(alps_path($pout['jpeg']));
$corner = imagecolorsforindex($pjpeg, imagecolorat($pjpeg, 2, 2));
alps_ok($corner['red'] > 245 && $corner['green'] > 245 && $corner['blue'] > 245, 'transparent areas are white in the JPEG, not black');
imagedestroy($pjpeg);
alps_ok(ImageDelivery::headerFit($png)['mode'] === 'contain', 'portrait header shows the whole image');
alps_ok(str_contains(ImageDelivery::blockImage($png, 'horiz__16x9'), 'alps-image--contain'), 'portrait image in a 16:9 card is shown whole');

WP_CLI::log('Small JPEG');
$small = alps_upload('alps-test-small.jpg', 500, 300, 'jpg');
$smeta = wp_get_attachment_metadata($small);
$sout = get_post_meta($small, '_wpcu_image_outputs', true);
alps_ok(get_post_mime_type($small) === 'image/avif' && $smeta['width'] === 500 && empty($smeta['sizes']), 'small upload: one 500 px AVIF, no small size');
alps_ok($sout['avif_small'] === '' && $sout['jpeg_width'] === 500, 'record has no small AVIF and a 500 px JPEG');
$spic = ImageDelivery::picture($small);
alps_ok(str_contains($spic, ' 500w') && !str_contains($spic, ' 768w'), 'picture offers only the one AVIF');

WP_CLI::log('AVIF upload');
if (function_exists('imageavif')) {
    $avif = alps_upload('alps-test-upload.avif', 900, 600, 'avif');
    $aout = get_post_meta($avif, '_wpcu_image_outputs', true);
    alps_ok(is_array($aout) && $aout['jpeg'] && getimagesize(alps_path($aout['jpeg']))['mime'] === 'image/jpeg', 'an uploaded AVIF also gets a JPEG fallback');
}

WP_CLI::log('Regeneration');
$before = get_post_meta($big, '_wpcu_image_outputs', true);
$files = glob(dirname(alps_path($meta['file'])) . '/alps-test-landscape*');
$regenerated = wp_generate_attachment_metadata($big, get_attached_file($big));
wp_update_attachment_metadata($big, $regenerated);
$after = get_post_meta($big, '_wpcu_image_outputs', true);
$filesAfter = glob(dirname(alps_path($meta['file'])) . '/alps-test-landscape*');
alps_ok($after['jpeg'] === $before['jpeg'] && count(array_filter($filesAfter, static fn ($f) => str_contains($f, '-fallback'))) === 1, 'regeneration keeps the one JPEG');
alps_ok(basename((string) $after['avif_small']) === wp_get_attachment_metadata($big)['sizes']['alps-small']['file'], 'record follows the regenerated small AVIF');

if (class_exists('\WPCleanup\Media_Inventory')) {
    WP_CLI::log('WP Cleanup');
    \WPCleanup\Media_Inventory::flush();
    foreach (['landscape' => $big, 'portrait PNG' => $png, 'small' => $small] as $label => $id) {
        $inv = \WPCleanup\Media_Inventory::attachment($id);
        alps_ok($inv && $inv['compliant'], "WP Cleanup sees the $label upload as already converted");
        $issues = \WPCleanup\Media_Integrity::check($id)['issues'];
        alps_ok(!$issues, "WP Cleanup's data check finds no problem ($label)" . ($issues ? ': ' . implode(' | ', wp_list_pluck($issues, 'message')) : ''));
    }
}

WP_CLI::log('Deletion');
$jpegPath = alps_path(get_post_meta($big, '_wpcu_image_outputs', true)['jpeg']);
wp_delete_attachment($big, true);
alps_ok(!is_file($jpegPath), 'deleting the attachment removes its JPEG fallback');

foreach (get_posts(['post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_alps_image_test']) as $old) {
    get_post_type($old) === 'attachment' ? wp_delete_attachment($old, true) : wp_delete_post($old, true);
}
WP_CLI::log('');
if ($GLOBALS['alps_f']) {
    WP_CLI::error(sprintf('%d failed, %d passed.', $GLOBALS['alps_f'], $GLOBALS['alps_p']));
}
WP_CLI::success(sprintf('All %d upload and delivery checks passed.', $GLOBALS['alps_p']));
