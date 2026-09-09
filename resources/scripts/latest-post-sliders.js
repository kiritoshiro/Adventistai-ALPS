const sliderSelector = '[data-alps-latest-slider]';

const setupLatestPostSlider = (slider) => {
  if (slider.dataset.alpsSliderInitialized === 'true') return;
  const slides = Array.from(slider.querySelectorAll('[data-alps-slider-slide]'));
  const dots = Array.from(slider.querySelectorAll('[data-alps-slider-dot]'));

  if (slides.length < 2) {
    return;
  }
  slider.dataset.alpsSliderInitialized = 'true';

  const interval = Math.max(2000, Number(slider.dataset.alpsSliderInterval || 5) * 1000);
  const autoplay = slider.dataset.alpsSliderAutoplay !== 'false';
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let activeIndex = 0;
  let timer = null;
  let hovered = false;
  let gesture = null;
  let suppressClickUntil = 0;

  const setActiveSlide = (nextIndex) => {
    activeIndex = (nextIndex + slides.length) % slides.length;

    slides.forEach((slide, index) => {
      const isActive = index === activeIndex;
      slide.classList.toggle('is-active', isActive);
      slide.setAttribute('aria-hidden', isActive ? 'false' : 'true');
      slide.inert = !isActive;
    });

    dots.forEach((dot, index) => {
      const isActive = index === activeIndex;
      dot.classList.toggle('is-active', isActive);
      dot.setAttribute('aria-selected', isActive ? 'true' : 'false');
      dot.setAttribute('tabindex', isActive ? '0' : '-1');
    });
  };

  const stop = () => {
    if (timer) {
      window.clearInterval(timer);
      timer = null;
    }
  };

  const start = () => {
    stop();

    if (!autoplay || reduceMotion.matches || document.hidden || hovered || gesture || slider.contains(document.activeElement)) {
      return;
    }

    timer = window.setInterval(() => setActiveSlide(activeIndex + 1), interval);
  };

  const navigate = (direction) => {
    setActiveSlide(activeIndex + direction);
    start();
  };

  slider.querySelector('[data-alps-slider-prev]')?.addEventListener('click', () => navigate(-1));
  slider.querySelector('[data-alps-slider-next]')?.addEventListener('click', () => navigate(1));

  const viewport = slider.querySelector('[data-alps-slider-viewport]');
  viewport?.addEventListener('touchstart', (event) => {
    suppressClickUntil = 0;
    gesture = event.touches.length === 1
      ? { x: event.touches[0].clientX, y: event.touches[0].clientY, id: event.touches[0].identifier }
      : null;
    stop();
  }, { passive: true });

  viewport?.addEventListener('touchend', (event) => {
    const origin = gesture;
    gesture = null;
    const touch = Array.from(event.changedTouches).find((item) => item.identifier === origin?.id);
    if (origin && touch && event.touches.length === 0) {
      const dx = touch.clientX - origin.x;
      const dy = touch.clientY - origin.y;
      if (Math.abs(dx) >= 50 && Math.abs(dx) > Math.abs(dy) * 1.3) {
        // A swipe on the linked card must not also open the post.
        suppressClickUntil = Date.now() + 500;
        navigate(dx < 0 ? 1 : -1);
      }
    }
    start();
  }, { passive: true });

  viewport?.addEventListener('touchcancel', () => {
    gesture = null;
    start();
  }, { passive: true });

  viewport?.addEventListener('click', (event) => {
    if (Date.now() < suppressClickUntil) {
      event.preventDefault();
      event.stopPropagation();
    }
  }, true);

  dots.forEach((dot, index) => {
    dot.addEventListener('click', () => {
      setActiveSlide(index);
      start();
    });

    dot.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowRight' && event.key !== 'ArrowDown' && event.key !== 'ArrowLeft' && event.key !== 'ArrowUp') {
        return;
      }

      event.preventDefault();
      const direction = event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 1;
      const nextIndex = (index + direction + dots.length) % dots.length;
      dots[nextIndex].focus();
      setActiveSlide(nextIndex);
      start();
    });
  });

  slider.addEventListener('mouseenter', () => { hovered = true; stop(); });
  slider.addEventListener('mouseleave', () => { hovered = false; start(); });
  slider.addEventListener('focusin', stop);
  slider.addEventListener('focusout', (event) => {
    if (!slider.contains(event.relatedTarget)) {
      window.setTimeout(start, 0);
    }
  });

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stop();
    } else {
      start();
    }
  });

  if (typeof reduceMotion.addEventListener === 'function') {
    reduceMotion.addEventListener('change', start);
  }

  setActiveSlide(0);
  start();
};

export const initLatestPostSliders = (root = document) => {
  root.querySelectorAll(sliderSelector).forEach(setupLatestPostSlider);
};

export default initLatestPostSliders;
