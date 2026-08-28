(function ($) {
  'use strict';

  $('.adv-color-field').wpColorPicker();

  $('[data-adv-reset-weights]').on('click', function () {
    const defaults = JSON.parse(this.dataset.defaults || '{}');
    Object.keys(defaults).forEach((key) => {
      const field = document.querySelector(`[data-adv-weight="${key}"]`);
      if (field) field.value = defaults[key];
    });
  });

  const button = document.querySelector('[data-adv-reindex]');
  const progress = document.querySelector('[data-adv-progress]');
  const text = document.querySelector('[data-adv-progress-text]');
  if (!button || !window.AdvSearchAdmin) return;

  button.addEventListener('click', async () => {
    button.disabled = true;
    progress.hidden = false;
    progress.value = 0;
    text.textContent = window.AdvSearchAdmin.working;
    let offset = Number(window.localStorage.getItem('advSearchReindexOffset') || 0);

    try {
      while (true) {
        const response = await fetch(window.AdvSearchAdmin.endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': window.AdvSearchAdmin.nonce
          },
          body: JSON.stringify({ offset })
        });
        if (!response.ok) throw new Error(`Reindex failed: ${response.status}`);
        const batch = await response.json();
        offset = batch.next;
        window.localStorage.setItem('advSearchReindexOffset', String(offset));
        progress.max = Math.max(1, batch.total);
        progress.value = Math.min(offset, batch.total);
        text.textContent = `${Math.min(offset, batch.total)} / ${batch.total}`;
        if (batch.done) break;
      }
      window.localStorage.removeItem('advSearchReindexOffset');
      text.textContent = window.AdvSearchAdmin.done;
      window.setTimeout(() => window.location.reload(), 700);
    } catch (error) {
      text.textContent = window.AdvSearchAdmin.error;
      button.disabled = false;
    }
  });
})(jQuery);
