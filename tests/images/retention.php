<?php
require __DIR__ . '/../../app/OriginalImageRetention.php';
use App\OriginalImageRetention as Retention;
const MINUTE_IN_SECONDS = 60;
const WEEK_IN_SECONDS = 604800;
const DAY_IN_SECONDS = 86400;
$root = sys_get_temp_dir() . '/alps-retention-' . bin2hex(random_bytes(6));
mkdir($root);
$meta = [];
$data = [];
$options = [];
$scheduled = [];
$shared = false;
$blocked = false;
$checks = 0;
function check($condition, $message) { global $checks; ++$checks; if (!$condition) { throw new RuntimeException($message); } }
function get_post_meta($id, $key, $single) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; }
function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$id][$key]); }
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; }
function wp_next_scheduled($hook) { return $GLOBALS['scheduled'][$hook] ?? false; }
function wp_schedule_single_event($time, $hook) { $GLOBALS['scheduled'][$hook] = $time; }
function wp_get_attachment_metadata($id) { return $GLOBALS['data'][$id]; }
function wp_update_attachment_metadata($id, $value) { $GLOBALS['data'][$id] = Retention::track($value, $id); }
function wp_get_upload_dir() { return ['basedir' => $GLOBALS['root']]; }
function get_attached_file($id, $unfiltered = false) { return $GLOBALS['root'] . '/display.png'; }
function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
function wp_getimagesize($file) { return getimagesize($file); }
function get_posts($args) {
    if ($args['meta_key'] === '_wp_attached_file') { return $GLOBALS['shared'] ? [2] : []; }
    // The retention queue: attachments with a due date (in range, for the sweep).
    $ids = [];
    foreach ($GLOBALS['meta'] as $id => $values) {
        $due = $values['_alps_original_delete_after'] ?? null;
        if ($due !== null && (!isset($args['meta_value']) || $due <= $args['meta_value'])) { $ids[] = $id; }
    }
    return $ids;
}
function wp_delete_file_from_directory($file, $dir) {
    if (!$GLOBALS['blocked'] && dirname(realpath($file)) === realpath($dir)) { unlink($file); }
}
function resetFixture() {
    global $meta, $data, $root, $shared, $blocked, $scheduled;
    $shared = $blocked = false;
    $scheduled = [];
    $meta = [1 => ['_alps_two_size_upload' => 1]];
    $data = [1 => ['file' => 'display.png', 'width' => 1920, 'height' => 1280, 'original_image' => 'source.png', 'sizes' => ['alps-small' => ['file' => 'small.png']]]];
    $png = hex2bin('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000b49444154789c636000020000050001a5f645400000000049454e44ae426082');
    foreach (['source.png', 'display.png', 'small.png'] as $file) { file_put_contents($root . '/' . $file, $png); }
    Retention::track($data[1], 1);
}
function expireFixture() { $GLOBALS['meta'][1]['_alps_original_delete_after'] = time() - 1; }
function near($a, $b) { return abs($a - $b) < 3; }
try {
    Retention::apply(); // The default settings, as already applied on a site.
    resetFixture();
    $due =$meta[1]['_alps_original_delete_after'];
    check(abs($due - time() - WEEK_IN_SECONDS) < 2, 'seven day grace');
    check(near($meta[1]['_alps_original_kept_at'], time()) && Retention::keptAt(1) === $meta[1]['_alps_original_kept_at'], 'kept time recorded');
    check(!$scheduled, 'no early sweep while originals are kept');
    Retention::track($data[1], 1);
    check($due === $meta[1]['_alps_original_delete_after'], 'metadata update preserves deadline');
    check(!Retention::deleteExpired(1) && file_exists($root . '/source.png'), 'not before deadline');
    expireFixture();
    check(Retention::deleteExpired(1), 'expired original removed');
    check(!file_exists($root . '/source.png') && file_exists($root . '/display.png') && file_exists($root . '/small.png'), 'only source deleted');
    check(!isset($data[1]['original_image']) && empty($meta[1]['_alps_original_delete_after']) && empty($meta[1]['_alps_original_kept_at']), 'metadata and queue cleared');
    check(!Retention::deleteExpired(1), 'repeated cleanup harmless');

    // Every safety check refuses both the scheduled and the forced deletion; only the due date differs.
    $guards = [
        'missing full retained' => function () { unlink($GLOBALS['root'] . '/display.png'); },
        'missing small retained' => function () { unlink($GLOBALS['root'] . '/small.png'); },
        'failed small generation retained' => function () { $GLOBALS['data'][1]['sizes'] = []; },
        'traversal refused' => function () { $GLOBALS['data'][1]['original_image'] = '../outside.png'; },
        'active source retained' => function () {
            $GLOBALS['data'][1]['original_image'] = 'display.png';
            Retention::track($GLOBALS['data'][1], 1);
        },
        'shared attachment source retained' => function () { $GLOBALS['shared'] = true; },
        'failed deletion preserves metadata' => function () { $GLOBALS['blocked'] = true; },
        'editor backup retained' => function () { $GLOBALS['meta'][1]['_wp_attachment_backup_sizes'] = ['full-orig' => ['file' => 'source.png']]; },
        'legacy attachment excluded' => function () { $GLOBALS['meta'][1]['_alps_two_size_upload'] = ''; },
    ];
    foreach ($guards as $label => $break) {
        resetFixture(); $break(); expireFixture();
        check(!Retention::deleteExpired(1) && isset($data[1]['original_image']), $label);
        resetFixture(); $break();
        check(!Retention::deleteNow(1) && isset($data[1]['original_image']), "$label (forced)");
        if (is_file($root . '/display.png') && $data[1]['original_image'] === 'source.png') {
            check(file_exists($root . '/source.png'), "$label: source kept (forced)");
        }
    }
    resetFixture();
    unset($meta[1]['_alps_original_delete_after']);
    check(!Retention::deleteNow(1) && file_exists($root . '/source.png'), 'untracked attachment excluded (forced)');
    resetFixture(); expireFixture();
    $blocked = true;
    Retention::sweep();
    check(near($meta[1]['_alps_original_delete_after'], time() + DAY_IN_SECONDS), 'blocked source rescheduled');
    resetFixture(); expireFixture();
    unlink($root . '/source.png');
    check(Retention::deleteExpired(1) && !isset($data[1]['original_image']), 'missing source metadata repaired');

    // Forced deletion before the due date.
    resetFixture();
    check(Retention::deleteNow(1) && !file_exists($root . '/source.png') && !isset($data[1]['original_image']), 'forced deletion before the deadline');
    check(empty($meta[1]['_alps_original_delete_after']) && empty($meta[1]['_alps_original_kept_at']), 'forced deletion clears the queue');
    check(!Retention::deleteNow(1), 'repeated forced deletion harmless');

    // Settings: days.
    $options = [];
    check(Retention::keeps() && Retention::days() === 7, 'defaults: kept for 7 days');
    foreach (['3' => 3, ' 30 ' => 30, '0' => 1, '-5' => 1, '999' => 365, 'abc' => 7, '' => 7] as $value => $days) {
        $options['_alps_image_original_days'] = (string) $value;
        check(Retention::days() === $days, "days '$value' read as $days");
    }
    $options = ['_alps_image_original_days' => '3'];
    resetFixture();
    check(near($meta[1]['_alps_original_delete_after'], time() + 3 * DAY_IN_SECONDS), 'custom days for new originals');

    // Changing the days applies to originals already kept, from when they were kept.
    $options = [];
    resetFixture();
    Retention::apply();
    $kept = time() - 2 * DAY_IN_SECONDS;
    $meta[1]['_alps_original_kept_at'] = $kept;
    $options['_alps_image_original_days'] = '10';
    Retention::apply();
    check($meta[1]['_alps_original_delete_after'] === $kept + 10 * DAY_IN_SECONDS, 'longer period recomputed from kept time');
    $options['_alps_image_original_days'] = '1';
    Retention::apply();
    check($meta[1]['_alps_original_delete_after'] === $kept + DAY_IN_SECONDS, 'shorter period recomputed');
    $retry = time() + DAY_IN_SECONDS;
    $meta[1]['_alps_original_delete_after'] = $retry;
    Retention::apply();
    check($meta[1]['_alps_original_delete_after'] === $retry, 'unchanged settings leave due dates (retries) alone');
    Retention::sweep();
    check(file_exists($root . '/source.png'), 'sweep keeps the original before its due date');
    // Saved without the settings page's hook (e.g. another tool): the sweep applies it first.
    $options['_alps_image_original_days'] = '2';
    Retention::sweep();
    check(!file_exists($root . '/source.png'), 'sweep applies changed settings, then deletes what is due');

    // Rows kept before the setting existed have no kept time: kept = due - 7 days.
    $options = [];
    resetFixture();
    Retention::apply();
    $oldDue = time() + 4 * DAY_IN_SECONDS;
    $meta[1]['_alps_original_delete_after'] = $oldDue;
    unset($meta[1]['_alps_original_kept_at']);
    check(Retention::keptAt(1) === $oldDue - WEEK_IN_SECONDS, 'kept time derived for existing rows');
    $options['_alps_image_original_days'] = '14';
    Retention::apply();
    check($meta[1]['_alps_original_kept_at'] === $oldDue - WEEK_IN_SECONDS, 'derived kept time stored');
    check($meta[1]['_alps_original_delete_after'] === $oldDue - WEEK_IN_SECONDS + 14 * DAY_IN_SECONDS, 'existing row recomputed with the new days');
    $meta[2] = ['_alps_original_delete_after' => 0];
    $options['_alps_image_original_days'] = '15';
    Retention::apply();
    check($meta[2] === ['_alps_original_delete_after' => 0], 'rows without a kept time untouched');
    unset($meta[2]);

    // Setting off: new originals are due at once and swept soon; kept ones at the next sweep.
    $options = ['_alps_image_keep_originals' => ''];
    check(!Retention::keeps(), 'unticked setting read as off');
    resetFixture();
    check(near($meta[1]['_alps_original_delete_after'], time()), 'not kept: due at once');
    check(isset($scheduled[Retention::SOON]) && near($scheduled[Retention::SOON], time() + 5 * MINUTE_IN_SECONDS), 'not kept: sweep scheduled after the upload');
    $first = $scheduled[Retention::SOON];
    $data[1]['original_image'] = 'other.png';
    Retention::track($data[1], 1);
    check($scheduled[Retention::SOON] === $first, 'one early sweep at a time');
    $data[1]['original_image'] = 'source.png';
    Retention::track($data[1], 1);
    $meta[1]['_alps_original_delete_after'] = time();
    Retention::sweep();
    check(!file_exists($root . '/source.png') && !isset($data[1]['original_image']), 'not kept: deleted by the sweep');
    $options = [];
    resetFixture();
    Retention::apply();
    check(file_exists($root . '/source.png') && $meta[1]['_alps_original_delete_after'] > time(), 'kept while on');
    $options['_alps_image_keep_originals'] = '';
    Retention::sweep();
    check(!file_exists($root . '/source.png'), 'switching off deletes kept originals at the next sweep');
    $options = ['_alps_image_keep_originals' => 'true'];
    check(Retention::keeps(), 'ticked setting read as on');
    echo "Original retention: $checks checks passed.\n";
} finally {
    // Only delete this test's explicitly named files in its random temporary directory.
    foreach (['source.png', 'display.png', 'small.png'] as $file) {
        if (is_file($root . '/' . $file)) { unlink($root . '/' . $file); }
    }
    rmdir($root);
}
