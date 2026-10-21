// Browser test of author profiles (App\Authors) on a Playground site prepared
// by e2e.py (accounts, posts, migration done, profiles on, Yoast SEO active):
//   python3 tests/authors/e2e.py   (or: node tests/authors/e2e.mjs <url> <seed.json> [screenshot])
// Needs Playwright (global install: NODE_PATH="$(npm root -g)").
import {createRequire} from 'node:module';
import {readFileSync} from 'node:fs';

const require = createRequire(import.meta.url);
const {chromium, request} = require('playwright');
const [base, seedFile, shot] = process.argv.slice(2);
const seed = JSON.parse(readFileSync(seedFile, 'utf8'));
let failures = 0;
let checks = 0;
const check = (label, ok, detail = '') => {
  checks++;
  console.log(`${ok ? 'ok   ' : 'FAIL '} ${label}${ok ? '' : ' ' + String(detail).slice(0, 600)}`);
  if (!ok) failures++;
};
const path = (url) => new URL(url, base).pathname;
// Logins that must never reach visitors ("admin" is part of wp-admin URLs; its archive URL is checked instead).
const logins = seed.logins.filter((login) => login !== 'admin');
const leaks = (body) => [...logins.filter((login) => body.includes(login)), ...(/\/author\/[a-z0-9-]+/i.test(body) ? ['/author/<login>'] : [])];
console.log('migration: ' + seed.migration.join(' | '));

// 1. Visitors.
const visitor = await request.newContext({baseURL: base});
const fetchPage = async (url, options = {}) => {
  const response = await visitor.get(url, options);
  return {status: response.status(), headers: response.headers(), body: await response.text()};
};

let res = await fetchPage('/vaivos-irasas/');
check('post page 200', res.status === 200, res.status);
check('Yoast meta author is the author', res.body.includes('<meta name="author" content="Vaiva Paldavičienė"'), res.body.match(/<meta name="author"[^>]*>/)?.[0]);
const schema = JSON.parse(res.body.match(/<script type="application\/ld\+json" class="yoast-schema-graph">(.*?)<\/script>/s)?.[1] || '{}');
const graph = schema['@graph'] || [];
const article = graph.find((piece) => piece['@type'] === 'Article' || (Array.isArray(piece['@type']) && piece['@type'].includes('Article')));
const people = graph.filter((piece) => piece['@type'] === 'Person');
check('schema: Article author is the author', article?.author?.name === 'Vaiva Paldavičienė', JSON.stringify(article?.author));
check('schema: Person from the author with the author page', people.length === 1 && path(people[0].url) === '/autorius/vaiva-paldaviciene/' && people[0].description === 'Redaktorė ir vertėja.', JSON.stringify(people));
check('post page: no login names or /author/ links', leaks(res.body).length === 0, leaks(res.body));
check('no visible byline by default', !res.body.includes('alps-hero__byline'));

res = await fetchPage('/autorius/vaiva-paldaviciene/');
check('author page 200', res.status === 200, res.status);
check('author page: name, bio and posts', res.body.includes('Vaiva Paldavičienė') && res.body.includes('Redaktorė ir vertėja.') && res.body.includes('Vaivos įrašas'));
check('author page: no login names', leaks(res.body).length === 0, leaks(res.body));

res = await fetchPage('/author/vaiva-login/', {maxRedirects: 0});
check('/author/<login>/ → 301 to the author page', res.status === 301 && path(res.headers.location) === '/autorius/vaiva-paldaviciene/', `${res.status} ${res.headers.location}`);
res = await fetchPage('/author/admin/', {maxRedirects: 0});
check('/author/admin/ → 301 to "Adventistai"', res.status === 301 && path(res.headers.location) === '/autorius/adventistai/', `${res.status} ${res.headers.location}`);
res = await fetchPage(`/?author=${seed.users.mantas}`, {maxRedirects: 0});
check('?author=N → 301 to the author page', res.status === 301 && path(res.headers.location) === '/autorius/mantas/', `${res.status} ${res.headers.location}`);
res = await fetchPage(`/?author=${seed.users.tuscias}`, {maxRedirects: 0});
check('?author=N without an author → 404, no login', res.status === 404 && !res.body.includes('tuscias-login'), res.status);
res = await fetchPage('/author/vaiva-login/feed/', {maxRedirects: 0});
check('author feed → 301 to the author feed', res.status === 301 && path(res.headers.location).startsWith('/autorius/vaiva-paldaviciene/feed'), `${res.status} ${res.headers.location}`);

