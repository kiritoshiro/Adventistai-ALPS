// Fails if the theme's own code uses Carbon Fields again. The theme reads and
// writes the values in Carbon Fields' storage format itself (app/Fields/,
// app/Widgets/); Carbon Fields is only a Composer dev dependency for the
// comparison tests (tests/fields/), which are not part of the theme package.
// Comment lines may still name Carbon Fields; widget IDs keep their
// "carbon_fields_" prefix (stored data).
import {readFileSync, readdirSync, statSync} from 'node:fs';
import {join, relative, resolve} from 'node:path';

const root = resolve(import.meta.dirname, '..');
const roots = ['app', 'resources', 'inc', 'functions.php', 'index.php', 'searchform.php', 'defaults.php', 'defaults-themes.php'];
const extensions = /\.(php|js|mjs|ts|json)$/;
const patterns = [
  [/Carbon_Fields\\/, 'Carbon_Fields namespace'],
  [/\bcarbon_(get|set)_/, 'carbon_get_*/carbon_set_* function'],
  [/htmlburger/, 'htmlburger package'],
  [/carbon-fields\//, 'carbon-fields/ path'],
  [/\bcrb_(?!get_i18n_)/, 'crb_ helper'],
];
const comment = /^\s*(\*|\/\/|\/\*|#|\{\{--)/;

function files(path) {
  const full = join(root, path);
  if (!statSync(full, {throwIfNoEntry: false})) {
    return [];
  }
  if (statSync(full).isFile()) {
    return extensions.test(path) || path.endsWith('.blade.php') ? [full] : [];
  }
  return readdirSync(full).flatMap((name) => files(join(path, name)));
}

const problems = [];
for (const file of roots.flatMap(files)) {
  readFileSync(file, 'utf8').split('\n').forEach((line, index) => {
    if (comment.test(line)) {
      return;
    }
    for (const [pattern, label] of patterns) {
      if (pattern.test(line)) {
        problems.push(`${relative(root, file)}:${index + 1}: ${label}: ${line.trim().slice(0, 120)}`);
      }
    }
  });
}
const composer = JSON.parse(readFileSync(join(root, 'composer.json'), 'utf8'));
if (composer.require && composer.require['htmlburger/carbon-fields']) {
  problems.push('composer.json: htmlburger/carbon-fields is a runtime dependency (only require-dev is allowed)');
}

if (problems.length) {
  console.error(problems.join('\n'));
  console.error(`\n${problems.length} Carbon Fields reference(s) in the theme. Use App\\Fields\\Store and friends instead.`);
  process.exit(1);
}
console.log('No Carbon Fields in the theme code.');
