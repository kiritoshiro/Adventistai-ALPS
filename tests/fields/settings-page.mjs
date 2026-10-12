// Browser test of Appearance → ALPS Theme Settings (app/Fields/SettingsPage.php)
// on a Playground site prepared by settings-e2e.py. Usage:
//   node tests/fields/settings-page.mjs http://127.0.0.1:9497
// Needs Playwright (global install: NODE_PATH="$(npm root -g)").
import {createRequire} from 'node:module';

const require = createRequire(import.meta.url);
const {chromium} = require('playwright');
const base = process.argv[2];
const page_url = `${base}/wp-admin/themes.php?page=alps-theme-options`;
let failures = 0;
let checks = 0;
const check = (label, ok, detail = '') => {
  checks++;
  if (ok) {
    console.log(`ok    ${label}`);
  } else {
    failures++;
    console.log(`FAIL  ${label} ${detail}`);
  }
};

const browser = await chromium.launch();
const errors = [];

async function login(context, user, password, track = true) {
  const page = await context.newPage();
  track && page.on('pageerror', (e) => errors.push(e.message));
  track && page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
  track && page.on('response', (r) => r.status() >= 400 && errors.push(`HTTP ${r.status()} ${r.url()}`));
  await page.goto(`${base}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', password);
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  return page;
}

const admin = await browser.newContext({viewport: {width: 1280, height: 900}});
const page = await login(admin, 'admin', 'password');
await page.goto(page_url);
await page.screenshot({path: (process.argv[3] || 'settings-page-phone.png').replace('phone', 'desktop'), fullPage: true});

// Tabs.
const tabs = await page.locator('.alps-settings__tabs .nav-tab').allTextContents();
check('six tabs', tabs.join('|') === 'GLOBAL|POSTS OPTIONS|SABBATH COLUMN|FOOTER CONTENT|IMAGES|CACHE', tabs.join('|'));
check('only the first panel shows', await page.locator('.alps-settings__panel:visible').count() === 1);
await page.click('.nav-tab[data-tab="posts"]');
check('tab switch shows Posts options', await page.locator('#alps-posts_page_title').isVisible());
check('tab switch hides Global', !(await page.locator('#alps-theme_color').isVisible()));
check('stored values shown: posts page title', await page.inputValue('#alps-posts_page_title') === 'Render posts title');

// GLOBAL
await page.click('.nav-tab[data-tab="global"]');
check('stored values shown: theme colour', await page.inputValue('#alps-theme_color') === 'denim');
await page.selectOption('#alps-theme_color', 'forest');
await page.check('#alps-dark_theme');
await page.fill('#alps-site_branding_statement', 'Statement from the test');
// Logo: pick the second library image in the media modal.
await page.locator('#alps-logo').locator('xpath=..').locator('[data-image-choose]').click();
await page.locator('.media-modal #menu-item-browse').click();
await page.waitForSelector('.media-modal .attachments .attachment', {timeout: 60000}).catch(async (e) => {
  await page.screenshot({path: 'media-modal-debug.png'});
  console.log('media modal open:', await page.locator('.media-modal').count(), 'errors:', errors.join(' | '));
  throw e;
});
await page.locator('.media-modal .attachments .attachment').nth(1).click();
await page.click('.media-modal .media-button-select');
const logo_id = await page.inputValue('#alps-logo');
check('media modal sets the logo ID', /^\d+$/.test(logo_id), logo_id);
check('logo preview shown', await page.locator('[data-image] >> nth=0 >> img').count() === 1);

// POSTS OPTIONS
await page.click('.nav-tab[data-tab="posts"]');
await page.fill('#alps-posts_page_title', 'Home title from the test');
await page.uncheck('#alps-posts_grid');

// SABBATH COLUMN: the screens choice only shows while the column is hidden.
await page.click('.nav-tab[data-tab="sabbath"]');
const screens = page.locator('[data-shown-with="alps-sabbath_hide"]');
check('screens choice hidden while the column shows', !(await screens.isVisible()));
await page.check('#alps-sabbath_hide');
check('screens choice shown once hidden', await screens.isVisible());
await page.check('input[name="alps[sabbath_hide_screens]"][value="hide-sabbath--until-large"]');

// FOOTER CONTENT
await page.click('.nav-tab[data-tab="footer"]');
await page.fill('#alps-footer_copyright', 'Copyright from the test');
await page.fill('#alps-footer_description-0-footer_description_en', 'Footer EN from the test');
await page.click('[data-row-add="footer_description"]');
check('add entry adds a row with defaults', await page.locator('[data-rows="footer_description"] [data-row]').count() === 2
  && (await page.inputValue('#alps-footer_description-1-footer_description_de')).length > 0);
await page.locator('[data-rows="footer_description"] [data-row] >> nth=1 >> [data-row-remove]').click();
check('remove entry removes it', await page.locator('[data-rows="footer_description"] [data-row]').count() === 1);
await page.fill('#alps-footer_address-0-footer_address_city', 'Vilnius');
await page.check('input[name="alps[footer_logo_type]"][value="circle"]');
await page.locator('#alps-footer_logo_icon').locator('xpath=..').locator('[data-image-remove]').click();
check('image remove clears the ID', await page.inputValue('#alps-footer_logo_icon') === '');

// IMAGES
await page.click('.nav-tab[data-tab="images"]');
await page.fill('#alps-alps_image_avif_quality', '60');

// Save from the Images tab.
await Promise.all([page.waitForNavigation(), page.click('#submit')]);
check('saved notice', await page.locator('.notice-success').innerText().then((t) => t.includes('Settings saved.')).catch(() => false));
check('stays on the Images tab', new URL(page.url()).searchParams.get('tab') === 'images' && await page.locator('#alps-alps_image_avif_quality').isVisible());

// Reload and read everything back.
await page.goto(page_url);
const values = {
  theme_color: await page.inputValue('#alps-theme_color'),
  dark_theme: await page.isChecked('#alps-dark_theme'),
  site_branding_statement: await page.inputValue('#alps-site_branding_statement'),
  logo: await page.inputValue('#alps-logo'),
  posts_page_title: await page.inputValue('#alps-posts_page_title'),
  posts_grid: await page.isChecked('#alps-posts_grid'),
  sabbath_hide: await page.isChecked('#alps-sabbath_hide'),
  screens: await page.isChecked('input[name="alps[sabbath_hide_screens]"][value="hide-sabbath--until-large"]'),
  footer_copyright: await page.inputValue('#alps-footer_copyright'),
  footer_en: await page.inputValue('#alps-footer_description-0-footer_description_en'),
  footer_rows: await page.locator('[data-rows="footer_description"] [data-row]').count(),
  city: await page.inputValue('#alps-footer_address-0-footer_address_city'),
  circle: await page.isChecked('input[name="alps[footer_logo_type]"][value="circle"]'),
  footer_logo_icon: await page.inputValue('#alps-footer_logo_icon'),
  avif: await page.inputValue('#alps-alps_image_avif_quality'),
};
const expected = {
  theme_color: 'forest', dark_theme: true, site_branding_statement: 'Statement from the test', logo: logo_id,
  posts_page_title: 'Home title from the test', posts_grid: false, sabbath_hide: true, screens: true,
  footer_copyright: 'Copyright from the test', footer_en: 'Footer EN from the test', footer_rows: 1,
  city: 'Vilnius', circle: true, footer_logo_icon: '', avif: '60',
};
for (const [key, value] of Object.entries(expected)) {
  check(`after reload: ${key}`, values[key] === value, `got ${JSON.stringify(values[key])}`);
}

// Front end.
const front = await admin.newPage();
await front.goto(`${base}/?page_id=19`);
const html = await front.content();
check('front end: copyright', html.includes('Copyright from the test'));
check('front end: footer description', html.includes('Footer EN from the test'));
check('front end: address city', html.includes('Vilnius'));
check('front end: forest stylesheet', html.includes('main-forest'));
check('front end: forest palette', await front.evaluate(() => getComputedStyle(document.body).getPropertyValue('--wp--preset--color--darker').trim()) === '#101a00');
check('front end: sabbath column hidden until large', html.includes('hide-sabbath--until-large'));

// Saving without a valid nonce is refused and changes nothing.
const forged = await page.request.post(`${base}/wp-admin/admin-post.php`, {
  form: {action: 'alps_save_theme_settings', _wpnonce: 'bad', 'alps[footer_copyright]': 'Forged'},
  maxRedirects: 0,
});
check('bad nonce refused', forged.status() >= 400, `HTTP ${forged.status()}`);
await page.goto(page_url);
check('bad nonce changed nothing', await page.inputValue('#alps-footer_copyright') === 'Copyright from the test');

// An editor cannot open or save the page.
const editor_context = await browser.newContext();
// Its 403 responses are expected, so they are not counted as errors.
const editor = await login(editor_context, 'fields-editor', 'fields-editor-test', false);
const editor_page = await editor.goto(page_url);
check('editor cannot open the page', editor_page.status() >= 400 || (await editor.content()).includes('not allowed'), `HTTP ${editor_page.status()}`);
const editor_nonce_page = await editor.request.post(`${base}/wp-admin/admin-post.php`, {
  form: {action: 'alps_save_theme_settings', 'alps[footer_copyright]': 'From editor'},
  maxRedirects: 0,
});
check('editor save refused', editor_nonce_page.status() >= 400, `HTTP ${editor_nonce_page.status()}`);

// Without JavaScript every tab shows and the form still saves.
const nojs = await browser.newContext({javaScriptEnabled: false});
const nojs_page = await login(nojs, 'admin', 'password');
await nojs_page.goto(page_url);
check('no JS: all panels visible', await nojs_page.locator('.alps-settings__panel:visible').count() === 6);
await nojs_page.fill('#alps-footer_copyright', 'Saved without JS');
await Promise.all([nojs_page.waitForNavigation(), nojs_page.click('#submit')]);
await nojs_page.goto(page_url);
check('no JS: saved', await nojs_page.inputValue('#alps-footer_copyright') === 'Saved without JS');
check('no JS: other values kept', await nojs_page.inputValue('#alps-theme_color') === 'forest' && await nojs_page.isChecked('#alps-dark_theme'));

// Narrow screen: no horizontal scroll.
await page.setViewportSize({width: 390, height: 800});
await page.goto(page_url);
check('phone width: no horizontal scroll', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
await page.screenshot({path: process.argv[3] || 'settings-page-phone.png', fullPage: false});

check('no JavaScript errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(`settings-page: ${checks} checks, ${failures} failures`);
process.exit(failures ? 1 : 0);
