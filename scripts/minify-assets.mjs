// Writes the minified copies the theme loads or embeds, from the readable
// sources (the theme falls back to a source when SCRIPT_DEBUG is on or the
// copy is missing). lightningcss and terser are already build dependencies.
//
//   node scripts/minify-assets.mjs           rebuild
//   node scripts/minify-assets.mjs --check   fail if any copy is out of date (CI)
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { transform } from 'lightningcss';
import { minify } from 'terser';

const assets = [
  ['assets/css/site-overrides.css', 'assets/css/site-overrides.min.css'],
  ['assets/css/card-scroller.css', 'assets/css/card-scroller.min.css'],
  ['assets/js/sabbath-timer.js', 'assets/js/sabbath-timer.min.js'],
];

const check = process.argv.includes('--check');
let failed = false;
for (const [source, target] of assets) {
  // Same bytes on Windows (CRLF checkouts) and in CI.
  const code = readFileSync(source, 'utf8').replace(/\r\n/g, '\n');
  const output = source.endsWith('.css')
    ? transform({ filename: source, code: Buffer.from(code), minify: true }).code.toString() + '\n'
    : (await minify(code, { ecma: 2020, compress: true, mangle: true, format: { comments: false } })).code + '\n';

  if (check) {
    const current = existsSync(target) ? readFileSync(target, 'utf8').replace(/\r\n/g, '\n') : '';
    if (current !== output) {
      console.error(`${target} is out of date: run node scripts/minify-assets.mjs`);
      failed = true;
    } else {
      console.log(`PASS: ${target} is up to date (${output.length} bytes from ${code.length}).`);
    }
  } else {
    writeFileSync(target, output);
    console.log(`Wrote ${target}: ${output.length} bytes from ${code.length}.`);
  }
}
process.exit(failed ? 1 : 0);
