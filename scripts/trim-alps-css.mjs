// Writes main-denim.used.css: the ALPS stylesheet without the rules for
// components the site never uses (about four fifths of the file). The theme
// links this copy instead of the full one; the full file stays as the
// source and is loaded under SCRIPT_DEBUG or when the copy is missing.
//
// A selector is dropped only when it needs a class missing from
// scripts/alps-used-classes.txt (classes inside :not(), :is() and the like are
// ignored, so those selectors stay). A rule goes when all its selectors go.
// Nothing else changes, so the rules that stay keep their order.
//
//   node scripts/trim-alps-css.mjs           rebuild
//   node scripts/trim-alps-css.mjs --check   fail if the copy is out of date (CI)
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { transform } from 'lightningcss';

const source = 'app/local/alps/css/main-denim.css';
const list = 'scripts/alps-used-classes.txt';
const target = 'app/local/alps/css/main-denim.used.css';

const used = new Set(
  readFileSync(list, 'utf8').split(/\r?\n/).filter((line) => line && !line.startsWith('#')),
);
const canMatch = (selector) => selector.every((part) => part.type !== 'class' || used.has(part.name));

let dropped = 0;
const output = transform({
  filename: source,
  code: Buffer.from(readFileSync(source, 'utf8').replace(/\r\n/g, '\n')),
  minify: true,
  visitor: {
    Rule: {
      style(rule) {
        const selectors = rule.value.selectors.filter(canMatch);
        dropped += rule.value.selectors.length - selectors.length;
        if (selectors.length === 0) return [];
        rule.value.selectors = selectors;
        return rule;
      },
    },
  },
}).code.toString() + '\n';

if (process.argv.includes('--check')) {
  const current = existsSync(target) ? readFileSync(target, 'utf8').replace(/\r\n/g, '\n') : '';
  if (current !== output) {
    console.error(`${target} is out of date: run node scripts/trim-alps-css.mjs`);
    process.exit(1);
  }
  console.log(`PASS: ${target} is up to date (${output.length} bytes).`);
} else {
  writeFileSync(target, output);
  console.log(`Wrote ${target}: ${output.length} bytes from ${readFileSync(source).length}; ${dropped} selectors dropped.`);
}
