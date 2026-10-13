// Browser test of the block editor field panels (app/Fields/PostPanels.php,
// resources/scripts/field-panels.js) on a Playground site prepared by e2e.py:
//   python3 tests/fields/e2e.py editor-panels   (or: node tests/fields/editor-panels.mjs <url>)
// Needs Playwright (global install: NODE_PATH="$(npm root -g)").
import {createRequire} from 'node:module';

const require = createRequire(import.meta.url);
const {chromium} = require('playwright');
const base = process.argv[2];
let failures = 0;
let checks = 0;
const check = (label, ok, detail = '') => {
  checks++;
  console.log(`${ok ? 'ok   ' : 'FAIL '} ${label}${ok ? '' : ' ' + detail}`);
  if (!ok) failures++;
};

const browser = await chromium.launch();
const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

await page.goto(`${base}/wp-login.php`);
await page.fill('#user_login', 'admin');
await page.fill('#user_pass', 'password');
await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

const api = async (path, options = {}) => page.evaluate(async ([path, options]) => {
  const response = await window.wp.apiFetch({path, ...options});
  return response;
}, [path, options]);
const idOf = async (type, slug) => (await api(`/wp/v2/${type}?slug=${slug}&_fields=id`))[0].id;
const meta = async (id) => api(`/alps-test/v1/meta/${id}`);

async function openEditor(url) {
  await page.goto(url);
  await page.waitForFunction(() => window.wp?.data?.select('core/editor')?.getCurrentPostId());
  await page.evaluate(() => {
    const prefs = window.wp.data.dispatch('core/preferences');
    prefs.set('core/edit-post', 'welcomeGuide', false);
    prefs.set('core', 'welcomeGuide', false);
    window.wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/document');
  });
  await page.waitForSelector('.alps-field-panels', {timeout: 30000});
  // The welcome guide can still open a moment after loading; close it.
  const guide = page.locator('.components-modal__frame', {hasText: 'Welcome to the editor'});
  if (await guide.waitFor({timeout: 4000}).then(() => true, () => false)) {
    await guide.getByRole('button', {name: 'Close'}).click();
    await guide.waitFor({state: 'detached'});
  }
}
const panel = (title) => page.locator('.components-panel__body', {has: page.locator(`button:has-text("${title}")`)});
async function openPanel(title) {
  const body = panel(title);
  const toggle = body.locator('button.components-panel__body-toggle').first();
  if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
    await toggle.click();
  }
  return body;
}
async function save() {
  await page.evaluate(() => window.wp.data.dispatch('core/editor').savePost());
  await page.waitForFunction(() => {
    const editor = window.wp.data.select('core/editor');
    return !editor.isSavingPost() && !editor.isDirtyPostDirty?.() && !editor.isEditedPostDirty();
  }, null, {timeout: 30000});
}

// A page with a hero.
const heroId = await idOf('pages', 'hero-default');
await openEditor(`${base}/wp-admin/post.php?post=${heroId}&action=edit`);
const titles = await page.locator('.alps-field-panels .components-panel__body-title').allTextContents();
for (const title of ['ALPS: Header and Page Builder Options', 'ALPS: Header Banner', 'ALPS: Hero', 'ALPS: Entry Sidebar / Featured Image', 'ALPS: Related Pages & Posts']) {
  check(`page shows panel "${title}"`, titles.some((t) => t.includes(title)), titles.join(' | '));
}
check('no Carbon Fields boxes in the block editor', await page.locator('[id^="carbon_fields_container"]').count() === 0);

let hero = await openPanel('ALPS: Hero');
check('stored kicker shown', await hero.getByLabel('Hero Kicker').inputValue() === 'Hero kicker');
check('stored style shown', await hero.getByLabel('Half screen image with text overlay (Min/Max Images: 1)').isChecked());
check('conditional: carousel hidden for this style', await hero.getByText('Hero Carousel').count() === 0);

// Switch to a carousel, add two slides, pick an image for the first.
await hero.getByLabel('Half screen image gallery with text overlay (Max Images: 9)').check();
check('conditional: carousel shown', await hero.getByText('Hero Carousel').count() > 0);
check('conditional: single-image fields hidden', await hero.getByLabel('Hero Kicker').count() === 0);
await hero.getByRole('button', {name: 'Add slide'}).click();
await hero.getByRole('button', {name: 'Add slide'}).click();
const headings = hero.getByLabel('Hero Title');
await headings.nth(0).fill('First slide from the editor');
await headings.nth(1).fill('Second slide from the editor');
await hero.getByRole('button', {name: 'Select image'}).first().click();
await page.locator('.media-modal #menu-item-browse').click();
await page.locator('.media-modal .attachments .attachment').first().click();
await page.locator('.media-modal .media-button-select').click();
check('image preview after picking', await hero.locator('.alps-field-panels__image img').first().waitFor({timeout: 15000}).then(() => true, () => false));
// Move the second slide up.
await hero.locator('.alps-field-panels__row').nth(1).getByRole('button', {name: 'Move up'}).click();
check('move up reorders', await headings.nth(0).inputValue() === 'Second slide from the editor');

