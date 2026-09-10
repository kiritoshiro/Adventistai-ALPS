# Image with wrapping text

Available in Adventistai 3.26.0 and later as **Vaizdas su aptekančiu tekstu**
(search `vaizdas`, `image`, or `wrap` in the block inserter).

Choose a media-library image or enter an image URL in the block settings, then
write or paste paragraphs inside the block. Headings, lists and quotes are also
supported. Move existing text blocks into this block using Gutenberg's List View.
Text outside this block does not wrap around its image.

The image floats left when the block itself is at least 40em wide. Text uses the
right side and returns to the full width underneath the image and caption. Below
40em the image centers above the text. The threshold follows the available block
width and font size, including narrow columns and browser zoom. Browsers without
container-query support use the centered layout. Words are not forcibly split or
hyphenated; unusually long unbroken strings can extend beyond the reading area.

## WPBridge / WordPress REST API

The registered block name is `alps/wrapped-image-text`. It uses standard Gutenberg
block comments in the post's `content`, so a bridge that can write raw WordPress
post content can insert it without a custom endpoint. Do not insert an HTML
figure yourself: the server renders it from the attributes. Keep real Gutenberg
paragraph/heading/list/quote blocks inside the wrapper, not escaped JSON text.

```html
<!-- wp:alps/wrapped-image-text {"imageId":123,"alt":"Describe the photograph","imageWidth":32,"maxImageWidth":320} -->
<!-- wp:paragraph -->
<p>First paragraph next to the image.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>Continue the article here. Longer text automatically fills the full width below the image.</p>
<!-- /wp:paragraph -->
<!-- /wp:alps/wrapped-image-text -->
```

Replace `123` with the actual WordPress attachment ID. Alternatively omit
`imageId` and set `imageUrl` to an HTTP(S) image URL. The media ID takes precedence;
the URL is a fallback. `imageId` alone also resolves the image in the editor.

| Attribute | Type | Default | Meaning |
| --- | --- | --- | --- |
| imageId | number | 0 | WordPress image attachment ID |
| imageUrl | string | empty | Image URL fallback |
| alt | string | empty | Image description; empty for decorative images |
| caption | string | empty | Optional plain-text caption |
| imageWidth | number | 32 | Percentage width while floated, clamped to 20–45 |
| maxImageWidth | number | 320 | Maximum image width in pixels, clamped to 160–600 |

Installing the release makes the block available; existing articles are not
automatically converted. When converting an article through WPBridge, retrieve
its current raw content first, preserve its text and metadata, and replace only
the intended image/text section. Preview the result before publishing.