res = await fetchPage('/wp-json/wp/v2/users');
check('logged out: /wp/v2/users is 401', res.status === 401, res.status);
res = await fetchPage('/wp-json/wp/v2/users/1');
check('logged out: /wp/v2/users/1 is 401', res.status === 401, res.status);

res = await fetchPage('/feed/');
check('RSS dc:creator is the author', res.body.includes('<dc:creator><![CDATA[Vaiva Paldavičienė]]></dc:creator>'), res.body.match(/<dc:creator>.*?<\/dc:creator>/g)?.join(' '));
check('RSS: no login names', leaks(res.body).length === 0, leaks(res.body));

res = await fetchPage('/sitemap_index.xml');
check('Yoast sitemap index lists the authors', res.status === 200 && res.body.includes('alps_author-sitemap.xml'), res.status);
check('Yoast sitemap index has no user sitemap', !/[^_]author-sitemap\.xml/.test(res.body), res.body);
res = await fetchPage('/alps_author-sitemap.xml');
check('author sitemap lists author pages', res.body.includes('/autorius/vaiva-paldaviciene/') && leaks(res.body).length === 0, res.body.slice(0, 400));

res = await fetchPage(`/wp-json/oembed/1.0/embed?url=${encodeURIComponent(base + '/vaivos-irasas/')}`);
const oembed = JSON.parse(res.body);
check('oEmbed author is the author', oembed.author_name === 'Vaiva Paldavičienė' && path(oembed.author_url) === '/autorius/vaiva-paldaviciene/', res.body.slice(0, 300));
res = await fetchPage('/');
check('front page: no login names', leaks(res.body).length === 0, leaks(res.body));

