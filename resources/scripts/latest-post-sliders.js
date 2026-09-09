const sliderSelector = '[data-alps-latest-slider]';

const setupLatestPostSlider = (slider) => {
  const slides = Array.from(slider.querySelectorAll('[data-alps-slider-slide]'));
  const dots = Array.from(slider.querySelectorAll('[data-alps-slider-dot]'));

  if (slides.length < 2) {
    return;
  }

  const interval = Math.max(2000, Number(slider.dataset.alpsSliderInterval || 5) * 1000);
  const autoplay = slider.dataset.alpsSliderAutoplay !== 'false';
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let activeIndex = 0;
  let timer = null;

  const setActiveSlide = (nextIndex) => {
    activeIndex = (nextIndex + slides.length) % slides.length;

    slides.forEach((slide, index) => {
      const isActive = index === activeIndex;
      slide.classList.toggle('is-active', isActive);
      slide.setAttribute('aria-hidden', isActive ? 'false' : 'true');
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

    if (!autoplay || reduceMotion.matches || document.hidden) {
      return;
    }

    timer = window.setInterval(() => setActiveSlide(activeIndex + 1), interval);
  };

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

  slider.addEventListener('mouseenter', stop);
  slider.addEventListener('mouseleave', start);
  slider.addEventListener('focusin', stop);
  slider.addEventListener('focusout', (event) => {
    if (!slider.contains(event.relatedTarget)) {
      start();
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
