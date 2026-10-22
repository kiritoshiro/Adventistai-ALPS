# Author profiles (3.36.0)

Posts and pages are credited to **authors**, a list of names managed in the
theme, instead of to user accounts. Old accounts can then be deleted without
changing who a post is credited to, and no login name reaches visitors.

The feature is **off until it is turned on** per site (Appearance → ALPS Theme
Settings → AUTHORS). While it is off the site looks exactly as before; only
the data model is registered, so the migration can run first.

Code: `app/Authors/` (`Taxonomy`, `Accounts`, `AdminPage`, `EditorPanel`,
`Output`, `Cli`), `resources/scripts/author-panel.js`,
`resources/views/taxonomy-alps_author.blade.php`,
`resources/views/partials/author-header.blade.php` and `author-byline.blade.php`.

## Data

- An author is a term of the `alps_author` taxonomy, on every post type with
  an edit screen that supports an author (posts, pages, Modern Events Calendar
  events …; filter `alps_author_post_types`).
- The name, URL slug (made from the name, never a login) and bio are the
  term's; photo, website, Facebook and an optional **linked account** are term
  meta (`alps_author_image`, `alps_author_url`, `alps_author_facebook`,
  `alps_author_user`). The linked account only preselects the author for that
  account's new posts; it is not public (not in REST).
- A post can have several authors. Their order is the taxonomy's own per-post
  term order (`sort`), so it needs no extra post meta and survives REST
  and WP-CLI.
- The terms stay in the database if the theme is switched.

## Managing authors

**Posts → Authors** (editors and administrators, `edit_others_posts`): list
with search, add, edit (name, slug, bio, photo, website, Facebook, linked
account), delete and merge.

- Deleting an author who is credited on posts asks which author takes them
  over; posts are never left without an author.
- **Merge** credits one author's posts to another (in the same place in each
  post's list, without duplicates) and deletes the first. Use it for
  duplicates such as "Adventistai" / "Adventistai.lt".
- While the feature is off only administrators see the page, to prepare the list.
- Authors are not deleted through the REST API (`DELETE /wp/v2/alps_author/<id>`
  answers 403), so the choice of who takes over their posts can't be skipped.
  If the default author is merged into another, that one becomes the default;
  if it is deleted any other way, the setting is cleared.

**Editor → Author panel**: search the list and choose one or more authors,
reorder them, remove them. Editors and administrators also get **+ New
author** (name and short bio; it offers an existing author with the same name
instead of a duplicate). Contributors choose from the list only. A new post
starts with the author linked to its account. Publishing with no author shows
a warning in the pre-publish panel naming the author that will be used.

When a post is published or scheduled with no author (editor, REST — e.g.
WPBridge — or WP-CLI) it gets its account's linked author, else the **default
author**; authors that are set are never changed. Classic edit screens (if
any post type still uses one) get a simple checkbox box.

The post list gets an "All authors" filter and an Authors column.

## Settings (ALPS Theme Settings → AUTHORS)

| Setting | Default |
| --- | --- |
| Use author profiles | off |
| Author page address (`/<base>/<slug>/`) | `autorius` (rewrite rules are rebuilt automatically when it changes) |
| Default author | set by the migration to "Adventistai" |
| Show the authors under post titles (byline) | off |
| Hide user accounts | on |

They are stored like the other theme settings (Carbon Fields' row format,
`App\Fields\Store`).

## What visitors see (when on)

- Author pages: `/autorius/<slug>/` with the name, photo, bio, links and the
  author's posts (paged normally, not with Ajax Load More, which only filters
  by category). An author without posts still shows the photo and bio.
- `/author/<login>/`, its feed and `?author=N` redirect (301) to the linked
  author's page, otherwise 404. WordPress's own redirect that reveals the
  login never runs.
- `the_author`, the post author's display name, author links, RSS
  `dc:creator` (all authors), oEmbed, the Author Box widget and, with Yoast
  SEO, `<meta name="author">`, Slack "Written by", `article:author`, the schema
  `Person` (from the author: name, page, photo, bio, website/Facebook — the
  account's Person, Gravatar and `/author/` URL are removed) and sitemaps
  (no user sitemap; an `alps_author` sitemap) all use the authors.
- A post with no author is shown as the default author's; with no default
  author either, no name at all — never the account.
- **Hide user accounts**: `/wp-json/wp/v2/users` (in any letter case) answers
  401 to visitors who are not logged in (logged-in users and the block editor
  are unaffected), the core users sitemap is off, and comment classes don't
  name accounts.

## Migration (WP-CLI)

```bash
wp alps authors migrate --dry-run      # the plan: account → author, posts, possible duplicates
wp alps authors migrate                # do it (can be run again; posts with an author are left alone)
wp alps authors verify                 # posts without an author (exit 1), authors without posts, links to deleted accounts
wp alps authors list
wp alps authors holder-account         # the locked "Redakcija" account (prints its ID)
```

`migrate` (option `--post-type=post,page`): for every account that wrote a
published, scheduled, draft, pending, private or trashed post, it uses the
author linked to the account or makes one from its display name, bio,
website and `facebook` user meta, and credits that account's posts that have
no author to it. It makes sure an author named "Adventistai" exists (reusing
the one made from an account that displays as "Adventistai") and sets it as
the default author. Posts of accounts that no longer exist, of no account or
of the "Redakcija" account get the default author. Names that look like the
same author ("Adventistai", "Adventistai.lt", "adventistai lt") are reported,
not merged.

## Deleting accounts

Before WordPress reassigns or deletes a deleted account's posts, they get the
account's author (made from the account if the site uses authors and it has
none), and the author is unlinked. Choose **"Attribute all content to:
Redakcija"** (or `wp user delete <account> --reassign=<Redakcija ID>`): the
posts keep their byline because it comes from the author.

The **Redakcija** account (`wp alps authors holder-account`, login
`redakcija`) has no role and a random 64-character password that is never
shown or stored, plus `alps_login_disabled`: it cannot log in with a password
or an application password and cannot reset its password. It is never offered
as an author.

## Rollout on a site

1. Install 3.36.0.
2. `wp alps authors migrate --dry-run`; review the table and the possible duplicates.
3. `wp alps authors holder-account`.
4. `wp alps authors migrate`, then `wp alps authors verify`.
5. Turn on **Use author profiles**; check a post, an author page and an old
   `/author/` address; purge Cloudflare.
6. Merge duplicates and add photos and bios under Posts → Authors over time.
7. Delete or demote old accounts one group at a time, reassigning to Redakcija.

## Tests

- `python3 tests/authors/run.py` (Playground; in CI): migration dry run vs
  real and idempotence, default author without a duplicate, deleted accounts
  and orphan posts, delete with reassignment, merge, the safety net for REST
  posts, the new-post default, contributor rights, author order, the
  Redakcija account (password, application password, reset), and the output
  with the feature off and on.
- `python3 tests/authors/e2e.py` (Playground + Yoast SEO + Playwright, local):
  Posts → Authors (add, edit, delete with reassignment), the settings tab,
  the editor panel (choose, reorder, add inline, duplicate warning, publish,
  pre-publish warning), a contributor, and visitors (author page, 301/404 for
  old addresses, REST users 401, Yoast meta/schema/sitemaps, RSS, oEmbed, no
  login names in the HTML).
- The Playground smoke test turns the feature on and loads an author page,
  `/?author=1` and `/author/admin/`.
