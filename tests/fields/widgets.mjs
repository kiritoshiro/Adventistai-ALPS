// Browser test of the theme's widgets (app/Widgets/) on Appearance → Widgets,
// on a Playground site prepared by e2e.py:
//   python3 tests/fields/e2e.py widgets   (or: node tests/fields/widgets.mjs <url>)
// The seed saved the widgets the way Carbon Fields did.
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
const context = await browser.newContext({viewport: {width: 1280, height: 1000}});
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

await page.goto(`${base}/wp-login.php`);
await page.fill('#user_login', 'admin');
await page.fill('#user_pass', 'password');
await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
// The classic widgets screen has no wp.apiFetch: call the REST API with a nonce.
const nonce = await (await page.request.get(`${base}/wp-admin/admin-ajax.php?action=rest-nonce`)).text();
const api = async (path) => {
  const [route, query] = path.split('?');
  const response = await page.request.get(`${base}/index.php?rest_route=${route}${query ? '&' + query : ''}`, {headers: {'X-WP-Nonce': nonce}});
  const body = await response.text();
  try {
    return JSON.parse(body);
  } catch (e) {
    throw new Error(`REST ${path}: HTTP ${response.status()} ${body.slice(0, 200)}`);
  }
};

// Sidebars other than the first start collapsed.
async function openSidebar(id) {
  const holder = page.locator('.widgets-holder-wrap', {has: page.locator(`#${id}`)});
  if ((await holder.getAttribute('class')).includes('closed')) {
    await holder.locator('.sidebar-name').first().click();
  }
  await page.locator(`#${id}`).waitFor({state: 'visible'});
}
await page.goto(`${base}/wp-admin/widgets.php`);
await openSidebar('sidebar-posts');
const posts = page.locator('#sidebar-posts');
const names = await posts.locator('.widget .widget-title h3').allTextContents();
check('seeded widgets listed in Posts Sidebar', ['ALPS - Related Stories', 'ALPS - Author Box', 'ALPS - Text with Link'].every((n) => names.some((t) => t.includes(n))), names.join(' | '));

// Existing Text with Link: stored values shown, edit, save.
const text = posts.locator('.widget[id*="carbon_fields_alps_widget_text_with_link-2"]');
await text.locator('.widget-action').click();
check('stored title shown', await text.locator('input[name$="[text_link_title]"]').inputValue() === 'Widget title');
check('stored text shown', await text.locator('textarea[name$="[text_link_content]"]').inputValue() === 'Widget text');
await text.locator('input[name$="[text_link_title]"]').fill('Edited widget title');
await text.locator('.widget-control-save').click();
await text.locator('.spinner.is-active').waitFor({state: 'detached'}).catch(() => {});
await page.waitForFunction(() => !document.querySelector('#sidebar-posts .spinner.is-active'), null, {timeout: 30000});

// Add a Post Feed to the Posts Sidebar through the chooser.
const feedTitle = page.locator('#widget-list .widget', {hasText: 'ALPS - Post Feed'}).locator('.widget-title');
await feedTitle.click();
await page.locator('.widgets-chooser-sidebars li', {hasText: 'Posts Sidebar'}).click();
// WordPress saves a new widget at once and then replaces its form: wait for that.
const firstSave = page.waitForResponse((r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('action=save-widget'));
await page.locator('.widgets-chooser-add').click();
await firstSave;
await page.waitForFunction(() => !document.querySelector('#sidebar-posts .spinner.is-active'), null, {timeout: 30000});
const feed = posts.locator('.widget[id*="carbon_fields_alps_widget_post_feed"]').last();
// WordPress opens a newly added widget; open it by hand only if it did not.
const titleInput = feed.locator('input[name$="[post_feed_title]"]');
if (!(await titleInput.waitFor({state: 'visible', timeout: 5000}).then(() => true, () => false))) {
  await feed.locator('.widget-action').click();
  await titleInput.waitFor({state: 'visible'});
}
await page.waitForTimeout(500); // the opening animation
const categories = await feed.locator('select[name$="[post_feed_category]"] option').allTextContents();
check('category select lists categories', categories.includes('Render news'), categories.join(' | '));
await feed.locator('select[name$="[post_feed_category]"]').selectOption({label: 'Render news'});
await feed.locator('input[name$="[post_feed_title]"]').fill('Added feed');
await feed.locator('input[name$="[post_feed_count]"]').fill('1');
await feed.locator('input[name$="[post_feed_featured]"]').check();
await feed.locator('.widget-control-save').click();
await page.waitForFunction(() => !document.querySelector('#sidebar-posts .spinner.is-active'), null, {timeout: 30000});
const feedId = (await feed.getAttribute('id')).match(/post_feed-(\d+)/)[1];

// Stored as Carbon Fields stored them.
const textOption = await api('/alps-test/v1/option/widget_carbon_fields_alps_widget_text_with_link');
check('edited instance keeps the Carbon Fields keys', textOption['2']?._text_link_title === 'Edited widget title' && textOption['2']?._text_link_url === 'https://example.org/widget', JSON.stringify(textOption['2']));
const feedOption = await api('/alps-test/v1/option/widget_carbon_fields_alps_widget_post_feed');
const added = feedOption[feedId];
check('new instance in the Carbon Fields format', added?._post_feed_title === 'Added feed' && added?._post_feed_featured === 'true' && added?._post_feed_layout === '' && Number.isInteger(added?._post_feed_category), JSON.stringify(added));
const sidebars = await api('/alps-test/v1/option/sidebars_widgets');
check('sidebar keeps the old widget IDs', sidebars['sidebar-posts'].includes('carbon_fields_alps_widget_text_with_link-2') && sidebars['sidebar-posts'].includes(`carbon_fields_alps_widget_post_feed-${feedId}`), JSON.stringify(sidebars['sidebar-posts']));

// Reload: values come back.
await page.goto(`${base}/wp-admin/widgets.php`);
await openSidebar('sidebar-posts');
const reloaded = page.locator(`#sidebar-posts .widget[id*="carbon_fields_alps_widget_post_feed-${feedId}"]`);
await reloaded.locator('.widget-action').click();
check('after reload: feed title', await reloaded.locator('input[name$="[post_feed_title]"]').inputValue() === 'Added feed');
check('after reload: feed checkbox', await reloaded.locator('input[name$="[post_feed_featured]"]').isChecked());
check('after reload: feed category', (await reloaded.locator('select[name$="[post_feed_category]"] option:checked').innerText()) === 'Render news');

// Front end: a post shows the edited and the added widget.
const postId = (await api('/wp/v2/posts?slug=post-plain&_fields=id'))[0].id;
const front = await context.newPage();
await front.goto(`${base}/?p=${postId}`);
const html = await front.content();
check('front end: edited widget title', html.includes('Edited widget title'));
check('front end: added feed title', html.includes('Added feed'));
check('front end: widget class kept', html.includes('carbon_fields_alps_widget_post_feed'));

check('no JavaScript errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(`widgets: ${checks} checks, ${failures} failures`);
process.exit(failures ? 1 : 0);
