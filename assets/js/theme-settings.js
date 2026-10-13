/**
 * Appearance → ALPS Theme Settings (app/Fields/SettingsPage.php): tabs, image
 * pickers, footer description rows and the Sabbath column condition. Without
 * this script every tab shows and everything but images and new rows works.
 */
(function () {
  'use strict';

  var form = document.querySelector('.alps-settings__form');
  if (!form) {
    return;
  }
  var strings = window.alpsThemeSettings || {};

  // Tabs: show one panel, remember it for the redirect after saving.
  var tabInput = form.querySelector('.alps-settings__tab-input');
  var tabs = form.querySelectorAll('.alps-settings__tabs .nav-tab');
  var panels = form.querySelectorAll('.alps-settings__panel');
  function showTab(key) {
    tabs.forEach(function (tab) {
      tab.classList.toggle('nav-tab-active', tab.dataset.tab === key);
      tab.setAttribute('aria-selected', tab.dataset.tab === key ? 'true' : 'false');
    });
    panels.forEach(function (panel) {
      panel.hidden = panel.dataset.tab !== key;
    });
    tabInput.value = key;
  }
  form.classList.add('alps-settings__form--tabs');
  tabs.forEach(function (tab) {
    tab.setAttribute('role', 'tab');
    tab.addEventListener('click', function (event) {
      event.preventDefault();
      showTab(tab.dataset.tab);
      var url = new URL(window.location.href);
      url.searchParams.set('tab', tab.dataset.tab);
      url.searchParams.delete('updated');
      window.history.replaceState(null, '', url);
    });
  });
  showTab(tabInput.value);

  // Image fields: the media library picks an attachment ID.
  form.addEventListener('click', function (event) {
    var choose = event.target.closest('[data-image-choose]');
    var remove = event.target.closest('[data-image-remove]');
    var box = event.target.closest('[data-image]');
    if (!box || (!choose && !remove)) {
      return;
    }
    event.preventDefault();
    var input = box.querySelector('[data-image-input]');
    var preview = box.querySelector('[data-image-preview]');
    var removeButton = box.querySelector('[data-image-remove]');
    if (remove) {
      input.value = '';
      preview.textContent = '';
      removeButton.hidden = true;
      return;
    }
    var frame = window.wp.media({
      title: strings.choose,
      button: {text: strings.use},
      library: {type: 'image'},
      multiple: false,
    });
    frame.on('select', function () {
      var image = frame.state().get('selection').first().toJSON();
      var size = (image.sizes && (image.sizes.thumbnail || image.sizes.full)) || {url: image.url};
      input.value = String(image.id);
      preview.textContent = '';
      var img = document.createElement('img');
      img.src = size.url;
      img.alt = '';
      img.className = 'alps-settings__preview-img';
      preview.appendChild(img);
      removeButton.hidden = false;
    });
    frame.open();
  });

  // Footer description rows.
  form.addEventListener('click', function (event) {
    var add = event.target.closest('[data-row-add]');
    var remove = event.target.closest('[data-row-remove]');
    if (add) {
      event.preventDefault();
      var name = add.dataset.rowAdd;
      var template = form.querySelector('template[data-row-template="' + name + '"]');
      var rows = form.querySelector('[data-rows="' + name + '"]');
      var index = add.dataset.next;
      add.dataset.next = String(Number(index) + 1);
      var html = template.innerHTML.split('__i__').join(index);
      rows.insertAdjacentHTML('beforeend', html);
      updateEmpty(rows);
    } else if (remove) {
      event.preventDefault();
      var list = remove.closest('[data-rows]');
      remove.closest('[data-row]').remove();
      updateEmpty(list);
    }
  });
  function updateEmpty(rows) {
    var empty = rows.parentNode.querySelector('.alps-settings__empty');
    if (empty) {
      empty.hidden = rows.querySelector('[data-row]') !== null;
    }
  }

  // "Hide the sabbath column at certain screen widths" only matters while
  // the column is hidden.
  form.querySelectorAll('[data-shown-with]').forEach(function (item) {
    var control = document.getElementById(item.dataset.shownWith);
    if (!control) {
      return;
    }
    function update() {
      item.hidden = !control.checked;
    }
    control.addEventListener('change', update);
    update();
  });
})();
