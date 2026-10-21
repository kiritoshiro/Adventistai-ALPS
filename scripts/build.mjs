// Builds the theme bundles that Acorn's bundle('app') and bundle('editor')
// enqueue: resources/scripts/{app,editor}.js with esbuild and
// resources/styles/{app,editor}.css with lightningcss, into content-hashed
// files under public/js and public/css, plus public/manifest.json and
// public/entrypoints.json in the format bud wrote. Files from the previous
// builds stay for a while (see previous-assets.json below).
//
// public/ is committed and shipped as-is; CI runs this and fails when the
// result differs from the committed files.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import browserslist from 'browserslist';
import { build } from 'esbuild';
import { browserslistToTargets, bundle, Features } from 'lightningcss';

// The caniuse data comes from the lockfile, so builds stay reproducible.
process.env.BROWSERSLIST_IGNORE_OLD_DATA = '1';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const publicDir = resolve(root, 'public');
const entries = ['app', 'editor'];

// package.json "browserslist" (@wordpress/browserslist-config).
const browsers = browserslist(undefined, { path: root });

// esbuild wants the oldest version of each engine it knows. Android WebView
// and Samsung Internet are Chromium; current Chrome covers them.
const esbuildEngines = {
  chrome: 'chrome',
  and_chr: 'chrome',
  edge: 'edge',
  firefox: 'firefox',
  and_ff: 'firefox',
  safari: 'safari',
  ios_saf: 'ios',
  opera: 'opera',
};
const versionParts = (version) => version.split('.').map(Number);
const older = (a, b) => {
  const [x, y] = [versionParts(a), versionParts(b)];
  for (let i = 0; i < Math.max(x.length, y.length); i++) {
    if ((x[i] ?? 0) !== (y[i] ?? 0)) return (x[i] ?? 0) < (y[i] ?? 0);
  }
  return false;
};
const oldest = {};
for (const browser of browsers) {
  const [name, range] = browser.split(' ');
  const engine = esbuildEngines[name];
  const version = range.split('-')[0];
  if (!engine) continue;
  if (!/^\d+(\.\d+)*$/.test(version)) throw new Error(`Unexpected browserslist version "${browser}"`);
  if (!oldest[engine] || older(version, oldest[engine])) oldest[engine] = version;
}
const jsTarget = Object.entries(oldest).map(([engine, version]) => `${engine}${version}`);

// @wordpress/* imports are WordPress's own scripts (window.wp.*): keep them
// out of the bundle and list their handles as the bundle's dependencies.
// Not every @wordpress package has a script (e.g. icons, primitives), and a
// dependency WordPress never registers makes it skip the whole bundle, so
// only packages checked to exist as `wp-<name>` scripts are allowed.
const wordpressScripts = new Set([
  'api-fetch',
  'block-editor',
  'blocks',
  'components',
  'core-data',
  'data',
  'editor',
  'element',
  'i18n',
  'plugins',
  'server-side-render',
]);
const wordpressGlobals = (handles) => ({
  name: 'wordpress-globals',
  setup(build) {
    build.onResolve({ filter: /^@wordpress\// }, ({ path, importer }) => {
      if (!wordpressScripts.has(path.slice('@wordpress/'.length))) {
        return { errors: [{ text: `${path} (in ${importer}) is not in scripts/build.mjs's wordpressScripts. Add it only if WordPress registers a "wp-${path.slice('@wordpress/'.length)}" script; otherwise install and bundle the package.` }] };
      }
      return { path, namespace: 'wordpress' };
    });
    build.onLoad({ filter: /.*/, namespace: 'wordpress' }, ({ path }) => {
      const name = path.slice('@wordpress/'.length);
      const global = name.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
      handles.add(`wp-${name}`);
      return { contents: `module.exports = window.wp.${global};`, loader: 'js' };
    });
  },
});

const hashed = (dir, name, extension, contents) => {
  const hash = createHash('sha256').update(contents).digest('hex').slice(0, 6);
  return `${dir}/${name}.${hash}.${extension}`;
};

