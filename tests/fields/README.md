# Theme fields: Carbon Fields reference and checks

Groundwork for replacing Carbon Fields with native WordPress code: an exact record of how Carbon Fields 3.6.11 stores and returns every theme field, and the checks that the theme's own reader (`app/Fields/`) returns the same. Nothing here runs on a real site or ships in the theme ZIP (`release-theme.yml` excludes `tests/`).

| File | What it is |
| --- | --- |
| `capture.php` | Runs inside a throwaway WordPress. Saves a value in every field through Carbon Fields' own API, then records the definitions, the raw rows written, and what `carbon_get_*()`, `get_alps_field()`, `get_alps_option()` and `crb_get_i18n_theme_option()` return (also for an empty post, i.e. defaults). |
| `compare.php` | Runs inside a throwaway WordPress. Checks that `App\Fields\Registry` matches Carbon Fields' definitions and that `App\Fields\Store` returns exactly (`===`) what `carbon_get_*()` returns, for every field and these stored states: saved through Carbon Fields, nothing stored, blank, invalid choices and checkbox values, duplicate rows, gaps and out-of-order complex rows, unknown groups, legacy association rows, options written straight to the table. Also compares the old and new `get_alps_field()`, `get_alps_option()` and `crb_get_i18n_theme_option()`, checks that `Store::setOption()`/`Store::setPost()` write exactly the rows `carbon_set_theme_option()`/`carbon_set_post_meta()` write (including autoload and stray rows), and checks the settings page's sanitising and the block editor's `alps_fields` REST field (read, write only what changed, cleaning, permissions, the edit-screen defaults stored on first save), and that the native widgets have the Carbon Fields widgets' IDs, classes and titles, give their templates the same values and save the same instances (`update()`). Runs in CI next to the Playground smoke test. |
| `run.py` | Boots WordPress Playground with the theme mounted and activated, runs `capture.php` or `compare.php`. Fails if the run changed `theme.json`. |
| `legacy/cf-theme-options.php`, `legacy/cf-widget.php` | The Carbon Fields definitions of ALPS Theme Settings and of the four widgets before the native code replaced them (frozen). `run.py compare` loads them so options and widgets can still be compared with Carbon Fields; the theme never loads them. |
| `e2e.py`, `settings-page.mjs`, `editor-panels.mjs`, `widgets.mjs` | Browser tests (Playwright) on a seeded Playground site. `settings-page`: the native ALPS Theme Settings page (tabs, media picker, footer rows, the Sabbath condition, save and reload on every tab, front end, a bad nonce, an Editor account, no JavaScript, phone width). `editor-panels`: the block editor panels (all five on a page, four on a post, stored values, conditions, carousel rows with the media modal and reordering, the page picker, saving in Carbon Fields' row format, reload, front end, a new page's banner default, Carbon Fields boxes kept on the attachment screen, no JS errors). `widgets`: Appearance → Widgets (widgets saved by Carbon Fields show their values, editing and adding a widget, the saved instances keep Carbon Fields' keys and IDs, reload, front end, no JS errors). Local checks; need Playwright (`NODE_PATH` = `npm root -g`). |
| `render-seed.php`, `render-compare.py` | Renders 24 URLs (each hero style, header options, related pages, posts, archive, search, 404) with two sets of theme options from this checkout and from an older one in the same Playground, and diffs the HTML and the PHP log. Local check for each migration step. |
| `fixtures/carbon-fields-3.6.11.json` | The capture (WordPress 7.1.3, PHP 8.4.25). Created IDs appear as `{page_target}`, `{image_a}`, …; the `ids` map lists them. |
| `audit.sql` | Read-only queries for a live site: which fields hold data, which page templates are in use, which widgets exist. Generated from the fixture's field list. |

```bash
composer install
python3 tests/fields/run.py compare --php 8.4    # exits 1 on any difference
python3 tests/fields/run.py capture --php 8.4    # rewrites fixtures/carbon-fields-<version>.json
python3 tests/fields/e2e.py settings-page         # browser test of ALPS Theme Settings
python3 tests/fields/e2e.py editor-panels         # browser test of the block editor panels
python3 tests/fields/e2e.py widgets               # browser test of the widgets screen

# Rendered pages before/after: a checkout of the older version, with composer install
git worktree add ../alps-old <base commit> && (cd ../alps-old && composer install --no-dev)
python3 tests/fields/render-compare.py --old ../alps-old
```

Playground does not print `runPHP` output, so `run.py` writes it to `fixtures/<script>.log` (git-ignored). The PHP version is set through the blueprint's `preferredVersions`: Playground CLI 3.1.57 ignores `--php` otherwise. `compare.php` prints the versions it actually ran on. On Windows, run the commands from Git Bash with `MSYS_NO_PATHCONV=1`.

`render-compare.py` picks the theme per request with a cookie (a test-only mu-plugin), so both versions share the database, salts and clock; it replaces the theme folder name and file-modification version strings before comparing. The same PHP notices in both versions are not a difference.

## What the capture shows

- **Simple fields** are rows named `_<field>` (post meta or options): text as written, checkboxes `'true'` (or their `set_option_value`), images the attachment ID, radios/selects the option key. Theme options are written with `autoload` `off`.
- **Complex fields**: one `_<field>|||<row>|value` row per entry, holding the group name (`_` for the unnamed group; `footer_description` uses `translatable_footer_description`), plus `_<field>|<sub>|<row>|0|value` per sub-field. `carbon_get_*` returns a list of rows, each with `_type`.
- **Associations**: `_<field>|||<n>|type`, `|subtype`, `|id` and an empty `|value`. They are returned as `[{value, type, subtype, id}]`; IDs are strings.
- **Widgets**: the WordPress `id_base` is prefixed: `carbon_fields_alps_widget_author` etc., stored in `widget_carbon_fields_*`. Instance keys are `_<field>`.
- **Where the boxes appear** (checked in the block editor and on the media edit screen): pages get Header and Page Builder Options, Header Banner, Hero, Entry Sidebar / Featured Image, Related Pages & Posts. Posts and attachments get the first four. The six boxes tied to `template-home.php` and `views/template-posts.blade.php` (templates that no longer exist) stay hidden.
- **Defaults** are returned when no row exists, e.g. `hero_overlay` `on`, `head_type` `default`, `related_image_crop` `square`, `logo_recolor` true, `theme_color` `campfire`.
- `get_alps_field()` returns `null` for every non-empty scalar field; it only passes arrays (complex, association) through.

## What the comparison showed

- A radio or select whose stored value is not one of its choices reads as the **first** choice; with nothing stored, its default goes through the same rule (`featured_image_hero_layout`'s default `false` reads as `'false'`).
- Unknown fields, and any field read before `init`, return `null`.
- Complex rows are numbered by their stored row index, and a row whose index is beyond the number of group rows is dropped. With `WP_DEBUG`, Carbon Fields throws on such gaps (Acorn turns the warning into an exception); `Store` returns the non-debug result without a warning.
