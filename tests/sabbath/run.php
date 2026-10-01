<?php
// Checks for the Sabbath timer payload embedded on the front page.
require __DIR__ . '/../../app/SabbathTimer.php';
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
use App\SabbathTimer;

$checks = 0;
$ok = function ($condition, $message) use (&$checks) { check($condition, $message); $checks++; };
$zone = new DateTimeZone('Europe/Vilnius');

// Thursday 1 October 2026, before the Friday reveal.
$data = SabbathTimer::data(new DateTimeImmutable('2026-10-01 12:00:00', $zone));
$ok(count($data['cities']) === 20, 'all cities present');
$ok($data['defaultCity'] === 'vilnius', 'default city');

$vilnius = $data['cities']['vilnius']['events'];
$ok(count($vilnius) === 14, 'seven weeks of start/end events');
foreach ($vilnius as $event) {
    $keys = array_keys($event);
    $expected = $event['type'] === 'start' ? ['type', 'timestamp', 'revealTimestamp'] : ['type', 'timestamp'];
    $ok($keys === $expected, 'event carries only the fields the script reads');
}

$start = null;
foreach ($vilnius as $event) {
    if ($event['type'] === 'start' && $event['timestamp'] > strtotime('2026-10-01 12:00:00 Europe/Vilnius')) {
        $start = $event;
        break;
    }
}
$ok($start !== null, 'next Sabbath start found');
$sunset = (new DateTimeImmutable('@' . $start['timestamp']))->setTimezone($zone);
$ok($sunset->format('Y-m-d') === '2026-10-02', 'next start is Friday 2 October');
$ok($sunset->format('H') === '18', 'Vilnius sunset in the 18:00 hour in early October');
$reveal = (new DateTimeImmutable('@' . $start['revealTimestamp']))->setTimezone($zone);
$ok($reveal->format('Y-m-d H:i') === '2026-10-02 06:00', 'countdown revealed at 06:00 on Friday');

$json = json_encode($data);
$ok(strlen($json) < 18000, 'embedded payload stays small (' . strlen($json) . ' bytes)');

echo "Sabbath timer: {$checks} checks passed.\n";
