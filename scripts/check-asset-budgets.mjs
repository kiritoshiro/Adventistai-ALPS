import { readFileSync, statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const manifest = JSON.parse(readFileSync(resolve(root, 'public/manifest.json'), 'utf8'));
const built = (key) => typeof manifest[key] === 'string' ? `public/${manifest[key]}` : '';

const budgets = [
  ['Frontend CSS', built('app.css'), 22_000],
  ['Frontend JS', built('app.js'), 4_500],
  ['Shared runtime JS', built('runtime.js'), 2_500],
  ['Editor CSS', built('editor.css'), 9_000],
  ['Editor JS', built('editor.js'), 24_000],
  ['ALPS denim CSS', 'app/local/alps/css/main-denim.css', 215_000],
  ['Site overrides CSS', 'assets/css/site-overrides.css', 32_000],
  ['Site overrides CSS (minified, loaded)', 'assets/css/site-overrides.min.css', 15_000],
  ['Responsive fixes CSS', 'assets/css/responsive-zoom-fixes.css', 8_000],
  ['Search CSS', 'assets/css/adv-search.css', 7_500],
  ['Sabbath timer CSS', 'assets/css/sabbath-timer.css', 8_000],
  ['Search JS', 'assets/js/adv-search.js', 11_000],
  // Readable source; it calculates the sunsets that were embedded as about
  // 16 KB of JSON and places the city list in the top layer. The page embeds
  // the minified copy.
  ['Sabbath timer JS', 'assets/js/sabbath-timer.js', 17_000],
  ['Sabbath timer JS (minified, embedded)', 'assets/js/sabbath-timer.min.js', 8_500],
  ['Card scroller CSS', 'assets/css/card-scroller.css', 10_000],
  ['Card scroller CSS (minified, loaded)', 'assets/css/card-scroller.min.css', 8_500],
  ['Card scroller JS', 'assets/js/card-scroller.js', 4_500],
  // Embedded only on pages whose header shows an image (page-header.blade.php).
  ['Page header image CSS', 'assets/css/page-hero.css', 3_500],
  ['Page header image CSS (minified, embedded)', 'assets/css/page-hero.min.css', 2_100],
];

let failed = false;
for (const [label, relativePath, limit] of budgets) {
  if (!relativePath || !/^(public|app|assets)\//.test(relativePath) || relativePath.includes('..') || relativePath.includes('\\')) {
    console.error(`FAIL ${label}: invalid or missing asset path: ${relativePath}`);
    failed = true;
    continue;
  }

  try {
    const bytes = statSync(resolve(root, relativePath)).size;
    const status = bytes <= limit ? 'PASS' : 'FAIL';
    console.log(`${status} ${label}: ${bytes} / ${limit} bytes (${relativePath})`);
    if (bytes > limit) failed = true;
  } catch (error) {
    console.error(`FAIL ${label}: ${error.message}`);
    failed = true;
  }
}

if (failed) {
  console.error('Theme asset budget failed. Inspect the built output before raising a limit.');
  process.exitCode = 1;
}
