/**
 * alps/card-scroller front-end behaviour.
 *
 * Touchpads and touchscreens use native scrolling. This script only adds the
 * edge arrows and click-and-drag for a mouse. Edge state comes from an
 * IntersectionObserver, so nothing reads layout while the page is loading.
 */
(() => {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  const setup = (track) => {
    const frame = track.parentElement;
    const prev = frame.querySelector('[data-card-scroller-prev]');
    const next = frame.querySelector('[data-card-scroller-next]');
    // Optional link to the "more" page, shown in place of the next arrow at the end.
    const more = frame.querySelector('[data-card-scroller-more]');
    const items = track.children;
    if (!prev || !next || !items.length) return;

    const scrollByPage = (direction) => {
      track.scrollBy({
        left: direction * Math.max(220, Math.round(track.clientWidth * 0.85)),
        behavior: reduceMotion.matches ? 'auto' : 'smooth',
      });
    };

    prev.addEventListener('click', () => scrollByPage(-1));
    next.addEventListener('click', () => scrollByPage(1));

    // An arrow is shown while the card at its end is not fully in view. At
    // the end, the "more" link (if any) takes the next arrow's place.
    const atEnd = (end) => {
      next.hidden = end;
      if (more) more.hidden = !end;
    };
    if ('IntersectionObserver' in window) {
      const first = items[0];
      const last = items[items.length - 1];
      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          const atEdge = entry.intersectionRatio >= 0.98;
          if (entry.target === first) prev.hidden = atEdge;
          if (entry.target === last) atEnd(atEdge);
        });
      }, {root: track, threshold: [0, 0.98, 1]});

      observer.observe(first);
      if (last !== first) observer.observe(last);
      else {
        prev.hidden = true;
        atEnd(true);
      }
    }

    // Mouse drag only; other pointers keep native scrolling and momentum.
    let start = null;
    let dragged = false;

    track.addEventListener('pointerdown', (event) => {
      if (event.pointerType !== 'mouse' || event.button !== 0) return;
      start = {x: event.clientX, scrollLeft: track.scrollLeft};
      dragged = false;
    });

    track.addEventListener('pointermove', (event) => {
      if (!start) return;
      const distance = event.clientX - start.x;
      if (!dragged && Math.abs(distance) <= 4) return;
      if (!dragged) {
        dragged = true;
        track.classList.add('is-dragging');
        track.setPointerCapture(event.pointerId);
      }
      track.scrollLeft = start.scrollLeft - distance;
    });

    const stop = () => {
      start = null;
      track.classList.remove('is-dragging');
      // The click that follows pointerup is cancelled below; after that, a
      // keyboard-activated link must work again.
      if (dragged) window.setTimeout(() => { dragged = false; }, 0);
    };
    track.addEventListener('pointerup', stop);
    track.addEventListener('pointercancel', stop);

    // A drag must not open the card it started on.
    track.addEventListener('click', (event) => {
      if (!dragged) return;
      event.preventDefault();
      event.stopPropagation();
      dragged = false;
    }, true);

    track.addEventListener('dragstart', (event) => event.preventDefault());
  };

  document.querySelectorAll('[data-card-scroller-track]').forEach(setup);
})();
