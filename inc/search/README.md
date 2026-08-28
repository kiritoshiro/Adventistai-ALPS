# Adventistai advanced search

This directory contains the theme-owned search engine for `adventistai.lt`.
The feature is additive and is protected by the single `adv_search_enabled`
setting (`adv_search_enabled`, mirrored by the settings-array checkbox). When the switch is off the
original ALPS search template, form, and WordPress query behaviour are used.

## Architecture

- `Adv_Search_Normalizer` performs identical UTF-8 Lithuanian/Latin folding at
  index and query time and supplies raw-character offset maps for highlighting.
- `Adv_Search_Schema` owns the versioned `wp_adv_search_index` and
  `wp_adv_search_tokens` tables.
- `Adv_Search_Indexer` renders posts to safe plain text and maintains one index
  row plus prefix-searchable token rows per published item.
- `Adv_Search_Query` parses queries, uses left-anchored `LIKE 'term%'`, applies
  AND/OR and quoted-phrase rules, and ranks in two immutable tiers. A title
  match is always in the higher tier, regardless of configured weights.
- `Adv_Search_Renderer` is the only result HTML renderer used by the results
  page and REST response.
- `Adv_Search_REST` provides the nonce-protected public suggestion endpoint,
  hashed per-IP rate limit, cache, and administrator reindex endpoint.
- `Adv_Search_Admin` provides **Appearance → Paieška**.

The implementation never uses database word-boundary regex. Consequently it
does not depend on the MySQL 8 ICU versus MariaDB/MySQL 5.7 regex split or on
the site's collation for diacritic folding.

## First activation

1. Deploy the branch to a staging site.
2. Open **Appearance → Paieška → Indeksas**. This screen reports the actual
   MySQL/MariaDB version, `wp_posts`-database collation, index counts, table
   size, and whether `mbstring`/`intl` are available.
3. Confirm the explicit content-type allowlist on the **Bendra** tab. The
   defaults are only `post` and `page`; installing a plugin never adds its CPT.
4. Enable the master switch and save.
5. Return to **Indeksas** and press **Perkurti indeksą**. The browser processes
   50 posts per request and displays progress.
6. Test the acceptance queries, then enable the branch on production.

For a large site, use:

```bash
wp adventistai search reindex
```

The uploaded repository does not expose the production database version,
collation, installed PHP extensions, published-content count, or registered CPT
slugs. The Index tab deliberately reports these facts before production use.
The theme itself requires PHP 8.1 (`composer.json`); `mbstring` is required by
this feature, while `intl` is optional and is not required for the explicit
folding map.

## Settings

- **Bendra:** master switch, explicit public-post-type allowlist, excluded post
  and taxonomy IDs, excerpts, and an opt-in custom-field key allowlist. Draft,
  private, trashed, and password-protected content is always excluded.
- **Atitikimas:** live minimum characters, term length, maximum terms, AND/OR,
  quoted phrases, and Lithuanian stopwords.
- **Rikiavimas:** exact-title, title-start, title-first-token, title-token,
  excerpt, content, all-title bonus, and page multiplier.
- **Rodymas:** whitelisted per-page counts, live delay/limit, snippets,
  highlight tag/class/colour, metadata toggles, type filters, and zero-result
  links.
- **Indeksas:** compatibility facts, counts, last build, table size, batched
  rebuild, and cache invalidation.

Internal safety defaults not exposed as everyday controls are filterable in
`adv_search_options`: 100,000 indexed content characters, 5,000 tokens per
field, 300-second cache TTL, and 30 live requests per 60 seconds.

## Hooks and filters

- `adv_search_operator( string $operator, array $parsed )` changes AND/OR at
  query time. Keep the prefix-only promise when filtering it.
- `adv_search_score( float $score, array $row, array $matches, array $parsed )`
  changes score inside a tier. It cannot move a content-only result above the
  title tier.

Index maintenance uses `save_post`, `deleted_post`, `trashed_post`,
`untrashed_post`, and `post_updated`. Cache versioning is invalidated on content
or settings changes, so no broad transient-table deletion is required.

## Migration and removal

Schema version is stored in `adv_search_schema_version`. A theme update runs
`dbDelta()` only when the version changes; indexed rows survive normal theme
updates. Changing the searchable-type allowlist clears the index and displays
a rebuild notice rather than rebuilding during a page request.

Disabling the feature keeps both tables so re-enabling is fast and stock search
is immediately restored. WordPress themes have no plugin-style uninstall hook,
so deleting the theme files also leaves the tables and settings in place to
avoid accidental data loss. `Adv_Search_Schema::uninstall()` is available for a
deliberate maintenance script when permanent removal is required.

## Known limits

- Prefix matching is strict; there is no fuzzy or mid-word matching.
- Search is limited to explicitly allowed public post types and does not search
  comments, media, products, or taxonomies.
- Exact phrases are consecutive token prefixes, not database-collation phrases.
- Dynamic shortcodes are flattened at index time. Rebuild after changing a
  shortcode whose rendered text should be searchable.
- The in-memory browser cache lasts for the current page load; server cache uses
  transients and is version-invalidated on content changes.

## Tests

Run the dependency-free normalizer/query-parser checks:

```bash
php tests/search/run.php
```

Full ranking, SQL-plan, REST, accessibility, no-JavaScript, and 2,000-post
rebuild acceptance tests require a staging WordPress database. During staging,
run `EXPLAIN` on the candidate/token query and confirm `token_field` is selected
instead of a full token-table scan.