async function buildScript(name) {
  const handles = new Set();
  const result = await build({
    entryPoints: [resolve(root, `resources/scripts/${name}.js`)],
    bundle: true,
    format: 'iife',
    minify: true,
    target: jsTarget,
    // The block scripts use JSX; WordPress's React lives in wp.element.
    loader: { '.js': 'jsx' },
    jsx: 'transform',
    jsxFactory: 'wp.element.createElement',
    jsxFragment: 'wp.element.Fragment',
    legalComments: 'none',
    plugins: [wordpressGlobals(handles)],
    write: false,
    logLevel: 'warning',
  });
  const code = result.outputFiles[0].contents;
  if (Buffer.from(code).includes('wp.element.')) handles.add('wp-element');
  // Sorted: esbuild loads modules in parallel, so the order they were seen varies.
  return { file: hashed('js', name, 'js', code), code, dependencies: [...handles].sort() };
}

function buildStyle(name) {
  const { code } = bundle({
    filename: resolve(root, `resources/styles/${name}.css`),
    minify: true,
    targets: browserslistToTargets(browsers),
    // Flatten nesting like the previous PostCSS build did. Native nesting
    // wraps selector lists in :is(), which changes specificity.
    include: Features.Nesting,
  });
  return { file: hashed('css', name, 'css', code), code };
}

const manifest = {};
const entrypoints = {};
const files = [];

for (const name of entries) {
  const script = await buildScript(name);
  const style = buildStyle(name);
  files.push(script, style);
  manifest[`${name}.js`] = script.file;
  manifest[`${name}.css`] = style.file;
  entrypoints[name] = { js: [script.file], css: [style.file], dependencies: script.dependencies };
}
manifest['entrypoints.json'] = 'entrypoints.json';

// Pages cached before a theme update (Cloudflare keeps the homepage for
// hours) still request the previous hashed files, and a 404 there means no
// slider script. Keep the files of the last KEEP_GENERATIONS builds, newest
// first in public/previous-assets.json, so those pages work until their
// cache expires. A rebuild without source changes leaves the list as it is.
//
// The previous build is read from the last commit (git HEAD), not the
// working tree, so rebuilding several times before committing doesn't count
// each attempt as a generation and push the released files out. Without
// git (e.g. a copy without .git) the files in public/ are used.
const KEEP_GENERATIONS = 5;
const previousList = 'previous-assets.json';
const committedFile = (path) => {
  try {
    return execFileSync('git', ['show', `HEAD:public/${path}`], { cwd: root, stdio: ['ignore', 'pipe', 'ignore'] });
  } catch {
    return existsSync(resolve(publicDir, path)) ? readFileSync(resolve(publicDir, path)) : null;
  }
};
const committedJson = (path, fallback) => {
  try {
    return JSON.parse(committedFile(path).toString('utf8'));
  } catch {
    return fallback;
  }
};
const assetFiles = (map) => Object.entries(map).filter(([key]) => key !== 'entrypoints.json').map(([, file]) => file).sort();
const current = assetFiles(manifest);
const committed = assetFiles(committedJson('manifest.json', {}));
let generations = committedJson(previousList, []);
if (committed.length && committed.join() !== current.join()) generations.unshift(committed);
// Kept files come from the commit, so an earlier local build that deleted
// one from public/ can't lose it.
const found = new Map();
generations = generations
  .map((list) => list.filter((file) => {
    if (current.includes(file)) return false;
    if (!found.has(file)) found.set(file, committedFile(file));
    return Boolean(found.get(file));
  }))
  .filter((list) => list.length)
  .slice(0, KEEP_GENERATIONS);
const keptContents = new Map(generations.flat().map((file) => [file, found.get(file)]));
const keep = new Set([...current, ...keptContents.keys()]);

// Only this script writes public/js and public/css.
for (const dir of ['js', 'css']) {
  mkdirSync(resolve(publicDir, dir), { recursive: true });
  for (const name of readdirSync(resolve(publicDir, dir))) {
    if (!keep.has(`${dir}/${name}`)) rmSync(resolve(publicDir, dir, name), { recursive: true, force: true });
  }
}
for (const [file, contents] of keptContents) writeFileSync(resolve(publicDir, file), contents);
for (const { file, code } of files) writeFileSync(resolve(publicDir, file), code);
writeFileSync(resolve(publicDir, 'manifest.json'), JSON.stringify(manifest, null, 2));
writeFileSync(resolve(publicDir, 'entrypoints.json'), JSON.stringify(entrypoints, null, 2));
writeFileSync(resolve(publicDir, previousList), JSON.stringify(generations, null, 2));

for (const { file, code } of files) console.log(`public/${file}  ${code.length} B`);
