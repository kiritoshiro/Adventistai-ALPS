# Changelog
A record of the changes made to `ALPS for Wordpress`.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.30.10]
### Fixed
- Small plugin stylesheets are inlined again. WordPress runs `wp_enqueue_scripts` on `wp_head` priority 1, after the theme's marking pass (priority 0) and just before it inlines (also priority 1), so stylesheets plugins enqueue there stayed separate render-blocking requests (on adventistai.lt: Bible, calendar, cookie banner, book showcase and external posts, 1.7–4 KB each). They are now marked once every `wp_enqueue_scripts` callback has run.
- Desktop layout shift (PageSpeed CLS 0.13): the Sabbath rail is the last element of the page, so on a slow connection the browser painted the content at full width first and then narrowed it when the rail arrived, moving the sidebar (left 828 → 709 px, width 507 → 435 px at 1350 px) and the menu. A placeholder now holds the rail's seventh until the rail exists (`:has()`, inside `@supports`), and follows the "hide the Sabbath column" settings.

### Changed
- Latest Post Slider images get a `sizes` value that matches how wide they are drawn: the frame (at most 100vw minus padding on phones, about 560 px for one column, 840 px shared by the columns on wide screens), scaled down for portrait images that are fitted inside a 16:9 frame. Browsers downloaded the full file (743–1,000 px) for 160–400 px frames; a portrait cover in a two-column slider now asks for about 177 px.

## [3.30.9]
### Fixed
- Stacked Gutenberg Columns stay aligned: at 781 px and below, where WordPress stacks columns, the theme's 1.25em left gutter on every column after the first is cleared, so all columns start at the same left edge. Columns set not to stack on mobile keep it.
- Article and sidebar stay aligned when the sidebar moves below the article: ALPS added a one-seventh viewport left offset at 701–900 px (about 140 px at 850 px); the stacked layout (up to 1000 px) now clears it, so content keeps the normal ~16–19 px inset. Desktop layout is unchanged.

### Changed
- Workflows: the version comments on the pinned `shivammathur/setup-php` commit now say 2.37.2 (the tag that commit is), clearing the workflow security audit's version-mismatch finding. Pins and behaviour are unchanged.

## [3.30.8]
### Changed
- Header logo: the SVG `<img>` gets its width and height (from the attachment metadata, or the SVG's own size/viewBox, cached) plus `fetchpriority="high"`. It is the largest element on phones (PageSpeed's LCP element); its rendered size is unchanged (checked at 280 and 1280 px). The footer logo gets its size and lazy loading.
- Small stylesheets that plugins enqueue during `wp_footer` (the calendar's, the Bible popups') are now inlined too: one more inlining pass runs right before WordPress prints late styles.

### Added
- Security headers on public pages: `X-Frame-Options: SAMEORIGIN` and `Content-Security-Policy: frame-ancestors 'self'` (no framing by other sites) and `Cross-Origin-Opener-Policy: same-origin-allow-popups`. The CSP holds only the frame rule. Filter `adventistai_security_headers` to change them (e.g. to let a partner site frame pages).

## [3.30.7]
### Changed
- `site-overrides.css` is loaded as the minified `site-overrides.min.css` (13 KB instead of 32 KB, 2.9 KB instead of 9 KB compressed), built with lightningcss by `scripts/minify-site-overrides.mjs`; CI fails when it is out of date. Under `SCRIPT_DEBUG`, or when the copy is missing, the readable file is used.

### Fixed
- Footer links ("Privatumo politika", "Slapukų nustatymai", …) are at least 24 px high, so they are no longer too small and too close together for touch (WCAG 2.5.8, flagged by PageSpeed).

## [3.30.6]
### Added
- Theme cache: Acorn's compiled templates (`wp-content/cache/acorn/framework/views`) are cleared automatically the first time a new theme version runs, so an update no longer needs a manual cache clear. For a manual clear there is **Clear theme cache** in the admin bar and in Appearance → ALPS Theme Settings → Cache (administrators). Acorn's package/service manifests are left alone: deleting `services.php` stopped the theme from booting in a test.

