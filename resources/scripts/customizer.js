import $ from 'jquery';

// Only run inside the Customizer preview — wp.customize is not a function
// in the block editor, where this bundle is also loaded.
if (typeof wp !== 'undefined' && typeof wp.customize === 'function') {
  wp.customize('blogname', (value) => {
    value.bind(to => $('.brand').text(to));
  });
}
