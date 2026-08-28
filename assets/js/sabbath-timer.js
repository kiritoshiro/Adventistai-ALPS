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

  const labelNode = root.querySelector('[data-sabbath-label]');
  const countdownNode = root.querySelector('[data-sabbath-countdown]');
  const metaNode = root.querySelector('[data-sabbath-meta]');
  const selector = root.querySelector('[data-sabbath-selector]');
  const selectorButton = root.querySelector('[data-sabbath-selector-button]');
  const selectedCityNode = root.querySelector('[data-sabbath-selected-city]');
  const popover = root.querySelector('[data-sabbath-selector-popover]');
  const cityButtons = Array.from(root.querySelectorAll('[data-sabbath-city]'));
  const storageKey = 'adventistai-sabbath-city';

  let selectedCity = data.defaultCity && cities[data.defaultCity] ? data.defaultCity : cityKeys[0];
  try {
    const stored = window.localStorage.getItem(storageKey);
    if (stored && cities[stored]) selectedCity = stored;
  } catch (error) {}

  let currentEventTimestamp = null;

  const nextEvent = (cityKey, nowMs) => {
    const city = cities[cityKey];
    if (!city || !Array.isArray(city.events)) return null;
    return city.events.find((event) => Number(event.timestamp) * 1000 > nowMs) || null;
  };

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

  const formatTarget = (event, includeWeekday = true) => {
    const date = new Date(Number(event.timestamp) * 1000);
    const options = {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
      timeZone: data.timezone || 'Europe/Vilnius',
    };
    if (includeWeekday) options.weekday = 'long';
    return new Intl.DateTimeFormat('lt-LT', options).format(date);
  };

  const closeSelector = (restoreFocus = false) => {
    if (!popover || !selectorButton) return;
    popover.hidden = true;
    selectorButton.setAttribute('aria-expanded', 'false');
    if (restoreFocus) selectorButton.focus();
  };

  const openSelector = () => {
    if (!popover || !selectorButton) return;
    popover.hidden = false;
    selectorButton.setAttribute('aria-expanded', 'true');
    const selected = cityButtons.find((button) => button.getAttribute('data-sabbath-city') === selectedCity);
    if (selected) selected.focus();
  };

  const renderCityOptions = (nowMs) => {
    cityButtons.forEach((button) => {
      const cityKey = button.getAttribute('data-sabbath-city');
      const event = nextEvent(cityKey, nowMs);
      const timeNode = button.querySelector('[data-sabbath-city-time]');
      const isSelected = cityKey === selectedCity;

      button.classList.toggle('is-selected', isSelected);
      button.setAttribute('aria-selected', isSelected ? 'true' : 'false');

      if (timeNode) {
        if (event) {
          timeNode.textContent = formatTarget(event, false);
          timeNode.setAttribute('datetime', event.iso || '');
        } else {
          timeNode.textContent = '—';
          timeNode.removeAttribute('datetime');
        }
      }
    });
  };

  const renderStatic = (nowMs) => {
    const city = cities[selectedCity];
    const event = nextEvent(selectedCity, nowMs);

    if (!city || !event) {
      if (labelNode) labelNode.textContent = 'Sabatos laikas';
      if (countdownNode) countdownNode.textContent = '—';
      if (metaNode) metaNode.textContent = '';
      return null;
    }

    if (labelNode) {
      labelNode.textContent = event.type === 'end' ? 'Sabata baigiasi už:' : 'Sabata prasideda už:';
    }
    if (selectedCityNode) selectedCityNode.textContent = city.name;
    if (metaNode) metaNode.textContent = `· ${formatTarget(event)}`;

    currentEventTimestamp = Number(event.timestamp);
    renderCityOptions(nowMs);
    return event;
  };

  const tick = () => {
    const nowMs = Date.now();
    let event = nextEvent(selectedCity, nowMs);

    if (!event) {
      renderStatic(nowMs);
      return;
    }

    if (Number(event.timestamp) !== currentEventTimestamp) {
      event = renderStatic(nowMs);
      if (!event) return;
    }

    if (countdownNode) {
      countdownNode.textContent = formatCountdown(Number(event.timestamp) * 1000 - nowMs);
    }
  };

  if (selectorButton) {
    selectorButton.addEventListener('click', () => {
      const isOpen = selectorButton.getAttribute('aria-expanded') === 'true';
      if (isOpen) closeSelector(); else openSelector();
    });
  }

  cityButtons.forEach((button, index) => {
    button.addEventListener('click', () => {
      const cityKey = button.getAttribute('data-sabbath-city');
      if (!cityKey || !cities[cityKey]) return;

      selectedCity = cityKey;
      try {
        window.localStorage.setItem(storageKey, selectedCity);
      } catch (error) {}

      currentEventTimestamp = null;
      tick();
      closeSelector(true);
    });

    button.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
      event.preventDefault();
      const direction = event.key === 'ArrowDown' ? 1 : -1;
      cityButtons[(index + direction + cityButtons.length) % cityButtons.length].focus();
    });
  });

  document.addEventListener('click', (event) => {
    if (!selector || !popover || popover.hidden) return;
    if (!selector.contains(event.target)) closeSelector();
  });

  root.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && popover && !popover.hidden) {
      event.preventDefault();
      closeSelector(true);
    }
  });

  tick();
  window.setInterval(tick, 1000);
})();
