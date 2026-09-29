<?php
require __DIR__ . '/../../app/OriginalImageRetention.php';
use App\OriginalImageRetention as Retention;
const WEEK_IN_SECONDS = 604800;
const DAY_IN_SECONDS = 86400;
$root = sys_get_temp_dir() . '/alps-retention-' . bin2hex(random_bytes(6));
mkdir($root);
$meta = [];
$data = [];
$shared = false;
$blocked = false;
$checks = 0;
function check($condition, $message) { global $checks; ++$checks; if (!$condition) { throw new RuntimeException($message); } }
function get_post_meta($id, $key, $single) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; }
function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$id][$key]); }
function wp_get_attachment_metadata($id) { return $GLOBALS['data'][$id]; }
function wp_update_attachment_metadata($id, $value) { $GLOBALS['data'][$id] = Retention::track($value, $id); }
function wp_get_upload_dir() { return ['basedir' => $GLOBALS['root']]; }
function get_attached_file($id, $unfiltered = false) { return $GLOBALS['root'] . '/display.png'; }
function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
function wp_getimagesize($file) { return getimagesize($file); }
function get_posts($args) {
    if ($args['meta_key'] === '_wp_attached_file') { return $GLOBALS['shared'] ? [2] : []; }
    return [1];
}
function wp_delete_file_from_directory($file, $dir) {
    if (!$GLOBALS['blocked'] && dirname(realpath($file)) === realpath($dir)) { unlink($file); }
}
function resetFixture() {
    global $meta, $data, $root, $shared, $blocked;
    $shared = $blocked = false;
    $meta = [1 => ['_alps_two_size_upload' => 1]];
    $data = [1 => ['file' => 'display.png', 'width' => 1920, 'height' => 1280, 'original_image' => 'source.png', 'sizes' => ['alps-small' => ['file' => 'small.png']]]];
    $png = hex2bin('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000b49444154789c636000020000050001a5f645400000000049454e44ae426082');
    foreach (['source.png', 'display.png', 'small.png'] as $file) { file_put_contents($root . '/' . $file, $png); }
    Retention::track($data[1], 1);
}
function expireFixture() { $GLOBALS['meta'][1]['_alps_original_delete_after'] = time() - 1; }
try {
    resetFixture();
    $due = $meta[1]['_alps_original_delete_after'];
    check(abs($due - time() - WEEK_IN_SECONDS) < 2, 'seven day grace');
    Retention::track($data[1], 1);
    check($due === $meta[1]['_alps_original_delete_after'], 'metadata update preserves deadline');
    check(!Retention::deleteExpired(1) && file_exists($root . '/source.png'), 'not before deadline');
    expireFixture();
    check(Retention::deleteExpired(1), 'expired original removed');
    check(!file_exists($root . '/source.png') && file_exists($root . '/display.png') && file_exists($root . '/small.png'), 'only source deleted');
    check(!isset($data[1]['original_image']) && empty($meta[1]['_alps_original_delete_after']), 'metadata and queue cleared');
    check(!Retention::deleteExpired(1), 'repeated cleanup harmless');
    resetFixture(); expireFixture();
    unlink($root . '/display.png');
    check(!Retention::deleteExpired(1) && file_exists($root . '/source.png'), 'missing full retained');
    resetFixture(); expireFixture();
    unlink($root . '/small.png');
    check(!Retention::deleteExpired(1), 'missing small retained');
    resetFixture(); expireFixture();
    $data[1]['sizes'] = [];
    check(!Retention::deleteExpired(1), 'failed small generation retained');
    resetFixture(); expireFixture();
    $data[1]['original_image'] = '../outside.png';
    check(!Retention::deleteExpired(1), 'traversal refused');
    resetFixture(); expireFixture();
    $data[1]['original_image'] = 'display.png';
    Retention::track($data[1], 1); expireFixture();
    check(!Retention::deleteExpired(1), 'active source retained');
    resetFixture(); expireFixture();
    $shared = true;
    check(!Retention::deleteExpired(1), 'shared attachment source retained');
    resetFixture(); expireFixture();
    $blocked = true;
    check(!Retention::deleteExpired(1) && isset($data[1]['original_image']), 'failed deletion preserves metadata');
    Retention::sweep();
    check($meta[1]['_alps_original_delete_after'] >= time() + DAY_IN_SECONDS - 1, 'blocked source rescheduled');
    resetFixture(); expireFixture();
    $meta[1]['_wp_attachment_backup_sizes'] = ['full-orig' => ['file' => 'source.png']];
    check(!Retention::deleteExpired(1), 'editor backup retained');
    resetFixture(); expireFixture();
    unlink($root . '/source.png');
    check(Retention::deleteExpired(1) && !isset($data[1]['original_image']), 'missing source metadata repaired');
    resetFixture(); expireFixture();
    $meta[1]['_alps_two_size_upload'] = '';
    check(!Retention::deleteExpired(1), 'legacy attachment excluded');
    echo "Original retention: $checks checks passed.\n";
} finally {
    // Only delete this test's explicitly named files in its random temporary directory.
    foreach (['source.png', 'display.png', 'small.png'] as $file) {
        if (is_file($root . '/' . $file)) { unlink($root . '/' . $file); }
    }
    rmdir($root);
}
