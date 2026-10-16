/*
 * Deferred images: hidden slides of the Latest Post Slider and the cards of a
 * card scroller beyond the first screen-width of its track.
 *
 * The theme prints these images with their files in data-alps-src /
 * data-alps-srcset (App\DeferredImages::defer()), so the page does not
 * download them all up front. This restores them.
 *
 * Sliders: the active slide and its two neighbours once the slider is within
 * 600px of the screen and again on every slide change, and the whole slider as
 * soon as someone hovers, touches or focuses it (a dot can jump to any slide).
 * It relies on the markup of resources/scripts/latest-post-sliders.js:
 * [data-alps-latest-slider], [data-alps-slider-slide] and the is-active class.
 *
 * Card scrollers ([data-card-scroller-track], App\CardScrollerBlock): a card
 * that shows inside the track (a track wider than expected), and, from the
 * first scroll of the track (its arrows and mouse drag scroll it too) or
 * hover, touch or focus on the block, every card within one track-width of
 * the visible part. The images stay loading="lazy", so the browser still
 * waits for the block to come near.
 *
 * tests/scripts/run.php checks that the hooks still match. Printed inline in
 * the footer, untranspiled, so it keeps to syntax every supported browser
 * parses.
 */
(function () {
  var deferred = '[data-alps-src], [data-alps-srcset]';

  var load = function (slide) {
    if (!slide) return;
    Array.prototype.forEach.call(slide.querySelectorAll(deferred), function (el) {
      if (el.hasAttribute('data-alps-srcset')) {
        el.setAttribute('srcset', el.getAttribute('data-alps-srcset'));
        el.removeAttribute('data-alps-srcset');
      }
      if (el.hasAttribute('data-alps-src')) {
        el.setAttribute('src', el.getAttribute('data-alps-src'));
        el.removeAttribute('data-alps-src');
      }
    });
  };

  Array.prototype.forEach.call(document.querySelectorAll('[data-alps-latest-slider]'), function (slider) {
    var slides = Array.prototype.slice.call(slider.querySelectorAll('[data-alps-slider-slide]'));
    if (!slider.querySelector(deferred)) return;
    var near = false;
    var observers = [];
    var interactions = ['pointerover', 'touchstart', 'focusin'];

    var done = function () {
      if (slider.querySelector(deferred)) return;
      observers.forEach(function (observer) { observer.disconnect(); });
      interactions.forEach(function (type) { slider.removeEventListener(type, loadAll); });
    };
    var loadAll = function () {
      slides.forEach(load);
      done();
    };
    var update = function () {
      if (!near) return;
      var active = -1;
      slides.forEach(function (slide, index) {
        if (slide.classList.contains('is-active')) active = index;
      });
      if (active < 0) return;
      [active, active + 1, active - 1].forEach(function (n) {
        load(slides[(n + slides.length) % slides.length]);
      });
      done();
    };

    interactions.forEach(function (type) { slider.addEventListener(type, loadAll, { passive: true }); });
    var mutations = new MutationObserver(update);
    mutations.observe(slider, { subtree: true, attributes: true, attributeFilter: ['class'] });
    observers.push(mutations);
    if (!('IntersectionObserver' in window)) {
      near = true;
      update();
      return;
    }
    var visibility = new IntersectionObserver(function (entries) {
      near = entries.some(function (entry) { return entry.isIntersecting; });
      update();
    }, { rootMargin: '600px 0px' });
    visibility.observe(slider);
    observers.push(visibility);
  });

  Array.prototype.forEach.call(document.querySelectorAll('[data-card-scroller-track]'), function (track) {
    var cards = Array.prototype.filter.call(track.children, function (card) {
      return card.querySelector(deferred);
    });
    if (!cards.length) return;
    if (!('IntersectionObserver' in window)) {
      cards.forEach(load);
      return;
    }
    var frame = track.parentElement || track;
    var observers = [];
    var interactions = ['pointerover', 'touchstart', 'focusin'];
    var ahead = false;

    var done = function () {
      if (track.querySelector(deferred)) return;
      observers.forEach(function (observer) { observer.disconnect(); });
      interactions.forEach(function (type) { frame.removeEventListener(type, loadAhead); });
      track.removeEventListener('scroll', loadAhead);
    };
    // Restore the cards within `margin` of the track's visible part.
    var watch = function (margin) {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          load(entry.target);
          observer.unobserve(entry.target);
        });
        done();
      }, { root: track, rootMargin: margin });
      cards.forEach(function (card) { observer.observe(card); });
      observers.push(observer);
    };
    var loadAhead = function () {
      if (ahead) return;
      ahead = true;
      interactions.forEach(function (type) { frame.removeEventListener(type, loadAhead); });
      track.removeEventListener('scroll', loadAhead);
      watch('0px 100%');
    };

    interactions.forEach(function (type) { frame.addEventListener(type, loadAhead, { passive: true }); });
    track.addEventListener('scroll', loadAhead, { passive: true });
    watch('0px');
  });
})();
