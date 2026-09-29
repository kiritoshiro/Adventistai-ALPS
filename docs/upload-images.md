# Upload image policy

New JPEG, PNG and AVIF attachments use a full image capped at 1920 × 1920 and one `alps-small` image capped at 768 × 768. Aspect ratio is preserved, with no upscaling or hard crop. Small sources may need only one display file. WordPress keeps its recovery original separately; this is not a strict two-files-on-disk policy.

JPEG/PNG convert to AVIF through WordPress core when the image editor supports it. Require WordPress 6.5+ and an AVIF-capable GD/Imagick installation for AVIF output. Unsupported hosts retain the source format. GIF/WebP are excluded to avoid flattening animations. SVG and documents are unaffected. Legacy uploads and their regeneration retain their previous policy. Newly marked attachments retain the two-size policy on regeneration.

Existing template size names resolve to the small/full files for new attachments only. Images are no longer cropped to each old aspect ratio: check portrait cards, heroes, galleries and custom blocks on staging. Other plugins that generate files independently of core can bypass this policy. Core client-side media processing and media offload plugins require integration verification before deployment.

## Verification before release

Run `php tests/images/run.php` and `php tests/search/run.php`. On staging upload a large JPEG with EXIF rotation, transparent PNG, small JPEG, portrait photo and AVIF. Verify orientation, transparency, attachment URLs/MIME, srcset, actual dimensions and at most one `sizes` metadata entry (`alps-small`). Confirm original recovery, deletion and regeneration work. Repeat on a host without AVIF support. Upload GIF/WebP and regenerate a pre-existing attachment to confirm exclusion. Check uploaded bytes and actual AVIF output, not just extensions.

## Existing duplicate cleanup (separate operation)

1. Back up uploads and the database. Inventory attachment IDs, original paths, dimensions, hashes, generated files and usages. Treat generated variants as children, not duplicate attachments.
2. Group byte-identical originals by SHA-256. Use perceptual hashes to flag recompressed/resized lookalikes for visual review; never automatically delete based on perceptual similarity.
3. Select the correct highest-quality original, keeping captions, alt text and crop intent. Map each redundant attachment ID and URL to the chosen attachment and its sizes.
4. Audit and update featured-image IDs, Gutenberg content, galleries, Carbon Fields, options, serialized metadata, CSS/background URLs and language variants using WordPress-aware APIs. Do not raw-replace serialized database strings.
5. Verify pages and external URL references before deletion. Remove redundant attachments with WordPress APIs only after review; retain redirects where published URLs change. Delete obsolete generated files only after checking references and attachment metadata. Do not run blanket thumbnail deletion or regeneration on production.

No existing media is scanned, replaced or deleted by this feature.
