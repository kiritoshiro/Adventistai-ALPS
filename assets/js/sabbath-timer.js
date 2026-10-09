(() => {
  'use strict';

  // Sabbath start and end are calculated here from each city's coordinates,
  // so the page carries only the city list, not weeks of times per city.
  // Apparent sunset, Sun's centre 50' below the horizon (90°50' zenith);
  // the same formula the theme computed in PHP until 3.31.1.
  const rad = (degrees) => (degrees / 180) * Math.PI;
  const deg = (radians) => (radians / Math.PI) * 180;
  const normalize = (degrees) => {
    const value = degrees % 360;
    return value < 0 ? value + 360 : value;
  };

  // Unix seconds of sunset on a calendar date (month 1-12), or null.
  const sunset = (year, month, day, lat, lon) => {
    const midnight = Date.UTC(year, month - 1, day);
    const dayOfYear = Math.round((midnight - Date.UTC(year, 0, 1)) / 86400000) + 1;
    const lonHour = lon / 15;
    const t = dayOfYear + (18 - lonHour) / 24;
    const anomaly = 0.9856 * t - 3.289;
    const trueLon = normalize(anomaly + 1.916 * Math.sin(rad(anomaly)) + 0.02 * Math.sin(rad(2 * anomaly)) + 282.634);
    let ascension = normalize(deg(Math.atan(0.91764 * Math.tan(rad(trueLon)))));
    ascension = (ascension + Math.floor(trueLon / 90) * 90 - Math.floor(ascension / 90) * 90) / 15;
    const sinDec = 0.39782 * Math.sin(rad(trueLon));
    const cosDec = Math.cos(Math.asin(sinDec));
    const cosHour = (Math.cos(rad(90.833333)) - sinDec * Math.sin(rad(lat))) / (cosDec * Math.cos(rad(lat)));
    if (cosHour < -1 || cosHour > 1) return null;
    let utc = (deg(Math.acos(cosHour)) / 15 + ascension - 0.06571 * t - 6.622 - lonHour) % 24;
    if (utc < 0) utc += 24;
    const seconds = Math.round(utc * 3600);
    return midnight / 1000 + (seconds >= 86400 ? 0 : seconds);
  };

  // Wall-clock parts of an instant in a time zone, and the reverse.
  const formatters = {};
  const zoned = (ms, zone) => {
    formatters[zone] = formatters[zone] || new Intl.DateTimeFormat('en-GB', {
      timeZone: zone, hourCycle: 'h23', year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric',
    });
    const parts = {};
    formatters[zone].formatToParts(new Date(ms)).forEach((part) => { parts[part.type] = Number(part.value); });
    return parts;
  };
  const zonedSeconds = (year, month, day, hour, zone) => {
    const guess = Date.UTC(year, month - 1, day, hour);
    const at = zoned(guess, zone);
    const offset = Date.UTC(at.year, at.month - 1, at.day, at.hour, at.minute, at.second) - guess;
    return (guess - offset) / 1000;
  };

  // Last week's, this week's and next week's Friday and Saturday sunsets
  // (weeks start on Monday in the time zone); a start is revealed at
  // revealHour on its Friday.
  const weekEvents = (lat, lon, nowMs, zone, revealHour) => {
    const today = zoned(nowMs, zone);
    const base = Date.UTC(today.year, today.month - 1, today.day);
    const monday = base - ((new Date(base).getUTCDay() || 7) - 1) * 86400000;
    const events = [];
    for (let week = -1; week <= 1; week += 1) {
      const date = (offset) => {
        const value = new Date(monday + (week * 7 + offset) * 86400000);
        return [value.getUTCFullYear(), value.getUTCMonth() + 1, value.getUTCDate()];
      };
      const friday = date(4);
      const start = sunset(...friday, lat, lon);
      const end = sunset(...date(5), lat, lon);
      if (start !== null) events.push({ type: 'start', timestamp: start, revealTimestamp: zonedSeconds(...friday, revealHour, zone) });
      if (end !== null) events.push({ type: 'end', timestamp: end });
    }
    return events.sort((a, b) => a.timestamp - b.timestamp);
  };

  // tests/sabbath/sunsets.cjs checks the calculation against the PHP results.
  if (typeof module === 'object' && module && module.exports) {
    module.exports = { sunset, weekEvents };
    return;
  }

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

  // render() runs every second for 40 city options; build the formatter once.
  const timeFormatter = new Intl.DateTimeFormat('lt-LT', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone: data.timezone || 'Europe/Vilnius',
  });

  const formatTime = (event) => {
    if (!event) return '—';
    return timeFormatter.format(new Date(Number(event.timestamp) * 1000));
  };

  // Events per city, recalculated once a day.
  const zone = data.timezone || 'Europe/Vilnius';
  const revealHour = Number(data.fridayRevealHour) || 0;
  let eventsDay = '';
  let eventsByCity = {};
  const cityEvents = (cityKey, nowMs) => {
    const today = zoned(nowMs, zone);
    const day = `${today.year}-${today.month}-${today.day}`;
    if (day !== eventsDay) {
      eventsDay = day;
      eventsByCity = {};
    }
    const city = cities[cityKey];
    if (!eventsByCity[cityKey] && city) {
      eventsByCity[cityKey] = weekEvents(Number(city.lat), Number(city.lon), nowMs, zone, revealHour);
    }
    return eventsByCity[cityKey] || [];
  };

  const cityState = (cityKey, nowMs) => {
    const events = cityEvents(cityKey, nowMs);
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

  // The city list opens in the browser's top layer (popover="manual") where
  // supported. The page header clips its overflow and sits in a stacking
  // context below the content after it, so on phones a list positioned
  // inside it was cut off or covered. In the top layer it is placed under
  // its button with fixed coordinates and kept inside the viewport.
  const topLayer = typeof HTMLElement === 'function' && typeof HTMLElement.prototype.showPopover === 'function';
  if (topLayer) {
    [popover, activePopover].forEach((panel) => {
      if (panel) panel.setAttribute('popover', 'manual');
    });
  }

  const placePopover = (button, panel) => {
    const edge = 8;
    const gap = 10;
    const rect = button.getBoundingClientRect();
    const phone = window.matchMedia('(max-width: 760px)').matches;
    const width = Math.min(Math.max(panel.offsetWidth, phone ? 280 : 240), window.innerWidth - 2 * edge);
    // Under the button's left edge on phones, its right edge on wider screens (as before).
    const start = phone ? rect.left : rect.right - width;
    const below = window.innerHeight - rect.bottom - gap - edge;
    const above = rect.top - gap - edge;
    const up = below < 220 && above > below;
    panel.style.left = `${Math.round(Math.min(Math.max(edge, start), window.innerWidth - edge - width))}px`;
    panel.style.width = `${Math.round(width)}px`;
    panel.style.top = up ? 'auto' : `${Math.round(rect.bottom + gap)}px`;
    panel.style.bottom = up ? `${Math.round(window.innerHeight - rect.top + gap)}px` : 'auto';
    const list = panel.querySelector('[role="listbox"]');
    if (list) list.style.maxHeight = `${Math.round(Math.max(120, Math.min(420, (up ? above : below) - 50)))}px`;
  };

  const openPanels = [];
  const closePopover = (button, panel, restoreFocus = false) => {
    if (!button || !panel) return;
    if (topLayer && panel.matches(':popover-open')) panel.hidePopover();
    panel.hidden = true;
    const index = openPanels.findIndex((open) => open[1] === panel);
    if (index > -1) openPanels.splice(index, 1);
    button.setAttribute('aria-expanded', 'false');
    if (restoreFocus) button.focus();
  };

  const openPopover = (button, panel, buttons, attr) => {
    if (!button || !panel) return;
    panel.hidden = false;
    if (topLayer) {
      panel.showPopover();
      placePopover(button, panel);
      openPanels.push([button, panel]);
    }
    button.setAttribute('aria-expanded', 'true');
    const selected = buttons.find((item) => item.getAttribute(attr) === selectedCity);
    if (selected) selected.focus({ preventScroll: topLayer });
  };

  // A fixed list follows its button when the page scrolls or the window resizes.
  const replace = () => openPanels.forEach(([button, panel]) => placePopover(button, panel));
  window.addEventListener('scroll', replace, { passive: true });
  window.addEventListener('resize', replace);

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
      if (endMetaNode) endMetaNode.textContent = `· Sabata (šabas) baigiasi ${formatTime(state.end)}`;
    }

    // The city lists live inside the timer, so skip them while it is hidden.
    if (state.mode !== 'hidden') renderOptions(nowMs);
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
