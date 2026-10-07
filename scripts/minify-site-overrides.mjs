// Writes assets/css/site-overrides.min.css, the minified copy the theme loads
// (functions.php falls back to the readable file when SCRIPT_DEBUG is on or
// the copy is missing). lightningcss is already a build dependency.
//
//   node scripts/minify-site-overrides.mjs           rebuild
//   node scripts/minify-site-overrides.mjs --check   fail if out of date (CI)
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { transform } from 'lightningcss';

const source = 'assets/css/site-overrides.css';
const target = 'assets/css/site-overrides.min.css';
// Same bytes on Windows (CRLF checkouts) and in CI.
const css = readFileSync(source, 'utf8').replace(/\r\n/g, '\n');
const { code } = transform({ filename: source, code: Buffer.from(css), minify: true });
const output = code.toString() + '\n';

if (process.argv.includes('--check')) {
  const current = existsSync(target) ? readFileSync(target, 'utf8').replace(/\r\n/g, '\n') : '';
  if (current !== output) {
    console.error(`${target} is out of date: run node scripts/minify-site-overrides.mjs`);
    process.exit(1);
  }
  console.log(`PASS: ${target} is up to date (${output.length} bytes from ${css.length}).`);
} else {
  writeFileSync(target, output);
  console.log(`Wrote ${target}: ${output.length} bytes from ${css.length}.`);
}
