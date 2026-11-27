import { readFileSync, statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const manifest = JSON.parse(readFileSync(resolve(root, 'public/manifest.json'), 'utf8'));
const built = (key) => typeof manifest[key] === 'string' ? `public/${manifest[key]}` : '';

const budgets = [
  ['Frontend CSS', built('app.css'), 22_000],
  ['Frontend JS', built('app.js'), 4_500],
  // Includes the Latest Post Slider styles for the editor preview, with the
  // card layout ("Kortelių išdėstymas", 2.1 KB, 3.41.0) as rules of its own
  // so sliders without it keep their exact styles.
  ['Editor CSS', built('editor.css'), 11_000],
  // Block editor only (never on the site). Includes the theme's post field
  // panels (resources/scripts/field-panels.js, ~7 KB) since they replaced the
  // Carbon Fields boxes, and the Author panel (resources/scripts/author-panel.js,
  // ~5.5 KB, 3.36.0).
  ['Editor JS', built('editor.js'), 36_000],
  ['ALPS denim CSS', 'app/local/alps/css/main-denim.css', 215_000],
  // Trimmed to the classes the site uses (scripts/trim-alps-css.mjs); this
  // is the copy the theme links.
  ['ALPS denim CSS (trimmed, loaded)', 'app/local/alps/css/main-denim.used.css', 130_000],
  // Readable source; raised from 34,000 in 3.42.4 for the Wide/Full width
  // rules beside a sidebar and the links on dark Covers (33,738 -> 34,214 B).
  ['Site overrides CSS', 'assets/css/site-overrides.css', 34_500],
  ['Site overrides CSS (minified, loaded)', 'assets/css/site-overrides.min.css', 15_000],
  ['Responsive fixes CSS', 'assets/css/responsive-zoom-fixes.css', 8_000],
  ['Responsive fixes CSS (minified, embedded)', 'assets/css/responsive-zoom-fixes.min.css', 4_000],
  // Embedded on every page after the responsive fixes: logo clear space,
  // Denim bands and the front page's section headings.
  ['Identity CSS', 'assets/css/brand-identity.css', 5_000],
  ['Identity CSS (minified, embedded)', 'assets/css/brand-identity.min.css', 2_500],
  ['Search CSS', 'assets/css/adv-search.css', 7_500],
  ['Search CSS (minified, embedded)', 'assets/css/adv-search.min.css', 5_000],
  ['Sabbath timer CSS', 'assets/css/sabbath-timer.css', 8_000],
  ['Sabbath timer CSS (minified, embedded on the front page)', 'assets/css/sabbath-timer.min.css', 6_000],
  ['Search JS', 'assets/js/adv-search.js', 11_000],
  // "Visa darau nauja" band (3.42.0): embedded on the front page only while
  // switched on in the theme settings, with the two Figtree subsets and the
  // band's photos (the largest files, the 2x desktop crops, are listed).
  ['Campaign band CSS', 'assets/css/campaign-band.css', 9_500],
  ['Campaign band CSS (minified, embedded)', 'assets/css/campaign-band.min.css', 6_000],
  ['Campaign band font: Figtree ExtraBold subset', 'assets/fonts/figtree/Figtree-ExtraBold.woff2', 12_000],
  ['Campaign band font: Figtree Medium subset', 'assets/fonts/figtree/Figtree-Medium.woff2', 12_000],
  ['Campaign band image: old, 2x', 'assets/images/visa-darau-nauja/old-940.webp', 120_000],
  ['Campaign band image: new, 2x', 'assets/images/visa-darau-nauja/new-1528.webp', 120_000],
  ['Campaign band image: new, phone 2x', 'assets/images/visa-darau-nauja/new-phone-780.webp', 120_000],
  // Readable source; it calculates the sunsets that were embedded as about
  // 16 KB of JSON and places the city list in the top layer. The page embeds
  // the minified copy.
  ['Sabbath timer JS', 'assets/js/sabbath-timer.js', 17_000],
  ['Sabbath timer JS (minified, embedded)', 'assets/js/sabbath-timer.min.js', 8_500],
  // Embedded on pages with a Latest Post Slider or card scroller whose
  // deferred images wait for it.
  ['Deferred images JS (minified, embedded)', 'assets/js/slider-images.min.js', 2_600],
  ['Card scroller CSS', 'assets/css/card-scroller.css', 10_000],
  ['Card scroller CSS (minified, loaded)', 'assets/css/card-scroller.min.css', 8_500],
  ['Card scroller JS', 'assets/js/card-scroller.js', 4_500],
  // Embedded only on pages whose header shows an image (page-header.blade.php).
  ['Page header image CSS', 'assets/css/page-hero.css', 3_500],
  ['Page header image CSS (minified, embedded)', 'assets/css/page-hero.min.css', 2_100],
  // Embedded on every page (App\SidebarPosition): the left-sidebar order and
  // the page header lined up with the article.
  ['Sidebar layout CSS (minified, embedded)', 'assets/css/sidebar-layout.min.css', 2_300],
  // Embedded only when Sidebar position is Left side column (incl. dark-theme colours
  // and the header title over the article text). Raised from 2,400 for
  // lightningcss 1.33, which writes spaces inside calc() (2,389 -> 2,407 B).
  // Raised to 2,800 in 3.42.4 for the Wide/Full width block widths beside the
  // column (2,407 -> 2,637 B).
  ['Side column CSS (minified, embedded)', 'assets/css/sidebar-rail.min.css', 2_800],
  // Embedded after it when the side column is scaled like the Sabbath column.
  ['Scaled side column CSS (minified, embedded)', 'assets/css/sidebar-rail-scaled.min.css', 400],
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
