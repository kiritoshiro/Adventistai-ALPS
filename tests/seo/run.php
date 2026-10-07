<?php
// Checks that posts with an AVIF image still get an og:image under Yoast:
// the theme hands Yoast the JPEG fallback. WordPress and Yoast are stubbed.

$uploads = sys_get_temp_dir() . '/alps-seo-' . getmypid();
@mkdir($uploads);
foreach (['photo.avif', 'photo-small.avif', 'photo-fallback.jpg', 'social.avif', 'social-fallback.jpg', 'plain.avif'] as $file) {
    file_put_contents("$uploads/$file", 'x');
}

$meta = [];
$thumbnails = [];
$singular = true;
$queried = 10;
$fallbackEnabled = true;

function get_post_meta($id, $key, $single) { global $meta; return $meta[$id][$key] ?? ''; }
function get_post_thumbnail_id($id) { global $thumbnails; return $thumbnails[$id] ?? 0; }
function get_queried_object_id() { global $queried; return $queried; }
function is_singular() { global $singular; return $singular; }
function is_admin() { return false; }
function apply_filters($hook, $value) { global $fallbackEnabled; return $hook === 'adventistai_seo_fallback_enabled' ? $fallbackEnabled : $value; }
function wp_upload_dir($time = null, $create = true) { global $uploads; return ['basedir' => $uploads, 'baseurl' => 'https://example.test/uploads']; }
function trailingslashit($value) { return rtrim($value, '/\\') . '/'; }
function wp_get_attachment_metadata($id) { return ['width' => 1600, 'height' => 900]; }
function wp_get_attachment_image_src($id, $size) { global $sources; return $sources[$id] ?? false; }
function add_action() {}
function add_filter() {}

require __DIR__ . '/../../app/ImageDelivery.php';
require __DIR__ . '/../../app/SeoDefaults.php';

// The parts of Yoast\WP\SEO\Values\Open_Graph\Images that the theme uses.
class Images
{
    public array $images = [];

    public function has_images() { return !empty($this->images); }

    public function add_image($image) { $this->images[$image['url']] = $image; }
}

function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }

function converted($id, $name)
{
    global $meta;
    $meta[$id][App\ImageDelivery::OUTPUTS] = [
        'avif_full' => "$name.avif", 'avif_full_width' => 1600, 'avif_full_height' => 900,
        'avif_small' => "$name-small.avif", 'avif_small_width' => 768,
        'jpeg' => "$name-fallback.jpg", 'jpeg_width' => 1200, 'jpeg_height' => 675,
    ];
}

function run()
{
    App\ImageDelivery::forget(20);
    App\ImageDelivery::forget(30);
    App\ImageDelivery::forget(40);
    return App\SeoDefaults::addYoastImage(new Images())->images;
}

converted(20, 'photo');
converted(30, 'social');
$sources[40] = ['https://example.test/uploads/plain.avif', 1600, 900];

// Featured image converted to AVIF: Yoast found nothing, the JPEG is added.
$thumbnails[10] = 20;
$images = run();
check(array_keys($images) === ['https://example.test/uploads/photo-fallback.jpg'], 'featured JPEG fallback added');
$image = $images['https://example.test/uploads/photo-fallback.jpg'];
check($image['width'] === 1200 && $image['height'] === 675, 'JPEG dimensions passed on');
check($image['type'] === 'image/jpeg', 'JPEG type passed on');

// An AVIF image chosen in Yoast's Social tab wins over the featured image.
$meta[10]['_yoast_wpseo_opengraph-image-id'] = 30;
check(array_keys(run()) === ['https://example.test/uploads/social-fallback.jpg'], 'Yoast social image JPEG preferred');
unset($meta[10]['_yoast_wpseo_opengraph-image-id']);

// A usable image Yoast already found is left alone.
$yoast = new Images();
$yoast->add_image(['url' => 'https://example.test/uploads/chosen.png']);
check(array_keys(App\SeoDefaults::addYoastImage($yoast)->images) === ['https://example.test/uploads/chosen.png'], 'Yoast image kept');

// An AVIF without a JPEG copy is not offered: Facebook does not read it.
$thumbnails[10] = 40;
check(run() === [], 'AVIF-only image skipped');

// No image, not a single post, or the fallback switched off: nothing added.
$thumbnails[10] = 0;
check(run() === [], 'post without image');
$thumbnails[10] = 20;
$singular = false;
check(run() === [], 'archive pages left to Yoast');
$singular = true;
$fallbackEnabled = false;
check(run() === [], 'adventistai_seo_fallback_enabled respected');
$fallbackEnabled = true;

// Anything other than Yoast's container passes through untouched.
check(App\SeoDefaults::addYoastImage(null) === null, 'unexpected argument passed through');

array_map('unlink', glob("$uploads/*"));
rmdir($uploads);
echo "SEO image checks passed\n";
