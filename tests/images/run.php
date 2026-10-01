<?php
$meta = [];
$mime = [1 => 'image/jpeg', 2 => 'image/png', 3 => 'image/gif', 4 => 'image/webp', 5 => 'image/avif'];
$support = true;
function get_post_mime_type($id) { global $mime; return $mime[$id] ?? 'image/jpeg'; }
function update_post_meta($id, $key, $value) { global $meta; $meta[$id][$key] = $value; }
function get_post_meta($id, $key, $single) { global $meta; return $meta[$id][$key] ?? ''; }
function wp_image_editor_supports($args) { global $support; return $support; }
function get_attached_file($id) { return '/uploads/' . $id . '.jpg'; }
function image_downsize($id, $size) { return [$size, 100, 100, $size !== 'full']; }
require __DIR__ . '/../../app/UploadImages.php';
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
use App\UploadImages as Images;
foreach (range(1, 5) as $id) { Images::mark($id); }
check(Images::threshold(2560, [], '/uploads/1.jpg', 1) === false, 'new JPEG cap');
check(Images::threshold(2560, [], '/uploads/2.png', 2) === false, 'new PNG cap');
check(Images::threshold(2560, [], '/uploads/old.jpg', 99) === 2560, 'old attachments untouched');
check(Images::format([], '/uploads/1.jpg', 'image/jpeg') === ['image/jpeg' => 'image/avif'], 'JPEG conversion');
check(Images::format([], '/uploads/2.png', 'image/png') === ['image/png' => 'image/avif'], 'PNG conversion');
check(Images::format([], '/uploads/old.jpg', 'image/jpeg') === [], 'no old conversion');
$support = false;
check(Images::format([], '/uploads/1.jpg', 'image/jpeg') === [], 'unsupported host fallback');
$support = true;
$old = ['thumbnail' => ['width' => 150]];
foreach ([3, 4, 99] as $id) { check(Images::sizes($old, [], $id) === $old, 'excluded upload sizes'); }
check(array_keys(Images::sizes($old, [], 1)) === ['alps-small'], 'one derivative plus full');
check(Images::sizes($old, [], 5)['alps-small']['crop'] === false, 'AVIF aspect ratio');
// WordPress 6.x+ saves sub-sizes without a file name.
check(Images::format([], null, 'image/jpeg') === ['image/jpeg' => 'image/avif'], 'small size converts without a file name');
Images::smallDone(['sizes' => ['alps-small' => ['file' => 'x.avif']]], 5);
check(Images::format([], null, 'image/jpeg') === [], 'no conversion once the small size is saved');
check(Images::downsize(false, 1, 'featured__hero--xl')[0] === 'full', 'legacy large alias');
check(Images::downsize(false, 1, 'horiz__4x3--s')[0] === 'alps-small', 'legacy small alias');
check(Images::downsize(false, 1, 'medium')[0] === 'alps-small', 'editor alias');
check(Images::downsize(false, 1, 'alps-small') === false, 'no alias recursion');
check(Images::downsize(false, 99, 'medium') === false, 'legacy attachment untouched');
check(Images::downsize(false, 1, [300, 200]) === false, 'array dimensions preserved');
check(Images::downsize(['plugin'], 1, 'medium') === ['plugin'], 'other plugin result preserved');
echo "Image policy: 20 checks passed.\n";

function is_wp_error($value) { return $value instanceof Exception; }
function wp_get_image_editor($file) { global $editor; return $editor; }
function wp_unique_filename($dir, $name) { return $name; }
function update_attached_file($id, $file) { $GLOBALS['attached'] = $file; }
function _wp_relative_upload_path($file) { return basename($file); }
function wp_update_post($post) { $GLOBALS['updated_post'] = $post; }
class TestEditor {
    public $fail = false;
    public $resized = false;
    public function maybe_exif_rotate() { return true; }
    public function resize($w, $h, $crop) { $this->resized = [$w, $h, $crop]; return true; }
    public function generate_filename($suffix, $dir, $ext) { return '/uploads/test-display.' . $ext; }
    public function save($path, $mime) {
        if ($this->fail) { return new Exception('failed'); }
        return ['path' => $path, 'width' => 1920, 'height' => 1280, 'filesize' => 123, 'mime-type' => $mime];
    }
}
$editor = new TestEditor();
$input = ['file' => '1.jpg', 'width' => 3000, 'height' => 2000, 'sizes' => ['alps-small' => ['file' => 'small.avif']]];
$output = Images::finish($input, 1);
check($output['width'] === 1920 && $editor->resized === [1920, 1920, false], 'full resize');
check($output['original_image'] === '1.jpg', 'source preserved');
check($output['sizes'] === $input['sizes'], 'small metadata preserved');
check($GLOBALS['updated_post']['post_mime_type'] === 'image/avif', 'attachment MIME updated');
check($output['image_meta']['orientation'] === 1, 'orientation normalized');
$editor->fail = true;
check(Images::finish($input, 1) === $input, 'failed save keeps metadata');
check(Images::finish($input, 99) === $input, 'old metadata unchanged');
$editor = new Exception('cannot decode');
check(Images::finish($input, 1) === $input, 'decode failure keeps original');
$editor = new TestEditor();
$support = false;
$small = ['file' => '1.jpg', 'width' => 400, 'height' => 300];
check(Images::finish($small, 1) === $small, 'small fallback stays original');
// WordPress already saved a converted copy that is small enough: it becomes the display image.
$support = true;
$GLOBALS['file_mime'] = 'image/avif';
$GLOBALS['updated_post'] = null;
$copy = ['file' => '1.avif', 'width' => 500, 'height' => 300, 'original_image' => 'source.jpg'];
check(Images::finish($copy, 1) === $copy && $GLOBALS['updated_post']['post_mime_type'] === 'image/avif', 'small converted copy reused, not re-encoded');
echo "Finalization: 10 checks passed.\n";
function wp_get_image_mime($file) { return $GLOBALS['file_mime'] ?? 'image/jpeg'; }
