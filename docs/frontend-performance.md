# Front-end performance notes

## Stylesheet order

The cascade order is unchanged from the old `head.blade.php` output; only how each file is delivered changed:

1. Plugin stylesheets and WordPress inline styles.
2. The theme bundle `public/css/app.*.css`.
3. `assets/css/site-overrides.css`, followed by `responsive-zoom-fixes.css` embedded as a `<style>` block.
4. The advanced-search styles, embedded as a `<style>` block (`inc/search/class-adv-search.php`).
5. The ALPS stylesheet (`app/local/alps/css/main-<color>.css`), enqueued last as `alps-main` in `app/setup.php`. On the front page, `sabbath-timer.css` is embedded right after it.
6. Styles enqueued while the page renders (block styles, Modern Events Calendar), which WordPress moves into `<head>`.

The small files are embedded rather than linked so that each page has three render-blocking theme stylesheets instead of six. Edit the files in `assets/css/` as before; no build step is involved.

## Fonts

- `wp_head` (priority 2) preloads Noto Sans Regular, Noto Sans Bold and Source Serif 4 before any stylesheet loads. All three are used above the fold.
- The Source Serif 4 variable fonts keep the full weight axis (200–900). Their optical-size axis is pinned at 16, the size most serif text is set at (14–18px). This cut the files from about 135/142 KiB to 55/56 KiB.
- To rebuild the fonts from the upstream variable fonts, use fontTools:

```bash
pip install fonttools brotli
```

```bash
fonttools varLib.instancer -o SourceSerif4-Variable.woff2 SourceSerif4-Variable-upstream.woff2 opsz=16
```

Repeat for the italic file. Keep the existing Latin/Latin Extended-A subset, which covers Lithuanian.

## Sabbath timer

`sabbath-timer.js` is embedded straight after the timer markup, so the timer is shown or hidden before the content below it is laid out. As a deferred file, it revealed the timer late and shifted the page. The embedded JSON payload carries only the fields the script reads: about 16 KB instead of 41 KB.

## ALPS script

`app/local/alps/js/script.min.js` (menus, drawer and other pattern-library behaviour) is enqueued in `app/setup.php` as `alps-main`, in the footer, async, with `jquery` as a dependency. It reads the global `jQuery` as soon as it runs. It used to be a raw `<script>` tag in the footer template, which threw "jQuery is not defined" on sites where no plugin happened to load jQuery.

## Server caching (not in the theme)

Theme and plugin asset URLs change with every version (`?ver=` or content hashes), so they can be cached for a year. Set this at Cloudflare (Cache Rule → Browser TTL) or on the origin:

```
Cache-Control: public, max-age=31536000, immutable
```

Apply it to `/wp-content/` and `/wp-includes/` static files (CSS, JS, fonts, images), not to HTML.