// Related pages: custom list through search.
const related = await openPanel('ALPS: Related Pages & Posts');
await related.getByLabel('Show custom pages').check();
await related.getByLabel('Search pages and posts').fill('Roll');
await related.locator('.alps-field-panels__result').first().waitFor({timeout: 30000});
const decoded = await related.locator('.alps-field-panels__result').first().innerText();
check('result title shown as plain text', decoded === 'Rock & Roll’s page (page)', decoded);
await related.getByLabel('Search pages and posts').fill('Plain');
await related.locator('.alps-field-panels__result').first().waitFor({timeout: 30000});
const picked = await related.locator('.alps-field-panels__result').first().innerText();
await related.locator('.alps-field-panels__result').first().click();
check('picked entry listed', await related.locator('.alps-field-panels__post').count() === 1, picked);

await save();
let rows = await meta(heroId);
check('saved: hero type', rows._hero_type?.[0] === 'carousel', JSON.stringify(rows._hero_type));
check('saved: slide rows in Carbon Fields format', rows['_hero_carousel|slide_heading|0|0|value']?.[0] === 'Second slide from the editor'
  && rows['_hero_carousel|slide_heading|1|0|value']?.[0] === 'First slide from the editor' && rows['_hero_carousel|||1|value']?.[0] === '_');
check('saved: slide image ID', /^\d+$/.test(rows['_hero_carousel|slide_image|1|0|value']?.[0] || ''));
check('saved: related custom', rows._related?.[0] === 'related_custom' && rows['_related_custom_value|||0|type']?.[0] === 'post');
check('untouched kicker kept', rows._hero_kicker?.[0] === 'Hero kicker');

// Reload: values come back.
await openEditor(`${base}/wp-admin/post.php?post=${heroId}&action=edit`);
hero = await openPanel('ALPS: Hero');
check('after reload: carousel style', await hero.getByLabel('Half screen image gallery with text overlay (Max Images: 9)').isChecked());
check('after reload: slides', await hero.getByLabel('Hero Title').nth(0).inputValue() === 'Second slide from the editor');
const front = await context.newPage();
await front.goto(`${base}/?page_id=${heroId}`);
const html = await front.content();
check('front end: slide headings', html.includes('Second slide from the editor') && html.includes('First slide from the editor'));
check('front end: related list', html.includes(picked.replace(/ \((page|post)\)$/, '')));

// A post has no Related panel.
const postId = await idOf('posts', 'post-options');
await openEditor(`${base}/wp-admin/post.php?post=${postId}&action=edit`);
const postTitles = await page.locator('.alps-field-panels .components-panel__body-title').allTextContents();
check('post: no Related panel', !postTitles.some((t) => t.includes('Related Pages')) && postTitles.length === 4, postTitles.join(' | '));
const sidebar = await openPanel('ALPS: Entry Sidebar / Featured Image');
check('post: stored checkbox shown', await sidebar.getByLabel('Hide Sidebar').isChecked());
await sidebar.getByLabel('Hide Sidebar').uncheck();
await save();
check('post: unchecking stores ""', (await meta(postId))._hide_sidebar?.[0] === '');

// A new page saved without opening the panels gets the taller banner, as before.
await openEditor(`${base}/wp-admin/post-new.php?post_type=page`);
const layout = await openPanel('ALPS: Header and Page Builder Options');
check('new page shows the taller banner', await layout.getByLabel('Show taller fitted image with a blurred title background.').isChecked());
await page.evaluate(() => window.wp.data.dispatch('core/editor').editPost({title: 'New page from the test'}));
await save();
const newId = await page.evaluate(() => window.wp.data.select('core/editor').getCurrentPostId());
check('new page stores the taller banner', (await meta(newId))._featured_image_hero_layout?.[0] === 'page-header');

// The classic media screen has no theme fields (Carbon Fields is gone) and no errors.
const imageId = (await api('/wp/v2/media?per_page=1&_fields=id'))[0].id;
const media = await page.goto(`${base}/wp-admin/post.php?post=${imageId}&action=edit`);
check('attachment screen loads without theme boxes', media.status() === 200 && await page.locator('[id^="carbon_fields_container"]').count() === 0
  && await page.locator('#post-body').count() === 1);

// Narrow editor sidebar: fields fit.
await page.setViewportSize({width: 1024, height: 900});
await openEditor(`${base}/wp-admin/post.php?post=${heroId}&action=edit`);
await openPanel('ALPS: Hero');
await page.locator('.alps-field-panels').first().scrollIntoViewIfNeeded();
await page.screenshot({path: process.argv[3] || 'editor-panels.png'});

check('no JavaScript errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(`editor-panels: ${checks} checks, ${failures} failures`);
process.exit(failures ? 1 : 0);
