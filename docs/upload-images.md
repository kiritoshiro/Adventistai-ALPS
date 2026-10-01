# Upload image policy

New JPEG, PNG and AVIF attachments get three display files:

- a **full AVIF** capped at 1920 × 1920,
- one **`alps-small` AVIF** capped at 768 × 768,
- one **JPEG fallback** capped at 1920 × 1920, for browsers without AVIF. Transparent areas of PNG/AVIF sources are white in it, since JPEG has no transparency.

Aspect ratio is preserved, with no upscaling or hard crop. Small sources may need only one AVIF. A successfully replaced source is retained for seven days, then removed by the existing twice-daily alps_cron task. WP-Cron depends on traffic, so cleanup may run later. The live display image is never expired. Sources whose replacement or smaller image is missing or unreadable are retained and retried the next day. Recovery to the uploaded original is unavailable after cleanup; subsequent edits and regeneration use the retained display image.

JPEG/PNG convert to AVIF through WordPress core when the image editor supports it. This needs WordPress 6.5+ and an AVIF-capable GD/Imagick installation. Unsupported hosts keep the source format, which is then its own fallback, and no JPEG copy is made. GIF/WebP are excluded to avoid flattening animations. SVG and documents are unaffected. Legacy uploads and their regeneration keep their previous policy. Newly marked attachments keep this policy on regeneration, which reuses the existing JPEG fallback.

WordPress 6.x+ first saves a full-size converted copy (`name.avif`) and makes sub-sizes without a file name. The theme makes the small size AVIF in that case too. It reuses the copy as the display image when it is already small enough; otherwise it replaces the copy with the resized display image and deletes the copy.

## Shared record with WP Cleanup

The files are recorded in the attachment meta `_wpcu_image_outputs` (`jpeg`, `avif_full`, `avif_small`, their widths/heights, the `policy` and `by: alps-theme`). This is the same record the [WP Cleanup](https://github.com/kiritoshiro/wp-cleanup) plugin writes when it converts older images, so:

- the theme serves both the same way (see below);
- WP Cleanup lists new uploads as already converted, including during the seven days the original is retained, and its data check leaves the theme's marker alone.

When WP Cleanup is active and its saved image policy fits ALPS (`alps-small`, at most 1920/768 px), the theme uses that policy's sizes and JPEG quality, so both describe new uploads identically. Otherwise the defaults above apply. Themes or plugins can adjust them with the `alps_upload_image_policy` filter.

Deleting an attachment also deletes its JPEG fallback, unless another attachment uses that file.

## Delivery (`App\ImageDelivery`)

Converted images are served as `<picture>`: an AVIF `<source>` listing the small and full AVIF by width, and the JPEG as the `<img>`, with width and height to avoid layout shift. This applies to theme templates (cards, lists, carousels, headers), `wp_get_attachment_image()` output (featured images, blocks) and images in post content. Page-header backgrounds use `image-set()` with an AVIF and a JPEG line; browsers without `type()` support keep the JPEG. Open Graph images use the JPEG, since not every network reads AVIF. Images that were never converted keep WordPress's normal responsive markup.

Images are shown whole:

- **Headers and heroes** show the image at its own shape, capped at 80% of the screen height. A long page header with a background image takes the image's shape instead of a thin band. Square and portrait images are shown whole on the header colour.
- **Card grids and lists** keep one shape per slot (16:9, 4:3, 3:4 or square) so rows stay even. An image whose shape is close to the slot's (within 25%) fills it with a small trim. A very different shape, such as a portrait in a 16:9 card or a panorama, is shown whole on a light backdrop.
- **Round thumbnails** remain a filled circle, a deliberate crop.

Existing template size names resolve to the small and full files for new attachments only. Other plugins that generate files independently of core can bypass this policy. Core client-side media processing and media offload plugins need integration testing before deployment. Converted images are served from the site's own uploads URL, not through Jetpack's image CDN (i0.wp.com).

## Verification before release

Run `php tests/images/run.php`, `php tests/images/retention.php` and `php tests/search/run.php`. On a local WordPress site with this theme active, run `ALPS_TESTS=1 wp eval-file wp-content/themes/<theme>/tests/images/wordpress.php`. It uploads real images and checks the files, the shared record, the picture and background markup, regeneration, deletion, and WP Cleanup's view of the uploads when the plugin is active.

On staging, upload a large JPEG with EXIF rotation, a transparent PNG, a small JPEG, a portrait photo and an AVIF. Verify:

- orientation and transparency;
- attachment URLs and MIME types;
- srcset and actual dimensions;
- at most one `sizes` metadata entry (`alps-small`);
- a `-fallback.jpg` beside each AVIF upload.

Check the post header, card grid and featured image in a browser with and without AVIF support. Confirm that original recovery, deletion and regeneration work. Repeat on a host without AVIF support. Upload a GIF/WebP and regenerate a pre-existing attachment to confirm they are excluded. Check uploaded bytes and actual AVIF output, not just extensions.

## Existing duplicate cleanup (separate operation)

1. Back up uploads and the database. Inventory attachment IDs, original paths, dimensions, hashes, generated files and usages. Treat generated variants as children, not duplicate attachments.
2. Group byte-identical originals by SHA-256. Use perceptual hashes to flag recompressed/resized look-alikes for visual review; never automatically delete based on perceptual similarity.
3. Select the correct highest-quality original, keeping captions, alt text and crop intent. Map each redundant attachment ID and URL to the chosen attachment and its sizes.
4. Audit and update featured-image IDs, Gutenberg content, galleries, Carbon Fields, options, serialized metadata, CSS/background URLs and language variants using WordPress-aware APIs. Do not raw-replace serialized database strings.
5. Verify pages and external URL references before deletion. Remove redundant attachments with WordPress APIs only after review; keep redirects where published URLs change. Delete obsolete generated files only after checking references and attachment metadata. Do not run blanket thumbnail deletion or regeneration on production.

Retention applies only to managed attachments whose original metadata is saved after this feature is enabled. It does not bulk-enroll older originals. Editing backups and sources used as another attachment remain protected. A source that is itself the live display image (for example, a small AVIF or an unsupported conversion) is kept. Verify the alps_cron event is running on staging and confirm that original_image metadata is removed only after the source is deleted. Direct links to temporary original URLs expire too; use attachment display URLs. No duplicate attachments or legacy media are deleted by this feature.

The WP Cleanup plugin can convert older images to the same three files and remove their old sizes into a restorable backup.
