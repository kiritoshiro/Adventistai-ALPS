/**
 * Run a callback once document.body exists, checking on each animation frame.
 *
 * Same behaviour as @roots/sage/client's domReady, which the theme used
 * before the build moved from bud to esbuild.
 */
export const domReady = (onReady) => {
  window.requestAnimationFrame(async function check() {
    document.body ? await onReady() : window.requestAnimationFrame(check);
  });
};
