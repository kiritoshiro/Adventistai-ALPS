import {domReady} from './dom-ready';
import {initLatestPostSliders} from './latest-post-sliders';

/**
 * app.main
 */
const main = async () => {
  initLatestPostSliders();
};

domReady(main);
