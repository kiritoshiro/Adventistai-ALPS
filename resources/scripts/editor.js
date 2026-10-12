import {domReady} from './dom-ready';
import {registerBlockStyle, unregisterBlockStyle} from '@wordpress/blocks';

import './card-scroller-block';
import './latest-post-slider-block';
import './wrapped-image-text-block';

/**
 * editor.main
 */
const main = () => {
  unregisterBlockStyle('core/button', 'outline');

  registerBlockStyle('core/button', {
    name: 'outline',
    label: 'Outline',
  });
};

domReady(main);
