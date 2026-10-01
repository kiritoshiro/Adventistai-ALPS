# Card scroller block

`alps/card-scroller` (editor title **Kortelių slankiklis**) is a horizontally scrolling row of linked cards. It replaces the hand-written books and useful-sites snippets that were pasted into the front page as HTML, CSS and JavaScript.

- **Layouts:** `cover` for book covers (portrait image, title) and `logo` for partner logos (logo, title, short description).
- **Optional extras:** a linked heading above the row and a final "Daugiau" card.
- **Editing:** cards are edited in the block editor. Images are chosen from the media library, links are entered per card, and cards can be reordered or removed.
- **Rendering:** the block is rendered by `App\CardScrollerBlock`. Images go through `wp_get_attachment_image()`, so they get `srcset`, `width`/`height` and lazy loading. A card that only has an image URL is matched to its attachment once and the result is cached for a week.
- **Assets:** `assets/css/card-scroller.css` and the deferred `assets/js/card-scroller.js` load only on pages that contain the block. Touchpads and touchscreens scroll natively. The script adds edge arrows, using an `IntersectionObserver` so it never reads layout during page load, and mouse dragging.

## Replacing the front-page snippets

In the front page's Code editor, replace each snippet (`<!-- Books Showcase -->` … `</script>` and `<!-- Naudingų svetainių slankiklis -->` … `</script>`) with the matching block below. Then switch back to the Visual editor and check the cards. Choosing an image again from the media library is optional; it stores the attachment ID and skips the URL lookup.

### Books

```html
<!-- wp:alps/card-scroller {"heading":"Knygos","headingUrl":"https://adventistai.lt/knygos/","items":[{"imageUrl":"https://adventistai.lt/wp-content/uploads/Patriarchai-ir-pranasai.jpeg","title":"Patriarchai ir pranašai","url":"https://adventistai.lt/patriarchai-ir-pranasai/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/pranasai-ir-karaliai-638-937.png","title":"Pranašai ir karaliai","url":"https://adventistai.lt/pranasai-ir-karaliai/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/su-meile-is-dangaus-638-937.png","title":"Su meile iš Dangaus","url":"https://adventistai.lt/su-meile-is-dangaus/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/Apastalu-darbai-640x940-1.jpeg","title":"Apaštalų darbai","url":"https://adventistai.lt/apastalu-darbai/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/Didzioji-Kova-su-pav.jpeg","title":"Didžioji kova","url":"https://adventistai.lt/didzioji-kova/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/Sudetingu-biblijos-vietu-paaiskinimai-640x940-1.jpg","title":"Sudėtingų Biblijos vietų paaiškinimai","url":"https://adventistai.lt/sudetingu-biblijos-vietu-paaiskinimai/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/Biblijos-pazadai-virselis-2024-640x940-1.jpeg","title":"Mažoji Dievo pažadų knygelė","url":"https://adventistai.lt/mazoji-dievo-pazadu-knygele/","alt":"Mažoji Dievo pažadų knygelė vaikams"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/Marijos-sunaus-gyvenimas.jpeg","title":"Marijos Sūnaus gyvenimas","url":"https://adventistai.lt/marijos-sunaus-gyvenimas/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/Kelias-pas-Kristu-640x940-1.jpeg","title":"Kelias pas Kristų","url":"https://adventistai.lt/kelias-pas-kristu/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/Kristaus-kalno-pamokslas-638-937.png","title":"Kristaus kalno pamokslas","url":"https://adventistai.lt/kristaus-kalno-pamokslas/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/paslepti-lobiai-638-937.png","title":"Paslėpti lobiai","url":"https://adventistai.lt/paslepti-lobiai/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/Ugdymas.jpeg","title":"Ugdymas","url":"https://adventistai.lt/ugdymas/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/Geriausias-musu-Draugas-640x940-1.jpeg","title":"Geriausias mūsų Draugas","url":"https://adventistai.lt/geriausias-musu-draugas/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/Sabatos-Sventimas.jpeg","title":"Sabatos šventimas","url":"https://adventistai.lt/sabatos-sventimas/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/Viespaties-vynuogynas.jpeg","title":"Viešpaties vynuogynas","url":"https://adventistai.lt/viespaties-vynuogynas/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/gydymmo-tarnyste-638-937.png","title":"Gydymo tarnystė","url":"https://adventistai.lt/gydymo-tarnyste/"},{"imageUrl":"https://adventistai.lt/wp-content/uploads/Viltis-uzklupus-negandoms.jpg","title":"Viltis užklupus negandoms","url":"https://adventistai.lt/viltis-uzklupus-negandoms/"}]} /-->
```

### Useful sites

```html
<!-- wp:alps/card-scroller {"layout":"logo","items":[{"imageUrl":"https://adventistai.lt/wp-content/uploads/Bibletools.info_.jpeg","title":"BibleTools.info","description":"Biblijos studijos","url":"https://bibletools.info/","newTab":true},{"imageUrl":"https://adventistai.lt/wp-content/uploads/EGW-Writings-logo.webp","title":"EGW Writings","description":"E. Vait raštai","url":"https://whiteestate.org/resources/apps/","newTab":true},{"imageUrl":"https://adventistai.lt/wp-content/uploads/sabbath-school.webp","title":"Sabatos mokykla","description":"Biblijos pamokos","url":"https://sabbath-school.adventech.io/lt","newTab":true},{"imageUrl":"https://adventistai.lt/wp-content/uploads/Logo-LBD.png","title":"Mano Biblija","description":"Biblija internete","url":"https://www.manobiblija.lt/biblija/RUB%7CKAV/GEN.1","newTab":true},{"imageUrl":"https://adventistai.lt/wp-content/uploads/edeno-aidai-logo.png","title":"Edeno Aidai","description":"Giesmynas","url":"https://giesmynas.adventistai.lt/","newTab":true},{"imageUrl":"https://adventistai.lt/wp-content/uploads/ANN-1.jpg","title":"Adventist News Network","description":"Bažnyčios naujienos","url":"https://adventist.news/","newTab":true},{"imageUrl":"https://adventistai.lt/wp-content/uploads/ar-full-logo.webp","title":"Adventist Review","description":"Bažnyčios žurnalas","url":"https://adventistreview.org/","newTab":true},{"imageUrl":"https://adventistai.lt/wp-content/uploads/BRI.jpeg","title":"Biblical Research Institute","description":"Teologiniai tyrimai","url":"https://adventistbiblicalresearch.org/","newTab":true}],"moreLabel":"Daugiau","moreUrl":"https://adventistai.lt/naudingos-nurodos/"} /-->
```

## Verification before release

Check on staging at 320, 375, 768 and 1280 px:

- Arrows appear only when more cards are off-screen and disappear at each end.
- Mouse dragging scrolls without opening a card.
- Touch and touchpad scrolling stay native, and vertical page scrolling still works over the row.
- Keyboard: Tab reaches every card and both arrows.
- No layout shift as images load.
