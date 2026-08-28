(() => {
  'use strict';

  const root = document.querySelector('[data-sabbath-timer]');
  if (!root) return;

  const dataNode = root.querySelector('[data-sabbath-data]');
  if (!dataNode) return;

  let data;
  try {
    data = JSON.parse(dataNode.textContent || '{}');
  } catch (error) {
    return;
  }

  const cities = data.cities || {};
  const cityKeys = Object.keys(cities);
  if (!cityKeys.length) return;

  const countdownView = root.querySelector('[data-sabbath-countdown-view]');
  const activeView = root.querySelector('[data-sabbath-active-view]');
  const countdownNode = root.querySelector('[data-sabbath-countdown]');
  const startMetaNode = root.querySelector('[data-sabbath-start-meta]');
  const endMetaNode = root.querySelector('[data-sabbath-end-meta]');
  const verseTextNode = root.querySelector('[data-sabbath-verse-text]');
  const verseReferenceNode = root.querySelector('[data-sabbath-verse-reference]');

  const selector = root.querySelector('[data-sabbath-selector]');
  const selectorButton = root.querySelector('[data-sabbath-selector-button]');
  const selectedCityNode = root.querySelector('[data-sabbath-selected-city]');
  const popover = root.querySelector('[data-sabbath-selector-popover]');
  const cityButtons = Array.from(root.querySelectorAll('[data-sabbath-city]'));

  const activeSelector = root.querySelector('[data-sabbath-selector-active]');
  const activeSelectorButton = root.querySelector('[data-sabbath-selector-button-active]');
  const activeSelectedCityNode = root.querySelector('[data-sabbath-selected-city-active]');
  const activePopover = root.querySelector('[data-sabbath-selector-popover-active]');
  const activeCityButtons = Array.from(root.querySelectorAll('[data-sabbath-city-active]'));

  const storageKey = 'adventistai-sabbath-city';
  let selectedCity = data.defaultCity && cities[data.defaultCity] ? data.defaultCity : cityKeys[0];

  try {
    const stored = window.localStorage.getItem(storageKey);
    if (stored && cities[stored]) selectedCity = stored;
  } catch (error) {}

  const pad2 = (value) => String(value).padStart(2, '0');

  const formatCountdown = (milliseconds) => {
    let seconds = Math.max(0, Math.floor(milliseconds / 1000));
    const days = Math.floor(seconds / 86400);
    seconds -= days * 86400;
    const hours = Math.floor(seconds / 3600);
    seconds -= hours * 3600;
    const minutes = Math.floor(seconds / 60);
    seconds -= minutes * 60;
    const clock = `${pad2(hours)}:${pad2(minutes)}:${pad2(seconds)}`;
    return days > 0 ? `${days} d. ${clock}` : clock;
  };

  const formatTime = (event) => {
    if (!event) return '—';
    return new Intl.DateTimeFormat('lt-LT', {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
      timeZone: data.timezone || 'Europe/Vilnius',
    }).format(new Date(Number(event.timestamp) * 1000));
  };

  const cityState = (cityKey, nowMs) => {
    const city = cities[cityKey];
    const events = city && Array.isArray(city.events) ? city.events : [];
    if (!events.length) return { mode: 'hidden', start: null, end: null };

    for (let i = 0; i < events.length; i += 1) {
      const event = events[i];
      if (event.type !== 'start') continue;

      const startMs = Number(event.timestamp) * 1000;
      const revealMs = Number(event.revealTimestamp || event.timestamp) * 1000;
      const end = events.slice(i + 1).find((candidate) => candidate.type === 'end');
      const endMs = end ? Number(end.timestamp) * 1000 : null;

      if (nowMs >= revealMs && nowMs < startMs) {
        return { mode: 'countdown', start: event, end };
      }

      if (endMs && nowMs >= startMs && nowMs < endMs) {
        return { mode: 'active', start: event, end };
      }
    }

    return { mode: 'hidden', start: null, end: null };
  };

  const closePopover = (button, panel, restoreFocus = false) => {
    if (!button || !panel) return;
    panel.hidden = true;
    button.setAttribute('aria-expanded', 'false');
    if (restoreFocus) button.focus();
  };

  const openPopover = (button, panel, buttons, attr) => {
    if (!button || !panel) return;
    panel.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    const selected = buttons.find((item) => item.getAttribute(attr) === selectedCity);
    if (selected) selected.focus();
  };

  const updateCityChoice = (cityKey) => {
    if (!cityKey || !cities[cityKey]) return;
    selectedCity = cityKey;
    try {
      window.localStorage.setItem(storageKey, selectedCity);
    } catch (error) {}
    render();
  };

  const renderOptions = (nowMs) => {
    cityButtons.forEach((button) => {
      const cityKey = button.getAttribute('data-sabbath-city');
      const state = cityState(cityKey, nowMs);
      const timeNode = button.querySelector('[data-sabbath-city-time]');
      const isSelected = cityKey === selectedCity;
      button.classList.toggle('is-selected', isSelected);
      button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
      if (timeNode) timeNode.textContent = state.start ? formatTime(state.start) : '—';
    });

    activeCityButtons.forEach((button) => {
      const cityKey = button.getAttribute('data-sabbath-city-active');
      const state = cityState(cityKey, nowMs);
      const timeNode = button.querySelector('[data-sabbath-city-end-time]');
      const isSelected = cityKey === selectedCity;
      button.classList.toggle('is-selected', isSelected);
      button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
      if (timeNode) timeNode.textContent = state.end ? formatTime(state.end) : '—';
    });
  };

  const renderVerse = () => {
    const verses = Array.isArray(data.verses) ? data.verses : [];
    const verse = verses[0] || null;
    if (!verse) return;
    if (verseTextNode) verseTextNode.textContent = verse.text || '';
    if (verseReferenceNode) verseReferenceNode.textContent = verse.reference || '';
  };

  const render = () => {
    const nowMs = Date.now();
    const city = cities[selectedCity];
    const state = cityState(selectedCity, nowMs);

    root.hidden = state.mode === 'hidden';
    if (countdownView) countdownView.hidden = state.mode !== 'countdown';
    if (activeView) activeView.hidden = state.mode !== 'active';

    if (selectedCityNode) selectedCityNode.textContent = city.name;
    if (activeSelectedCityNode) activeSelectedCityNode.textContent = city.name;

    if (state.mode === 'countdown' && state.start) {
      if (countdownNode) countdownNode.textContent = formatCountdown(Number(state.start.timestamp) * 1000 - nowMs);
      if (startMetaNode) startMetaNode.textContent = `· pradžia ${formatTime(state.start)}`;
    }

    if (state.mode === 'active' && state.end) {
      renderVerse();
      if (endMetaNode) endMetaNode.textContent = `· Šabas baigiasi ${formatTime(state.end)}`;
    }

    renderOptions(nowMs);
  };

  if (selectorButton) {
    selectorButton.addEventListener('click', () => {
      const isOpen = selectorButton.getAttribute('aria-expanded') === 'true';
      if (isOpen) closePopover(selectorButton, popover);
      else openPopover(selectorButton, popover, cityButtons, 'data-sabbath-city');
    });
  }

  if (activeSelectorButton) {
    activeSelectorButton.addEventListener('click', () => {
      const isOpen = activeSelectorButton.getAttribute('aria-expanded') === 'true';
      if (isOpen) closePopover(activeSelectorButton, activePopover);
      else openPopover(activeSelectorButton, activePopover, activeCityButtons, 'data-sabbath-city-active');
    });
  }

  cityButtons.forEach((button, index) => {
    button.addEventListener('click', () => {
      updateCityChoice(button.getAttribute('data-sabbath-city'));
      closePopover(selectorButton, popover, true);
    });
    button.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
      event.preventDefault();
      const direction = event.key === 'ArrowDown' ? 1 : -1;
      cityButtons[(index + direction + cityButtons.length) % cityButtons.length].focus();
    });
  });

  activeCityButtons.forEach((button, index) => {
    button.addEventListener('click', () => {
      updateCityChoice(button.getAttribute('data-sabbath-city-active'));
      closePopover(activeSelectorButton, activePopover, true);
    });
    button.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
      event.preventDefault();
      const direction = event.key === 'ArrowDown' ? 1 : -1;
      activeCityButtons[(index + direction + activeCityButtons.length) % activeCityButtons.length].focus();
    });
  });

  document.addEventListener('click', (event) => {
    if (selector && popover && !popover.hidden && !selector.contains(event.target)) {
      closePopover(selectorButton, popover);
    }
    if (activeSelector && activePopover && !activePopover.hidden && !activeSelector.contains(event.target)) {
      closePopover(activeSelectorButton, activePopover);
    }
  });

  root.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (popover && !popover.hidden) closePopover(selectorButton, popover, true);
    if (activePopover && !activePopover.hidden) closePopover(activeSelectorButton, activePopover, true);
  });

  render();
  window.setInterval(render, 1000);
})();
