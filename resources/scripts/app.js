import {domReady} from '@roots/sage/client';
import 'jquery';
import {initLatestPostSliders} from './latest-post-sliders';

/**
 * app.main
 */
const main = async (err) => {
  if (err) {
    // handle hmr errors
    console.error(err);
  }

  initLatestPostSliders();
};

/**
 * Initialize
 *
 * @see https://webpack.js.org/api/hot-module-replacement
 */
domReady(main);
import.meta.webpackHot?.accept(main);