// 2. Posts → Authors as administrator.
const browser = await chromium.launch();
const errors = [];
const login = async (user, password) => {
  const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
  const page = await context.newPage();
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => m.type() === 'error' && !/Failed to load resource/.test(m.text()) && errors.push(m.text()));
  await page.goto(`${base}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', password);
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  return page;
};
const page = await login('admin', 'password');
const api = async (p, path, options = {}) => p.evaluate(async ([path, options]) => window.wp.apiFetch({path, ...options}), [path, options]);

await page.goto(`${base}/wp-admin/edit.php?page=alps-authors`);
const rows = await page.locator('.alps-authors__table tbody tr .row-title').allTextContents();
for (const name of ['Adventistai', 'Vaiva Paldavičienė', 'Mantas', 'Jonas Jonaitis']) {
  check(`author list shows ${name}`, rows.includes(name), rows.join(' | '));
}
check('menu: Posts → Authors', await page.locator('#menu-posts a[href$="page=alps-authors"]').count() === 1,await page.locator('#menu-posts a').evaluateAll((links) => links.map((a) => a.getAttribute('href')).join(' ')));

await page.click('a.page-title-action:has-text("Add author")');
await page.fill('#alps-author-name', 'Petras Petraitis');
await page.fill('#alps-author-description', 'Svečias autorius.');
await Promise.all([page.waitForNavigation(), page.click('#submit')]);
check('add author', (await page.locator('.notice-success').textContent())?.includes('Author added.') && await page.locator('.row-title', {hasText: 'Petras Petraitis'}).count() === 1);

await Promise.all([page.waitForNavigation(), page.click('.row-title:has-text("Petras Petraitis")')]);
check('edit form has the bio', (await page.inputValue('#alps-author-description')) === 'Svečias autorius.');
await page.fill('#alps-author-name', 'Petras P. Petraitis');
await Promise.all([page.waitForNavigation(), page.click('#submit')]);
check('edit author', await page.locator('.row-title', {hasText: 'Petras P. Petraitis'}).waitFor({timeout: 10000}).then(() => true, () => false), await page.locator('.row-title').allTextContents());

const mantasRow = page.locator('tr', {has: page.locator('.row-title', {hasText: /^Mantas$/})});
await mantasRow.hover();
await mantasRow.locator('.submitdelete').click();
check('delete asks who takes the posts', await page.locator('#alps-author-reassign').waitFor({timeout: 10000}).then(() => true, () => false), page.url());
await page.selectOption('#alps-author-reassign', {label: 'Petras P. Petraitis (petras-petraitis)'});
await Promise.all([page.waitForNavigation(), page.click('#submit')]);
check('delete with reassignment', await page.locator('.row-title', {hasText: /^Mantas$/}).count() === 0);
res = await fetchPage('/manto-irasas/');
check('the deleted author\'s post shows the new author', res.body.includes('<meta name="author" content="Petras P. Petraitis"'), res.body.match(/<meta name="author"[^>]*>/)?.[0]);

await page.goto(`${base}/wp-admin/edit.php`);
check('post list: author filter', await page.locator('#alps-author-filter').count() === 1);
check('post list: Authors column', await page.locator('th#taxonomy-alps_author').count() === 1);

// 3. ALPS Theme Settings → AUTHORS: turn the byline on.
await page.goto(`${base}/wp-admin/themes.php?page=alps-theme-options&tab=authors`);
check('settings: profiles on', await page.isChecked('#alps-alps_authors_enabled'));
check('settings: default author is "Adventistai"', (await page.locator('#alps-alps_authors_default option:checked').textContent()) === 'Adventistai');
check('settings: hide user accounts on by default', await page.isChecked('#alps-alps_authors_hide_users'));
await page.check('#alps-alps_authors_byline');
await Promise.all([page.waitForNavigation(), page.click('#submit')]);
check('settings saved: byline on', await page.isChecked('#alps-alps_authors_byline'));

// 4. The editor's Author panel.
async function openEditor(p, url) {
  await p.goto(url);
  await p.waitForFunction(() => window.wp?.data?.select('core/editor')?.getCurrentPostId());
  await p.evaluate(() => {
    const prefs = window.wp.data.dispatch('core/preferences');
    prefs.set('core/edit-post', 'welcomeGuide', false);
    prefs.set('core', 'welcomeGuide', false);
    window.wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/document');
  });
  await p.waitForSelector('.alps-author-panel', {timeout: 30000});
  const guide = p.locator('.components-modal__frame', {hasText: 'Welcome to the editor'});
  if (await guide.waitFor({timeout: 4000}).then(() => true, () => false)) {
    await guide.getByRole('button', {name: 'Close'}).click();
    await guide.waitFor({state: 'detached'});
  }
  const toggle = p.locator('.alps-author-panel button.components-panel__body-toggle').first();
  if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
    await toggle.click();
  }
}
const chosen = (p) => p.locator('.alps-author-panel__chosen .alps-author-panel__name').allTextContents();
// Waits until the chosen authors' names have loaded and are these.
const waitChosen = (p, names) => p.waitForFunction((names) => JSON.stringify([...document.querySelectorAll('.alps-author-panel__chosen .alps-author-panel__name')].map((s) => s.textContent)) === JSON.stringify(names), names, {timeout: 15000}).then(() => true, () => false);

await openEditor(page, `${base}/wp-admin/post-new.php`);
check('new post starts with the account\'s author', await waitChosen(page, ['Adventistai']), await chosen(page));
await page.fill('.alps-author-panel input[type="text"]', 'Vaiva');
await page.click('.alps-author-panel__result:has-text("Vaiva Paldavičienė")');
check('choose a second author', await waitChosen(page, ['Adventistai', 'Vaiva Paldavičienė']), await chosen(page));
await page.locator('.alps-author-panel__chosen li').nth(1).getByRole('button', {name: 'Move up'}).click();
check('reorder', await waitChosen(page, ['Vaiva Paldavičienė', 'Adventistai']), await chosen(page));
await page.click('.alps-author-panel__new');
await page.fill('.alps-author-panel__new-form input[type="text"]', 'vaiva paldavičienė');
await page.click('.alps-author-panel__new-form button:has-text("Add author")');
await page.waitForSelector('.alps-author-panel__new-form .components-notice');
check('inline add: same name offers the existing author', (await page.locator('.alps-author-panel__new-form .components-notice').textContent()).includes('already exists'));
await page.fill('.alps-author-panel__new-form input[type="text"]', 'Naujas Autorius');
await page.fill('.alps-author-panel__new-form textarea', 'Trumpa biografija.');
await page.click('.alps-author-panel__new-form button:has-text("Add author")');
check('inline add creates and chooses the author', await waitChosen(page, ['Vaiva Paldavičienė', 'Adventistai', 'Naujas Autorius']), await chosen(page));
if (shot) {
  await page.locator('.alps-author-panel').screenshot({path: shot});
}
await page.evaluate(() => window.wp.data.dispatch('core/editor').editPost({title: 'E2E įrašas', status: 'publish'}));
await page.evaluate(() => window.wp.data.dispatch('core/editor').savePost());
await page.waitForFunction(() => !window.wp.data.select('core/editor').isSavingPost() && window.wp.data.select('core/editor').getCurrentPostAttribute('status') === 'publish', null, {timeout: 30000});
const newId = await page.evaluate(() => window.wp.data.select('core/editor').getCurrentPostId());
const saved = await api(page, `/wp/v2/posts/${newId}?context=edit&_fields=alps_author,link`);
const names = [];
for (const id of saved.alps_author) {
  names.push((await api(page, `/wp/v2/alps_author/${id}?_fields=name`)).name);
}
check('saved with the authors in order', JSON.stringify(names) === '["Vaiva Paldavičienė","Adventistai","Naujas Autorius"]', JSON.stringify(names));
res = await fetchPage(path(saved.link));
const byline = res.body.match(/<span class="o-kicker alps-hero__byline[^"]*"[^>]*>(.*?)<\/span>/s)?.[1] || '';
check('byline: the three authors linked in order', /Vaiva Paldavičienė<\/a>, <a [^>]*>Adventistai<\/a>, <a [^>]*>Naujas Autorius/.test(byline) && byline.includes('/autorius/naujas-autorius/'), byline);

// Pre-publish check: no author chosen.
await openEditor(page, `${base}/wp-admin/post-new.php`);
await page.waitForFunction(() => document.querySelectorAll('.alps-author-panel__chosen li').length === 1);
await page.locator('.alps-author-panel__chosen li').first().getByRole('button', {name: 'Remove'}).click();
await page.evaluate(() => window.wp.data.dispatch('core/editor').editPost({title: 'Be autoriaus'}));
await page.click('.editor-post-publish-panel__toggle, .editor-post-publish-button__button');
const prepublish = page.locator('.alps-author-prepublish');
await prepublish.waitFor({timeout: 15000});
check('pre-publish warns and names the default', (await prepublish.textContent()).includes('will be credited to “Adventistai”'), await prepublish.textContent());

// 5. A contributor chooses from the list but can't add to it.
const contributor = await login('jonas-login', 'jonas-login-pass');
await openEditor(contributor, `${base}/wp-admin/post-new.php`);
check('contributor sees the Author panel', await contributor.locator('.alps-author-panel').count() === 1);
check('contributor has no "+ New author"', await contributor.locator('.alps-author-panel__new').count() === 0);
await contributor.fill('.alps-author-panel input[type="text"]', 'Petras');
await contributor.waitForSelector('.alps-author-panel__result');
check('contributor can search the list', await contributor.locator('.alps-author-panel__result:has-text("Petras P. Petraitis")').count() === 1);
await contributor.goto(`${base}/wp-admin/edit.php?page=alps-authors`);
check('contributor cannot open Posts → Authors', (await contributor.content()).includes('not allowed') || (await contributor.title()).includes('rror'));

check('no JavaScript errors', errors.length === 0, errors.join(' | '));
await browser.close();
await visitor.dispose();
console.log(`${checks} checks, ${failures} failures`);
process.exit(failures ? 1 : 0);
