<?php
// Checks for the Sabbath timer payload embedded on the front page. The times
// themselves are calculated in the browser: see tests/sabbath/sunsets.cjs.
require __DIR__ . '/../../app/SabbathTimer.php';
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
use App\SabbathTimer;

$checks = 0;
$ok = function ($condition, $message) use (&$checks) { check($condition, $message); $checks++; };

$data = SabbathTimer::data();
$ok(count($data['cities']) === 20, 'all cities present');
$ok($data['defaultCity'] === 'vilnius', 'default city');
$ok($data['timezone'] === 'Europe/Vilnius' && $data['fridayRevealHour'] === 6, 'time zone and the 06:00 Friday reveal');
foreach ($data['cities'] as $city) {
    $ok(array_keys($city) === ['name', 'lat', 'lon'], 'a city carries only its name and coordinates');
    $ok($city['lat'] > 53.8 && $city['lat'] < 56.5 && $city['lon'] > 20.9 && $city['lon'] < 26.9, $city['name'] . ' lies in Lithuania');
}

$ok($data['verses'] === [['text' => 'Atmink ir švęsk šabo dieną.', 'reference' => 'Išėjimo knyga 20, 8']], 'the default verse without settings');
$ok(SabbathTimer::enabled(), 'the timer shows unless the settings hide it');

$json = wp_json_like($data);
$ok(strlen($json) < 2500, 'embedded payload stays small (' . strlen($json) . ' bytes)');

$partial = (string) file_get_contents(__DIR__ . '/../../resources/views/partials/sabbath-timer.blade.php');
$ok(false !== strpos($partial, 'SabbathTimer::data()') && false !== strpos($partial, 'data-sabbath-data'), 'the partial embeds the payload for the script');

// Hiding the timer drops both its markup and its embedded stylesheet.
$header = (string) file_get_contents(__DIR__ . '/../../resources/views/patterns/02-organisms/sections/page-header.blade.php');
$ok(false !== strpos($header, 'is_front_page() && \App\SabbathTimer::enabled()') && false !== strpos($header, "@if (\$sabbath_timer)"), 'the front-page header asks whether the timer is on');
$setup = (string) file_get_contents(__DIR__ . '/../../app/setup.php');
$ok(false !== strpos($setup, 'is_front_page() && SabbathTimer::enabled()'), 'the timer stylesheet is embedded only while the timer is on');

// The flags the partial passes to wp_json_encode().
function wp_json_like($data) { return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); }

echo "Sabbath timer: {$checks} checks passed.\n";
