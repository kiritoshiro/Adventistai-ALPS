(function () {
  'use strict';

  if (!window.AdvSearchConfig || !window.fetch || !window.AbortController) return;

  const config = window.AdvSearchConfig;
  const cache = new Map();
  const foldMap = {
    ą: 'a', č: 'c', ę: 'e', ė: 'e', į: 'i', š: 's', ų: 'u', ū: 'u', ž: 'z',
    á: 'a', à: 'a', â: 'a', ä: 'a', ã: 'a', å: 'a', é: 'e', è: 'e', ê: 'e', ë: 'e',
    í: 'i', ì: 'i', î: 'i', ï: 'i', ó: 'o', ò: 'o', ô: 'o', ö: 'o', õ: 'o',
    ú: 'u', ù: 'u', û: 'u', ü: 'u', ç: 'c', ñ: 'n', ý: 'y', ÿ: 'y', ł: 'l', ø: 'o',
    æ: 'ae', ß: 'ss', œ: 'oe'
  };

  function normalize(value) {
    return Array.from(value.toLocaleLowerCase('lt-LT'))
      .map((char) => foldMap[char] || char)
      .join('')
      .replace(/[^\p{L}\p{N}]+/gu, ' ')
      .trim()
      .replace(/\s+/g, ' ');
  }

  function format(template, value) {
    return String(template).replace(/%[ds]/, String(value));
  }

  document.querySelectorAll('[data-adv-search]').forEach((root) => {
    const form = root.querySelector('form');
    const input = root.querySelector('[role="combobox"]');
    const list = root.querySelector('[role="listbox"]');
    const live = root.querySelector('[data-adv-search-live]');
    if (!form || !input || !list) return;

    let timer = null;
    let loadingTimer = null;
    let controller = null;
    let active = -1;
    let lastTyped = input.value;

    function close() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.setAttribute('aria-activedescendant', '');
      active = -1;
      root.classList.remove('is-loading');
    }

    function options() {
      return Array.from(list.querySelectorAll('[role="option"]'));
    }

    function activate(index) {
      const items = options();
      if (!items.length) return;
      active = (index + items.length) % items.length;
      items.forEach((item, itemIndex) => item.classList.toggle('is-active', itemIndex === active));
      input.setAttribute('aria-activedescendant', items[active].id);
      items[active].scrollIntoView({ block: 'nearest' });
    }

    function option(id, className) {
      const item = document.createElement('li');
      item.id = id;
      item.className = className;
      item.setAttribute('role', 'option');
      item.setAttribute('aria-selected', 'false');
      return item;
    }

    function render(payload, requestedValue) {
      if (input.value !== requestedValue) return;
      list.replaceChildren();
      active = -1;

      if (!payload.results.length) {
        const empty = option(`${input.id}-empty`, 'adv-search__empty');
        const message = document.createElement('p');
        message.textContent = format(config.strings.none, requestedValue);
        empty.appendChild(message);
        (config.suggestions || []).slice(0, 3).forEach((suggestion) => {
          const link = document.createElement('a');
          link.href = suggestion.url;
          link.textContent = suggestion.label;
          empty.appendChild(link);
        });
        list.appendChild(empty);
        live.textContent = format(config.strings.none, requestedValue);
      } else {
        payload.results.forEach((result, index) => {
          const item = option(`${input.id}-option-${result.id}`, 'adv-search__option');
          item.dataset.url = result.url;
          const link = document.createElement('a');
          link.href = result.url;
          link.className = 'adv-search__option-link';
          const title = document.createElement('span');
          title.className = 'adv-search__option-title';
          title.innerHTML = result.title_html;
          const meta = document.createElement('span');
          meta.className = 'adv-search__option-meta';
          meta.textContent = `${result.type_label} · ${result.date}`;
          const snippet = document.createElement('span');
          snippet.className = 'adv-search__option-snippet';
          snippet.innerHTML = result.snippet_html;
          link.append(title, meta, snippet);
          item.appendChild(link);
          item.addEventListener('mousemove', () => activate(index));
          list.appendChild(item);
        });

        if (payload.total > payload.results.length) {
          const more = option(`${input.id}-more`, 'adv-search__more');
          more.dataset.url = payload.more_url;
          const link = document.createElement('a');
          link.href = payload.more_url;
          link.textContent = format(config.strings.all, payload.total);
          more.appendChild(link);
          list.appendChild(more);
        }
        live.textContent = format(config.strings.found, payload.total);
      }

      root.classList.remove('is-loading');
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    async function search(requestedValue) {
      const key = normalize(requestedValue);
      if (key.length < Number(config.minChars)) {
        close();
        return;
      }
      if (cache.has(key)) {
        render(cache.get(key), requestedValue);
        return;
      }

      if (controller) controller.abort();
      controller = new AbortController();
      const requestLoadingTimer = window.setTimeout(() => {
        if (!list.hidden) return;
        root.classList.add('is-loading');
        live.textContent = config.strings.loading;
      }, 150);
      loadingTimer = requestLoadingTimer;

      const url = new URL(config.endpoint);
      url.searchParams.set('q', requestedValue);
      url.searchParams.set('per_page', String(config.limit));
      try {
        const response = await fetch(url.toString(), {
          signal: controller.signal,
          headers: { 'X-WP-Nonce': config.nonce, Accept: 'application/json' },
          credentials: 'same-origin'
        });
        if (!response.ok) throw new Error(`Search request failed: ${response.status}`);
        const payload = await response.json();
        cache.set(key, payload);
        if (input.value === requestedValue) render(payload, requestedValue);
      } catch (error) {
        if (error.name !== 'AbortError') close();
      } finally {
        window.clearTimeout(requestLoadingTimer);
      }
    }

    input.addEventListener('input', () => {
      lastTyped = input.value;
      window.clearTimeout(timer);
      window.clearTimeout(loadingTimer);
      if (controller) controller.abort();
      timer = window.setTimeout(() => search(input.value), Number(config.delay));
    });

    input.addEventListener('keydown', (event) => {
      const items = options();
      if (event.key === 'ArrowDown' && items.length) {
        event.preventDefault();
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        activate(active + 1);
      } else if (event.key === 'ArrowUp' && items.length) {
        event.preventDefault();
        activate(active - 1);
      } else if (event.key === 'Enter' && active >= 0 && items[active]) {
        event.preventDefault();
        window.location.href = items[active].dataset.url || items[active].querySelector('a').href;
      } else if (event.key === 'Escape') {
        event.preventDefault();
        input.value = lastTyped;
        close();
      } else if (event.key === 'Tab') {
        close();
      }
    });

    document.addEventListener('pointerdown', (event) => {
      if (!root.contains(event.target)) close();
    });
  });

  document.querySelectorAll('[data-adv-per-page]').forEach((select) => {
    const stored = window.localStorage.getItem('advSearchPerPage');
    if (stored && Array.from(select.options).some((option) => option.value === stored) && !new URLSearchParams(window.location.search).has('per_page')) {
	  if (select.value !== stored) {
		select.value = stored;
		select.form.submit();
		return;
	  }
    }
    select.addEventListener('change', () => {
      window.localStorage.setItem('advSearchPerPage', select.value);
      select.form.submit();
    });
  });
})();
