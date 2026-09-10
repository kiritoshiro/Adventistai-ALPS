/* global wp, alpsPostMedia */
(function () {
  'use strict';
  if (!window.wp?.media?.model?.Query || !window.alpsPostMedia || wp.media.model.Query.alpsPriorityInstalled) return;
  const Query = wp.media.model.Query;
  const Attachments = wp.media.model.Attachments;
  Query.alpsPriorityInstalled = true;
  let priorityIds = new Set(alpsPostMedia.ids.map(Number));

  function currentMedia() {
    const editor = wp.data?.select('core/editor');
    const blockEditor = wp.data?.select('core/block-editor');
    const ids = new Set();
    let content;
    if (editor?.getEditedPostContent && blockEditor?.getBlocks) {
      content = editor.getEditedPostContent();
      const keys = {'core/image': 'id', 'core/file': 'id', 'core/audio': 'id', 'core/video': 'id', 'core/cover': 'id', 'core/media-text': 'mediaId', 'alps/wrapped-image-text': 'imageId'};
      const walk = blocks => blocks.forEach(block => {
        const attrs = block.attributes || {};
        if (keys[block.name] && attrs[keys[block.name]]) ids.add(Number(attrs[keys[block.name]]));
        if (block.name === 'core/gallery') {
          (attrs.ids || []).forEach(id => ids.add(Number(id)));
          (attrs.images || []).forEach(image => ids.add(Number(image.id)));
        }
        walk(block.innerBlocks || []);
      });
      walk(blockEditor.getBlocks());
      const featured = editor.getEditedPostAttribute('featured_media');
      if (featured) ids.add(Number(featured));
    } else {
      const visual = window.tinymce?.get('content');
      content = visual && !visual.isHidden() ? visual.getContent() : document.getElementById('content')?.value;
      const featured = Number(document.getElementById('_thumbnail_id')?.value);
      if (featured > 0) ids.add(featured);
    }
    if (typeof content !== 'string') return {ids: alpsPostMedia.ids, urls: []};
    for (const match of content.matchAll(/(?:wp-image-|wp-att-|attachment_)(\d+)/g)) ids.add(Number(match[1]));
    for (const match of content.matchAll(/\[gallery[^\]]*\bids=["']([\d,\s]+)["']/g)) {
      match[1].split(',').forEach(id => ids.add(Number(id)));
    }
    const html = new DOMParser().parseFromString(content, 'text/html');
    const urls = Array.from(html.querySelectorAll('img[src],a[href],video[src],audio[src],source[src]'))
      .map(el => el.getAttribute('src') || el.getAttribute('href')).filter(Boolean);
    // Custom dynamic blocks store image URLs in their JSON comments rather than HTML.
    for (const match of content.matchAll(/"imageUrl"\s*:\s*"([^"\\]*(?:\\.[^"\\]*)*)"/g)) {
      try { urls.push(JSON.parse('"' + match[1] + '"')); } catch { /* Ignore incomplete edits. */ }
    }
    return {ids: Array.from(ids).filter(id => Number.isInteger(id) && id > 0).slice(0, 500), urls: Array.from(new Set(urls)).slice(0, 100)};
  }

  const getQuery = Query.get;
  Query.get = function (props, options) {
    props = {...props};
    if ((!props.orderby || props.orderby === 'date') && (!props.order || props.order === 'DESC')) {
      const current = currentMedia();
      priorityIds = new Set(current.ids.map(Number));
      props.alps_post_id = Number(alpsPostMedia.postId);
      props.alps_media_ids = current.ids.join(',');
      props.alps_media_urls = current.urls;
    }
    const query = getQuery.call(this, props, options);
    // Extra query arguments disable core's upload-queue observer; restore it.
    if (props.alps_post_id && wp.Uploader?.queue && !props.include && !props.exclude) query.observe(wp.Uploader.queue);
    return query;
  };

  const compare = Attachments.comparator;
  Attachments.comparator = function (a, b, options) {
    if ((this.props.get('query') || this.props.get('alps_post_id')) && this.props.get('orderby') === 'date' && this.props.get('order') !== 'ASC') {
      const rank = model => priorityIds.has(Number(model.id)) || model.get('alpsUsedInPost') ? 0 : 1;
      const difference = rank(a) - rank(b);
      if (difference) return difference;
    }
    return compare.call(this, a, b, options);
  };

  const Frame = wp.media.view.MediaFrame;
  const open = Frame.prototype.open;
  Frame.prototype.open = function () {
    const current = currentMedia();
    const revision = JSON.stringify(current);
    this.states?.each(state => {
      const library = state.get('library');
      if (library?.props?.get('query')) {
        if (library.comparator === compare) library.comparator = Attachments.comparator;
        library.props.set('alps_media_revision', revision);
      }
    });
    return open.apply(this, arguments);
  };
}());
