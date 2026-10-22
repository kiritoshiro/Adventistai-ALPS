/**
 * Posts → Authors (app/Authors/AdminPage.php): the photo picker. Without this
 * script everything but choosing a photo works.
 */
(function () {
  'use strict';

  var strings = window.alpsAuthorsAdmin || {};

  document.querySelectorAll('.alps-authors [data-image]').forEach(function (box) {
    var input = box.querySelector('[data-image-input]');
    var preview = box.querySelector('[data-image-preview]');
    var remove = box.querySelector('[data-image-remove]');
    var frame;
    box.querySelector('[data-image-choose]').addEventListener('click', function () {
      if (!window.wp || !window.wp.media) {
        return;
      }
      if (!frame) {
        frame = window.wp.media({
          title: strings.choose,
          button: {text: strings.use},
          library: {type: 'image'},
          multiple: false,
        });
        frame.on('select', function () {
          var image = frame.state().get('selection').first().toJSON();
          var size = (image.sizes && (image.sizes.thumbnail || image.sizes.full)) || image;
          input.value = image.id;
          preview.innerHTML = '';
          var img = document.createElement('img');
          img.src = size.url;
          img.alt = '';
          img.style.maxWidth = '150px';
          preview.appendChild(img);
          remove.hidden = false;
        });
      }
      frame.open();
    });
    remove.addEventListener('click', function () {
      input.value = '';
      preview.innerHTML = '';
      remove.hidden = true;
    });
  });
})();
