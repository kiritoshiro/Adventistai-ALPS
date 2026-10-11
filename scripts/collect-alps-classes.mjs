// Lists the ALPS classes the site can produce, for scripts/trim-alps-css.mjs.
//
// A class counts as used when its name appears anywhere in the theme's PHP,
// Blade or JS, or in any extra file or folder given on the command line:
// saved pages of the live site, plugin sources, downloaded plugin scripts.
// Stylesheets and <style> blocks are skipped, since they name every class.
// A name ending in "-" (for example 'u-theme--' + colour in a script) keeps
// every class that starts with it.
//
// The list only grows: classes already in it are kept, so a page that was
// not saved this time cannot lose its styles. --fresh starts from scratch.
//
//   node scripts/collect-alps-classes.mjs [--fresh] [file-or-folder ...]
import { readFileSync, writeFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { join, extname } from 'node:path';
import { transform } from 'lightningcss';

const source = 'app/local/alps/css/main-denim.css';
const list = 'scripts/alps-used-classes.txt';
const themeSources = ['app', 'resources', 'assets/js', 'functions.php', 'index.php'];
const textTypes = new Set(['.php', '.js', '.mjs', '.jsx', '.ts', '.tsx', '.json', '.html', '.htm', '.txt']);

const args = process.argv.slice(2);
const fresh = args.includes('--fresh');
const extra = args.filter((arg) => arg !== '--fresh');

// Every class name the ALPS stylesheet mentions, including inside :not() etc.
const candidates = new Set();
const collect = (selectors) => {
  for (const selector of selectors) {
    for (const part of selector) {
      if (part.type === 'class') candidates.add(part.name);
      if (Array.isArray(part.selectors)) collect(part.selectors);
    }
  }
};
transform({
  filename: source,
  code: readFileSync(source),
  visitor: { Rule: { style(rule) { collect(rule.value.selectors); } } },
});

const names = new Set();
const prefixes = new Set();
const scan = (path) => {
  if (!existsSync(path)) return;
  if (statSync(path).isDirectory()) {
    for (const entry of readdirSync(path)) {
      if (entry === 'node_modules' || entry === 'vendor' || entry.startsWith('.')) continue;
      scan(join(path, entry));
    }
    return;
  }
  const type = extname(path).toLowerCase();
  if (!textTypes.has(type)) return;
  let text = readFileSync(path, 'utf8');
  if (type === '.html' || type === '.htm') text = text.replace(/<style\b[\s\S]*?<\/style>/gi, ' ');
  for (const [token] of text.matchAll(/-?[A-Za-z_][\w-]*/g)) {
    names.add(token);
    if (token.endsWith('-') && token.replace(/-+$/, '').length >= 3) prefixes.add(token);
  }
};
for (const path of [...themeSources, ...extra]) scan(path);

const used = new Set();
if (!fresh && existsSync(list)) {
  for (const line of readFileSync(list, 'utf8').split('\n')) {
    if (line && !line.startsWith('#') && candidates.has(line)) used.add(line);
  }
}
const before = used.size;
for (const name of candidates) {
  if (names.has(name) || [...prefixes].some((prefix) => name.startsWith(prefix))) used.add(name);
}

const header = [
  '# ALPS classes the site uses; scripts/trim-alps-css.mjs keeps only rules that can match them.',
  '# Rebuild with scripts/collect-alps-classes.mjs. Add a class by hand if an editor starts using a new one.',
];
writeFileSync(list, [...header, ...[...used].sort()].join('\n') + '\n');
console.log(`${used.size} of ${candidates.size} ALPS classes used (${used.size - before} new); wrote ${list}.`);
