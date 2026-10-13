# Carbon Fields reference capture

Groundwork for replacing Carbon Fields with native WordPress code: an exact record of how Carbon Fields 3.6.11 stores and returns every theme field. Nothing here runs on a real site or ships in the theme ZIP (`release-theme.yml` excludes `tests/`).

| File | What it is |
| --- | --- |
| `capture.php` | Runs inside a throwaway WordPress. Saves a value in every field through Carbon Fields' own API, then records the definitions, the raw rows written, and what `carbon_get_*()`, `get_alps_field()`, `get_alps_option()` and `crb_get_i18n_theme_option()` return (also for an empty post, i.e. defaults). |
| `run-capture.py` | Boots WordPress Playground with the theme mounted and activated, runs `capture.php`, restores `theme.json` afterwards. |
| `fixtures/carbon-fields-3.6.11.json` | The result (WordPress 7.1.3, PHP 8.4.25). Created IDs appear as `{page_target}`, `{image_a}`, …; the `ids` map lists them. |
| `audit.sql` | Read-only queries for a live site: which fields hold data, which page templates are in use, which widgets exist. Generated from the fixture's field list. |

```bash
composer install
python3 tests/fields/run-capture.py --php 8.4   # rewrites fixtures/carbon-fields-<version>.json
```

Playground does not print `runPHP` output, so `run-capture.py` writes it to `fixtures/capture.log` (git-ignored). The PHP version is set through the blueprint's `preferredVersions`: Playground CLI 3.1.57 ignores `--php` otherwise.

## What the capture shows

- **Simple fields** are rows named `_<field>` (post meta or options): text as written, checkboxes `'true'` (or their `set_option_value`), images the attachment ID, radios/selects the option key. Theme options are written with `autoload` `off`.
- **Complex fields**: one `_<field>|||<row>|value` row per entry, holding the group name (`_` for the unnamed group; `footer_description` uses `translatable_footer_description`), plus `_<field>|<sub>|<row>|0|value` per sub-field. `carbon_get_*` returns a list of rows, each with `_type`.
- **Associations**: `_<field>|||<n>|type`, `|subtype`, `|id` and an empty `|value`. They are returned as `[{value, type, subtype, id}]`; IDs are strings.
- **Widgets**: the WordPress `id_base` is prefixed: `carbon_fields_alps_widget_author` etc., stored in `widget_carbon_fields_*`. Instance keys are `_<field>`.
- **Where the boxes appear** (checked in the block editor and on the media edit screen): pages get Header and Page Builder Options, Header Banner, Hero, Entry Sidebar / Featured Image, Related Pages & Posts. Posts and attachments get the first four. The six boxes tied to `template-home.php` and `views/template-posts.blade.php` (templates that no longer exist) stay hidden.
- **Defaults** are returned when no row exists, e.g. `hero_overlay` `on`, `head_type` `default`, `related_image_crop` `square`, `logo_recolor` true, `theme_color` `campfire`.
- `get_alps_field()` returns `null` for every non-empty scalar field; it only passes arrays (complex, association) through.
