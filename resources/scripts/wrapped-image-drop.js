const safeImage = (image) => {
  if (!image) return null;
  let url = image.url || image.imageUrl || image.source_url || '';
  if (url) {
    try { url = new URL(url, document.baseURI).href; } catch { return null; }
    if (!/^https?:\/\//i.test(url)) return null;
  }
  const rawId = Number(image.id || image.imageId || 0);
  const id = Number.isSafeInteger(rawId) && rawId > 0 ? rawId : 0;
  return id || url ? {imageId: id, imageUrl: url, alt: image.alt || ''} : null;
};

/** Decode WordPress block drags and browser image drags without moving the source. */
export const droppedImage = (transfer, getBlock) => {
  try {
    const payload = JSON.parse(transfer.getData('wp-blocks') || 'null');
    const blocks = payload?.srcClientIds?.map(getBlock) || payload?.blocks || [];
    if (blocks.length === 1 && ['core/image', 'alps/wrapped-image-text'].includes(blocks[0]?.name)) {
      return safeImage(blocks[0].attributes);
    }
  } catch { /* Other applications do not use WordPress drag data. */ }
  const html = transfer.getData('text/html');
  if (html) {
    const image = new DOMParser().parseFromString(html, 'text/html').querySelector('img');
    if (image) {
      const src = image.getAttribute('src');
      let local = false;
      try { local = new URL(src, document.baseURI).origin === new URL(document.baseURI).origin; } catch { /* Invalid URL. */ }
      const id = local ? image.className.match(/\bwp-image-(\d+)\b/)?.[1] : 0;
      return safeImage({id, url: image.getAttribute('src'), alt: image.getAttribute('alt') || ''});
    }
  }
  const url = (transfer.getData('text/uri-list') || '').split(/\r?\n/).find(line => line && !line.startsWith('#'));
  return url ? safeImage({url}) : null;
};