### Changed
- Small stylesheets (12 KB or less, local, for all media) are printed inside the page instead of linked, up to 90 KB per page, using WordPress' own inlining. In this theme every plugin and block stylesheet ends up in `<head>`, so each was a separate request blocking the first paint (about a dozen on the front page). The theme's main stylesheets and the calendar's stay linked and cacheable.
- The front-end app bundle loads with `defer` (PR #79).

## [3.30.5]
### Fixed
- Facebook and other link previews show the post image again when Yoast SEO is active. Yoast skips AVIF images, so posts with an AVIF featured image had no `og:image`; the theme now gives Yoast the JPEG fallback (of the Yoast social image, or else the featured image) when Yoast found no usable image. An image Yoast can use is left alone.

## [3.30.4]
### Changed
- Latest-post sliders have fewer lines: no rule under the heading, no underline border on the heading link or above the dots, no focus box after a mouse click; the frame is lighter.
- A slider shown one per row puts its image beside the text (40% of the width) once it is at least 560px wide, instead of a full-width image above the text.
- Card scroller: the "Daugiau" card is neutral grey, fading in from the row; the end-of-row arrow matches the other arrows; the cards keep their light frame (site-overrides.css had replaced it with a blue underline).

## [3.30.3]
### Added
- Card scroller: at the end of the row, the next arrow becomes a blue "go to" arrow (→) that opens the Daugiau link, or the heading's link when that is empty. A Daugiau link works without a label too (no extra card).

## [3.30.2]
### Fixed
- Card scroller arrows: the ALPS colour stylesheets' button style showed as a coloured, rounded block behind each arrow. The arrows now show only a light white fade and the round arrow button.

## [3.30.1]
### Fixed
- Hand-written images in page content (for example the front page's book and logo snippets) get their attachment's width, height, srcset and sizes, so WordPress lazy-loads them instead of downloading every one at full size when the page opens.
- Only the page's first latest-post slider loads its first image eagerly; the others load lazily.
- Card scroller (Kortelių slankiklis): in the editor the cards wrap, so the "Pridėti kortelę" button stays reachable with many cards. Cards without a link are shown as plain cards instead of being dropped, so the block no longer renders empty. The block has an inserter preview.

## [3.30.0]
### Changed
- Upgrade Roots Acorn from 3.3 (Laravel 9, end of life since February 2024) to 6.3 (Laravel 13), and boot it with `Application::configure()->boot()` instead of the deprecated `bootloader()`.
- Require PHP 8.3 or later (Acorn 6 needs it). Production runs PHP 8.4.
- Require our ALPS Gutenberg Blocks fork (3.0.0) through TGMPA instead of upstream's package.
- Make the GitHub token for theme updates and the release selector optional.
### Upgrade notes
- Clear Acorn's compiled view cache after updating (`wp acorn optimize:clear`, or delete `wp-content/cache/acorn`).

## [3.28.1]
### Added
- Generate lightweight SEO defaults for titles, descriptions, canonical URLs, social metadata, and structured breadcrumbs without requiring per-post SEO fields.
- Add safe reusable content helpers for multilingual excerpts, category resolution, and responsive content images.
### Fixed
- Prevent empty category links and unsafe text output in post feeds, related stories, and latest-post sliders.
- Improve breadcrumb fallbacks and remove stray visible text from media blocks.

## [3.25.5]
### Added
- Slider category headings link to category archives, with an optional custom URL.
- Reorder slider modules by dragging or using up/down buttons.
### Fixed
- Display quotation marks correctly and use Lithuanian public slider labels.
- Remove empty featured-image placeholders and keep autoplay paused during hover or focus.
- Prevent duplicate slider timers and keyboard focus on inactive slides.

## [3.25.0]
### Added
- Convert the latest post slider into an insertable Gutenberg block for posts and pages, with independent slider sources, layout controls, custom headings, autoplay, and indicator navigation.

## [3.24.0]
### Added
- Add configurable latest post slider modules for pages, with category or selected post/page sources, autoplay, indicator navigation, image cards, and responsive one-to-four-column layouts.

## [3.15.3.8]
### Fix
- Show larger banner as 50/50 hero with large image and text. [#819](https://github.com/adventistchurch/alps-wordpress/issues/819)

## [3.15.3.7]
### Fix
- Fix PHP Errors in client servers. [#811](https://github.com/adventistchurch/alps-wordpress/issues/811)
- Widget Lock can't be unlocked. [#813](https://github.com/adventistchurch/alps-wordpress/issues/813)

### Update
- Disable Hero Overlay Fade. [#814](https://github.com/adventistchurch/alps-wordpress/issues/814)

## [3.15.3.6]
### Fix
- Fix PHP Errors in client servers. [#811](https://github.com/adventistchurch/alps-wordpress/issues/811)

## [3.15.3.5]
### Fix
- Fix security issues. [#809](https://github.com/adventistchurch/alps-wordpress/pull/809)
- Fix low security issues. [#810](https://github.com/adventistchurch/alps-wordpress/pull/810)

## [3.15.3.4]
### Fix
- Fix security issues. [#808](https://github.com/adventistchurch/alps-wordpress/pull/808)

## [3.15.3.3]
### Fix
- Rollback changes from 3.15.3.1 and 3.15.3.2 versions and update styles for embed YouTube Player. [#807](https://github.com/adventistchurch/alps-wordpress/pull/807)

## [3.15.3.2]
### Fix
- Fix broke Page Template Builder from 3.15.3.1 version. [#806](https://github.com/adventistchurch/alps-wordpress/pull/806)  

## [3.15.3.1]
### Fix
- Fix Page Template. [#805](https://github.com/adventistchurch/alps-wordpress/pull/805)

## [3.15.3.0]
### Update
- Increase WP version support for the theme [#801](https://github.com/adventistchurch/alps-wordpress/pull/801);
- Add Basic support for Sermon Manager plugin [#746](https://github.com/adventistchurch/alps-wordpress/issues/746);
- Menu toggle missing on some breakpoints [#800](https://github.com/adventistchurch/alps-wordpress/issues/800);
- Add ALPS video guide and update documentation [#803](https://github.com/adventistchurch/alps-wordpress/pull/803);
- Cropping Instructions [#777](https://github.com/adventistchurch/alps-wordpress/issues/777).

## [3.15.2.9]
### Fix
- Security alert from Wordfence. [#785](https://github.com/adventistchurch/alps-wordpress/issues/785)

### Update
- hero_carousel - increase more slides from 6 to 9. [#792](https://github.com/adventistchurch/alps-wordpress/issues/792)
- WordPress Image Compression Quality. [#779](https://github.com/adventistchurch/alps-wordpress/issues/779)

## [3.15.2.8]
### Update
- German language support for Gutenberg Blocks. [#549](https://github.com/adventistchurch/alps-wordpress/issues/549)
- Update dependencies for Security WordFence issue. [#785](https://github.com/adventistchurch/alps-wordpress/issues/785)

## [3.15.2.7]
### Fix
- Fix _wp.pagebuilders.scss

## [3.15.2.6]
### Update
- Access to remote assets;
- PHP errors.[#766](https://github.com/adventistchurch/alps-wordpress/issues/766);
- Conflict with Google Maps API.[#598](https://github.com/adventistchurch/alps/issues/598);
- Updates to 50/50 header and block editor css. [#760](https://github.com/adventistchurch/alps-wordpress/issues/760).

## [3.15.2.5]
### Update
- Update dependencies.

## [3.15.2.4]
### Update
- Make assets available remotely.

## [3.15.2.3]
### Update
- Update PROD workflow: add latest parameter.

## [3.15.2.2]
### Update
- Update DRAFT workflow.

## [3.15.2.1]
### Update
- Update workflow.

## [3.15.2.0]
### Update
- Update: split draft and PROD workflows.[#756](https://github.com/adventistchurch/alps-wordpress/pull/756)

## [3.15.1.9]
### Update
- Update publish workflow.[#751](https://github.com/adventistchurch/alps-wordpress/pull/751)

## [3.15.1.8]
### Update
- Added Primary Navigation Menu supporting 3rd level.[#745](https://github.com/adventistchurch/alps-wordpress/pull/745);

## [3.15.1.7]
### Update
- Added lodash.js dependency to carbon fields initialization (WP 6.4+)[#737](https://github.com/adventistchurch/alps-wordpress/pull/737);
- Add page subtitle [#723](https://github.com/adventistchurch/alps-wordpress/issues/723);

## [3.15.1.5]
### Update
- Make general link for getting latest version of theme [#723](https://github.com/adventistchurch/alps-wordpress/issues/723);
- Add labels to the theme description;

### Fix
- Missing style for input with autofill.


## [3.15.1.4]
### Fix
- Add "Enable Auto-updates" [#693](https://github.com/adventistchurch/alps-wordpress/issues/693)

### Update
- Update content-page-full-width.blade.php [#630](https://github.com/adventistchurch/alps-wordpress/pull/630)
- Support new version WP and increase PHP version [#718](https://github.com/adventistchurch/alps-wordpress/issues/718)

## [3.15.1.2]
### Update
- Update ALPS Theme settings design for supporting footer localizatinon [#627](https://github.com/adventistchurch/alps-wordpress/issues/627);
- Supporting Wide logo functionality [#670](https://github.com/adventistchurch/alps-wordpress/issues/670).

## [3.15.0.1]
### Update
- Update theme BUD version from 5 to 6 [#697](https://github.com/adventistchurch/alps-wordpress/issues/697)
- Move ALPS styles for storing inside theme (GDPR issue) [#682](https://github.com/adventistchurch/alps-wordpress/issues/682)
- New required plugins were added in ALPS: Spectra, Stackable, RankMath. [#699](https://github.com/adventistchurch/alps-wordpress/issues/699)
- Update global css styles file for button o-button-toggle class.
- Changes to allow WP theme to control logo color [#702](https://github.com/adventistchurch/alps-wordpress/pull/702)
- Changes to provide additional logo options in theme settings [#701](https://github.com/adventistchurch/alps-wordpress/pull/701)
- Add docs folder for getting developers information about branching strategy.

## [3.14.4.3]
### Fix
- Prevent space between city and comma in footer addresses [#676](https://github.com/adventistchurch/alps-wordpress/pull/676);
- Webpack updates, bug report, assign examples [#696](https://github.com/adventistchurch/alps-wordpress/pull/696).
- Fixes for undefined variable warnings and mods to post-options.php to provide default header settings for pages and posts. Was included a step to update old header format settings to the new values. [#695](https://github.com/adventistchurch/alps-wordpress/pull/695)

## [3.14.4.2]
### Fix
- Fix WPML Logo [#688](https://github.com/adventistchurch/alps-wordpress/issues/688)

## [3.14.4.1]
### Fix
- Added option to remove content spacing (page builder support)
- Added option to remove template and page header (page builder support)
- Added full color theme palette support via manipulation of theme.json based on theme options (page builder support)
- Editor css dynamically applied to add or remove margins based on selected template (theme builder support)
- Added additional template header and page header options
- Merged header settings/options for pages and posts so all options are available to both

## [3.14.3.6]
### Fix
Request [#658](https://github.com/adventistchurch/alps-wordpress/issues/658)
- Add prebuilding menus;
- installing required plugins (Guthenberg, SVG);
- WPML support;
- settings for Carbon Fields plugin, if it not installed yet;
- ALPS Gutenberg Blocks filter.
- Add NAD logo functionality.

Request [#664](https://github.com/adventistchurch/alps-wordpress/issues/664)
- Move all default menus creation to default.php file;
- Move Required plugins installation to default.php file.

Request [#661](https://github.com/adventistchurch/alps-wordpress/issues/661)
- Add left margin for content in Gutenberg Editor.

Request [#662](https://github.com/adventistchurch/alps-wordpress/issues/662)
- Remove pklist functionality for new Version of ALPS.

## [3.14.3.1]
### Fix
- Fix html structure for SEO. - [#671](https://github.com/adventistchurch/alps-wordpress/issues/671).

## [3.14.3.0]
### Fix
- Animation support for "Related Pages" preview (.GIF) - [#633](https://github.com/adventistchurch/alps-wordpress/issues/633).

## [3.14.2.4]
### Fix
- Clean Release.

## [3.14.2.2]
### Fix
- Fix support WP 6.2.
- Update theme .json file, add 'requires' field. 
- Update auto updater for theme.

## [3.14.2.1]
### Fix
- Pipeline fix.

## [3.14.2.0]
### Update
- Add supporting child theme (AORG).
- Support WP 6.2 version.

## [3.14.1]
### Update
- Update assets folder.
- Fix WordPress favicon instead of church's logo

## [3.14.0]
### Update
- Support php 8.0 and higher.
- Support WordPress 6.1 and higher.
- Migrate theme to Sage version 10.
- Migrate Carbon fields to the new version.
- Remove Kernel Scripts.
- Migrate from webpack to bud.
- Update Composer libraries.

## [3.13.3]
### Fixed
- Update css styles for current build.
-
## [3.13.2]
### Remove
- Remove styles menu styles for supporting another languages in the menu. Main changes in the alps project [#574. CSS 3.11.7. Fix menu styles.](https://github.com/adventistchurch/alps/pull/574).

## [3.12.0]
### Added
- Added the ablity to create a default version of the theme through `defaults.php` and `defaults-themes.php` files. Settings made in the `defaults.php` file will only show up on the first install.

## [3.11.0]
### Fixed
- Support php 8. Update global variables and support blade files for php 8.0 version.

## [3.10.6]
### Fixed
- Update css styles build.

## [3.10.5]
### Fixed
- Fix styles for Woocommerce plugin.

## [3.10.4]
### Fixed
- Fix Localized Google internal fonts. Update link tag correct type in head.

## [3.10.3]
### Sync
- Synchronize releases.

## [3.10.1]
### Fixed
- Localize Google Fonts within Template. Fix issue for GDPR of google fonts API. [#584](https://github.com/adventistchurch/alps-wordpress/issues/584).

## [3.10.0]
### Added
- Added support for Korean internationalization `.po` files, KO, thanks to Sangyong Lee.



## [3.9.2]
### Fixed
- Added a link to the image on the related stories block. [#575](https://github.com/adventistchurch/alps-wordpress/issues/575)


## [3.9.1]
### Added
- Updated the Search drawer markup to allow for filter results. [#578](https://github.com/adventistchurch/alps-wordpress/pull/578)


## [3.9.0]
### Added
- Add: Switched "Related Posts" to widgets. [#565](https://github.com/adventistchurch/alps-wordpress/issues/565)


## [3.8.0]
### Added
- Add: Added the ablity to diable the WPML language menu. [#548](https://github.com/adventistchurch/alps-wordpress/issues/548)

### Fixed
- Fix: Bug with CarbonFields and ALPS Settings page.


## [3.7.9]
### Fixed
- HOTFIX: Update ALPS versions if value is empty (fix for Redis).


## [3.7.8]
### Fixed
- HOTFIX: Adding annother fix for CSS `auto` properties for height and width of images.


## [3.7.7]
### Fixed
- HOTFIX: Adding css `auto` properties for height and width of images.


## [3.7.6]
### Fixed
- Fix: ALPS Related Pages block was only at 3 columns when the sidebar was hidden. [#546](https://github.com/adventistchurch/alps-wordpress/issues/546)
- Fix: A bug with related stories now showing for certain categories that had different slugs. [#536](https://github.com/adventistchurch/alps-wordpress/issues/536)


## [3.7.5]
### Fixed
- Fix: Switch related posts sidebar and menu drawer from using h3's to using divs. [#558](https://github.com/adventistchurch/alps-wordpress/issues/558)


## [3.7.4]
### Fixed
- Fix: Pulling in the images along with the JS and CSS to the local cache. [#551](https://github.com/adventistchurch/alps-wordpress/issues/551)


## [3.7.3]
### Fixed
- Fix: Fixing a bug that didn't allow the local cached version of the ALPS CSS/JS to be used on subthemes. [#551](https://github.com/adventistchurch/alps-wordpress/issues/551)


## [3.7.2]
### Fixed
- HOTFIX: Bug with homepage 3-up grid displays shifting to the right to overlap the Sabbath column.


## [3.7.1]
### Fixed
- Fixed a bug that didn't pull the latest version of the code. [#555](https://github.com/adventistchurch/alps-wordpress/pull/555)


## [3.7.0]
### Added
- Added local caching of ALPS CDN CSS and Javascript files. It will pull the most recent version into the theme for added performance. [#551](https://github.com/adventistchurch/alps-wordpress/issues/551)
### Fixed
- Fixed a bug where the related post grid at 3up widths (with hidden sidebar) is shifted wrong on the page.


## [3.6.6]
### Fixed
- Removed the second version of jQuery (2.2.4) since Wordpress now includes jQuery 3.5.1 [#550](https://github.com/adventistchurch/alps-wordpress/issues/550)


## [3.6.5]
### Fixed
- Fixed the styling for footnotes, and Footnotes Made Easy. [#507](https://github.com/adventistchurch/alps-wordpress/issues/507)
- Removed support for Guidepost and replaced it with SimpleTOC. [#533](https://github.com/adventistchurch/alps-wordpress/issues/533)


## [3.6.4]
### Fixed
- Fixed and updated the German translation. [#542](https://github.com/adventistchurch/alps-wordpress/issues/542)


## [3.6.3]
### Fixed
- Fixed an issue with the html language attributes displaying extra quote marks around them.


## [3.6.2]
### Added
- Added support for the `ALPS Media Block` component.
### Fixed
- Fixed an issue with ALPS using the `l-grid-item--xl` classes as `l-grid-item--xxl`.


## [3.6.1]
### Fixed
- Fixed an issue with ALPS using the `l-grid-item--xl` classes as `l-grid-item--xxl`.


## [3.6.0]
### Added
- Added A caption to the page header - feature image. [#514](https://github.com/adventistchurch/alps-wordpress/issues/514)
- Added support for wide logos in the header. This is done with a checkbox in the theme settings page. [#535](https://github.com/adventistchurch/alps-wordpress/issues/535)


## [3.5.15]
### Added
- Added Russian localization.
- Added full width templates `page` and `post` types.

### Fixed
- Fixed support for `dynamic sidebar` so that plugins can replace widget areas. [#528](https://github.com/adventistchurch/alps-wordpress/issues/528)
- Fixed the `alps.pot` generation and the `es_ES.*` files to remove the ` msgctxt "alps" ` lines. [#516](https://github.com/adventistchurch/alps-wordpress/issues/516)


## [3.5.14]
### Added
- Added support for the `Embed Plus for YouTube – Gallery, Channel, Playlist, Live Stream` plugin and block.

## [3.5.13]
### Fixed
- Missing pagination on search results. [#526](https://github.com/adventistchurch/alps-wordpress/issues/526)

## [3.5.12]
### Fixed
- Added additional strings to the `.pot` file, bringing in some more files in the `/app` file.

## [3.5.11]
### Added
- Added a full-width page template. [#518](https://github.com/adventistchurch/alps-wordpress/issues/518)
### Fixed
- Clarified the language on the settings page for the ALPS core version.

## [3.5.10]
### Fixed
- Overwrite of Wordpress's `.has-drop-cap:not(:focus)::first-letter` style.

## [3.5.9]
### Fixed
- Removed `has-dropcap` classes from the single page.

## [3.5.8]
### Fixed
- Added Russian `.po` files. Thanks to @marianmaximciuc.

## [3.5.7]
### Fixed
- Theme i18n refactoring. [#506](https://github.com/adventistchurch/alps-wordpress/pull/506)

## [3.5.6]
### Fixed
- Add alternative jQuery version using `$.noConflict(true)` [#474](https://github.com/adventistchurch/alps-wordpress/pull/474)
- Fix for Navigation Drawer Accordion with ALPS 3.7.0. [#513](https://github.com/adventistchurch/alps-wordpress/pull/513)
- Removes the Drop cap checkbox in the ALPS Wordpress theme. We now revert back to using the Wordpress Gutenberg interface for the dropcap. [#509](https://github.com/adventistchurch/alps-wordpress/pull/509)

## [3.5.5]
### Fixed
- Fixed the double-spacing betwen paragraphs on articles. [#480](https://github.com/adventistchurch/alps/issues/480)
- Fixed issue with buttons that weren't outlined.

## [3.5.4]
### Fixed
- Fixed to properly format the localized post date. [#327](https://github.com/adventistchurch/alps-wordpress/issues/327)

## [3.5.3]
### Fixed
- Adding support for `gt3pg-pro/grid` block.

## [3.5.2]
### Fixed
- Adding support for more kadance, gt3-photo-video-gallery, uagb, and kioken blocks.

## [3.5.1]
### Fixed
- Removing Qubely block support and adding support for two Kadence blocks.

## [3.5.0]
### Added
- ALPS version selector to allow an admin to set the version of ALPS used, including a "latest" option.  [#484](https://github.com/adventistchurch/alps-wordpress/issues/484)

## [3.4.13]
### Fixed
- Fixed: BUG in breakout box right margin [#490](https://github.com/adventistchurch/alps-wordpress/issues/490)

## [3.4.12]
### Fixed
- Fixed: External comment systems not working. [#493](https://github.com/adventistchurch/alps-wordpress/issues/493)

## [3.4.11]
### Fixed
- Fixed: Custom Logo set with WPML not Showing in 3.4.0 and forward [#469](https://github.com/adventistchurch/alps-wordpress/issues/469)

## [3.4.10]
### Added
- Added: Switched the jquery CDN to Cloudflare's version after running extensive WebpageTest.com checks on the top 5 jQuery CDN's. The Cloudflare URL came it much a litte faster then GoogleAPis.com and a bit faster then the jquery.com version.

## [3.4.9]
### Added
- Added: Added support for select `gutentor` and `qubely` blocks.

## [3.4.8]
### Fixed
- Fixed: adding cache busting for WP css/js [#487](https://github.com/adventistchurch/alps-wordpress/issues/478)

## [3.4.7]
### Fixed
- Fixed: & in categories displays as `&amp;` [#481](https://github.com/adventistchurch/alps-wordpress/issues/481)
- Fixed: Category Posts Feed Label setting not working. [#466](https://github.com/adventistchurch/alps-wordpress/issues/466)
- Fixed: Sabbath column hide settings working incorrectly. [#479](https://github.com/adventistchurch/alps-wordpress/issues/479)

### Added
- Added: a checkbox to disable images on the `Related Images` sidebar.


## [3.4.6]
### Added
- Added: Support for a custom field called `schemamarkup` to output JSON schema markup on a page.

### Fixed
- Fixed: Fixed an issue with `aligncenter` not centering images.
- Fixed: CTA buttons on Related pages to include the outlines.

## [3.4.5]
### Added
- Added: Core Table and Core Columns blocks for Gutenberg.

## [3.4.4]
### Fixed
- Fixed: Fixed an error with the footer schema not using the correct `itemprop` name for the `postalCode`.
- Fixed: Custom fields being hidden to prevent Piklist error. [#140](https://github.com/adventistchurch/alps-wordpress/issues/140)

## [3.4.3]
### Fixed
- Fixed: Comments not showing. [#462](https://github.com/adventistchurch/alps-wordpress/issues/462)

## [3.4.2]
### Fixed
- Fixed: CTA button missing from Full screen hero. [#476](https://github.com/adventistchurch/alps-wordpress/issues/476)

## [3.4.1]
### Fixed
- Fixed: The theme was missing the `hide-sabbath` class on all instances of the Sabbath column being hidden. [#29](https://github.com/adventistchurch/alps-gutenberg-blocks/issues/29)

## [3.4.0]
### Added
- Added: Site admins can now customize the homepage title (when using recent posts), customize the posts title, and use the various header settings on custom posts listing pages. [#337](https://github.com/adventistchurch/alps-wordpress/issues/337)

## [3.3.0]
### Added
- Added: A new Media Testimonies block. [#448](https://github.com/adventistchurch/alps-wordpress/pull/448)
- Added: Link underlines on sidebar menus. [#440](https://github.com/adventistchurch/alps/issues/440)

## [3.2.2]
### Fixed
- Fix: Fixed links when the related pages were set to child pages and resolving to the page id and not the freindly url. [#460](https://github.com/adventistchurch/alps-wordpress/issues/460)

## [3.2.1]
### Fixed
- Fix: Fixed an error that broke the gallery. [#455](https://github.com/adventistchurch/alps-wordpress/issues/455)

## [3.2.0]
### Fixed
- Fix: Buttons not available in Wordpress 5.4. [#451](https://github.com/adventistchurch/alps-wordpress/issues/451)
- Fix: add support for multilanguage logos [#443](https://github.com/adventistchurch/alps-wordpress/issues/443)

### Added
- Added: New "Full screen image with text and header overlay" [#445](https://github.com/adventistchurch/alps-wordpress/issues/443)


## [3.1.3]
### Fixed
- Fix: Theme update notifications [#422](https://github.com/adventistchurch/alps-wordpress/issues/422)

### Added
- Added: Enable Separator block [#403](https://github.com/adventistchurch/alps-wordpress/issues/403)


## [3.1.2]
### Fixed
- Fix: 4-digit Numbers appears on page when adding a 'Footer Logo Icon' [#437](https://github.com/adventistchurch/alps-wordpress/issues/437)

## [3.1.1]
### Fixed
- Fix for comment form not rendering correctly [#433](https://github.com/adventistchurch/alps-wordpress/issues/433)

## [3.1.0]
### Added
- Added a Hero Carousel slideshow with a maximum number of 6 images. [#318](https://github.com/adventistchurch/alps-wordpress/issues/318)


## [3.0.34]
### Fixed
- Fixed Guidepost css with the change of the Guidepost plugin.

## [3.0.33]
### Fixed
- Fixed the footer message. [#424](https://github.com/adventistchurch/alps-wordpress/issues/424)
- Update the location and name for the Guidepost plugin. [#427](https://github.com/adventistchurch/alps-wordpress/issues/427)
- Fixed header to use h1's for media blocks in the header. [#414](https://github.com/adventistchurch/alps-wordpress/issues/424)

## [3.0.32]
### Fixed
- Fixed the footer zipcode location. [#416](https://github.com/adventistchurch/alps-wordpress/issues/416)

## [3.0.31]
### Fixed
- Fixed the navigation bar covering ALPS elements for logged in users. [#391](https://github.com/adventistchurch/alps-wordpress/issues/391)

## [3.0.30]
### Fixed
- Removed custom jQuery version. Now it depends on the Wordpress version. [#362](https://github.com/adventistchurch/alps-wordpress/issues/362)


## [3.0.29]
### Fixed
- Fix to the header menu. [#409](https://github.com/adventistchurch/alps-wordpress/issues/409)

## [3.0.28]
### Fixed
- Removed requirement for the Gutenberg plugin. [#387](https://github.com/adventistchurch/alps-wordpress/pull/387)
- Removes shortcodes from expanding in teasers. [#390](https://github.com/adventistchurch/alps-wordpress/pull/390)

### Add
- Added support for the ARVE/NextGenThemes embed block.
- Adding License.txt file.

## [3.0.27-beta]
### Fixed
- Fixing the version_compare function operator. [#385](https://github.com/adventistchurch/alps-wordpress/issues/385)
- Updating theme incorrectly stops at WP version check. [#384](https://github.com/adventistchurch/alps-wordpress/issues/384)
- Remove Classic Editor Requirement. [#379](https://github.com/adventistchurch/alps-wordpress/issues/379)
- Fixes the display of the Guidepost blocks.


## [3.0.26-beta]
### Fixed
- Fixed Theme cache files not working in some hosting. [#358](https://github.com/adventistchurch/alps-wordpress/issues/358)

## [3.0.25-beta]
### Fixed
- Fixed a bug with the sidebar hiding and 3-up grids. [#376](https://github.com/adventistchurch/alps-wordpress/issues/376)

## [3.0.24-beta]
### Fixed
- Restoring deleted Piklist files.

## [3.0.23-beta]
### Fixed
- Fixed WPML Language Switcher. [#292](https://github.com/adventistchurch/alps-wordpress/issues/292)
- Fixed Display of Special Characters in Title Causes Error. [#367](https://github.com/adventistchurch/alps-wordpress/issues/367)
- Fixed Hero image text hover animation missing [#312](https://github.com/adventistchurch/alps-wordpress/issues/312)
- Fixed Console errors. [#365](https://github.com/adventistchurch/alps-wordpress/issues/365)
- Fixed Sidebar not hiding. [#371](https://github.com/adventistchurch/alps-wordpress/issues/371)

## [3.0.22-beta]
### Added
- Adding support for Tertiary menus. [#341](https://github.com/adventistchurch/alps-wordpress/pull/341)

## [3.0.21-beta]
### Added
- Added support for class, title, target, description and XFN attributes on menu links. [#349](https://github.com/adventistchurch/alps-wordpress/pull/349)
- Removed Piklist and replaced it with Carbon Fields. This new version now works properly on Wordpress 5, not requiring the switching back and forth betwen the Classic and Block editors. [#310](https://github.com/adventistchurch/alps-wordpress/pull/310)

## [3.0.19-beta]
### Fixed
- Version increment to fix deployment.

## [3.0.18-beta]
### Added
- Added the shortcode block to the editor.
- Added a feature to the `Latest Posts` block that allows you filter posts by `tags`. [#336](https://github.com/adventistchurch/alps-wordpress/issues/336)

## [3.0.17-beta]
### Fixed
- Fixes the footer logo missing when the Sabbath column is hidden. [#319](https://github.com/adventistchurch/alps-wordpress/issues/319)

## [3.0.16-beta]
### Fixed
- Fixes the breakout block on the wrong grid alignment. [#307](https://github.com/adventistchurch/alps-wordpress/issues/307)

## [3.0.15-beta]
## Added
- Adds a Latest Posts block [#285](https://github.com/adventistchurch/alps-wordpress/issues/285)

## [3.0.14-beta]
## Added
- Added full support of dropdown menus in secondary menus. This fix came from ALPS core [#361](https://github.com/adventistchurch/alps/issues/361)

### Fixed
- Fixed WPML language menu not attaching correctly to the secondary menu. [#266](https://github.com/adventistchurch/alps-wordpress/issues/266)
- Fixed the version numbering adding the beta number at the end instead of in the 0.0.x position.


## [3.0.13-beta]
### Fixed
- Fixed an issue with the paragraphs block not displaying functioning correctly. This removes the custom block and goes back to the default core paragraphs block. [#282](https://github.com/adventistchurch/alps-wordpress/issues/282)


## [3.0.12-beta]
### Fixed
- Fixed an issue with i18n phrases not using the correct function. [#279](https://github.com/adventistchurch/alps-wordpress/issues/279)

## [3.0.10-beta]
### Fixed
- Fixed the version number in the styles.css.

## [3.0.9-beta]
### Added
- i18n (internationalization) `.pot` file for translation.
- `es.po` and `es.mo` translation files. from [@bertobox](https://github.com/bertobox)
- More Gutenberg block types: List, Video, HTML, embed, button.
- Ability to translate the `Learn More` menu title.
- Switched

### Changed
- Updated the site branding statement to be more generic and force people to change it.
- Clarified `Posts Sidebar` region to `Posts Template Sidebar`.
- Removed unused `Default Tempalte`.
- Switched the download url for `alps-gutenberg-blocks` to a kernl.us url to pull it from the packaged source.

### Fixed
- Added pagination.
- Error with WPML causing white screen [#266](https://github.com/adventistchurch/alps-wordpress/issues/266)
- The requirements for the Gutenberg plugins.
- Issue with related pages not pulling the correct teaser or honoring the format settings. [#257](https://github.com/adventistchurch/alps-wordpress/issues/257)


## [3.0.8-alpha]
### Added
- Many changes. This is the first release.
