// The browser calculation in assets/js/sabbath-timer.js must give the same
// Sabbath times as the PHP implementation it replaced. sunsets.json holds
// that implementation's results for six cities at the corners of Lithuania,
// October 2026 to October 2027 (both daylight-saving changes).
// Run: node tests/sabbath/sunsets.cjs
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..', '..');
// The readable script and the minified copy the page embeds.
const calculators = ['assets/js/sabbath-timer.js', 'assets/js/sabbath-timer.min.js'].map((file) => {
  const loaded = { exports: {} };
  new Function('module', fs.readFileSync(path.join(root, file), 'utf8'))(loaded);
  return [file, loaded.exports.weekEvents];
});

const php = fs.readFileSync(path.join(root, 'app/SabbathTimer.php'), 'utf8');
const coordinates = {};
for (const match of php.matchAll(/'(\w+)' => \['name' => '[^']*', 'lat' => ([\d.]+), 'lon' => ([\d.]+)\]/g)) {
  coordinates[match[1]] = [Number(match[2]), Number(match[3])];
}

let failures = 0;
let checks = 0;
const check = (condition, message) => {
  checks += 1;
  if (!condition) {
    failures += 1;
    console.log(`FAIL ${message}`);
  }
};

check(Object.keys(coordinates).length === 20, 'coordinates of all 20 cities read from SabbathTimer.php');

for (const [file, weekEvents] of calculators) {
  const expected = JSON.parse(fs.readFileSync(path.join(__dirname, 'sunsets.json'), 'utf8')).cities;
  for (const [city, rows] of Object.entries(expected)) {
    const [lat, lon] = coordinates[city];
    for (const [start, reveal, end] of rows) {
      // Seen from Thursday noon, Friday morning and Saturday noon of that week.
      for (const nowMs of [start * 1000 - 30 * 3600 * 1000, reveal * 1000, end * 1000 - 6 * 3600 * 1000]) {
        const events = weekEvents(lat, lon, nowMs, 'Europe/Vilnius', 6);
        const startEvent = events.find((event) => event.type === 'start' && event.timestamp === start);
        const endEvent = events.find((event) => event.type === 'end' && event.timestamp === end);
        check(startEvent && startEvent.revealTimestamp === reveal && endEvent,
          `${file} ${city} ${new Date(start * 1000).toISOString().slice(0, 10)} seen at ${new Date(nowMs).toISOString()}: ${JSON.stringify(events.filter((event) => Math.abs(event.timestamp - start) < 2 * 86400))}`);
      }
    }
  }

  // Weeks run Monday to Sunday: on Sunday the past Sabbath is last in this week.
  const sunday = Date.UTC(2026, 9, 4, 10); // Sunday 4 October 2026, 13:00 in Vilnius
  const sundayEvents = weekEvents(54.6872, 25.2797, sunday, 'Europe/Vilnius', 6);
  check(sundayEvents.length === 6 && sundayEvents.some((event) => event.type === 'start' && event.timestamp * 1000 > sunday), `${file}: on Sunday the next Friday is already known`);
}

console.log(failures ? `${failures} of ${checks} checks failed` : `Sabbath sunsets: ${checks} checks passed`);
process.exit(failures ? 1 : 0);
