import {registerBlockType} from '@wordpress/blocks';
import {InnerBlocks, InspectorControls, MediaUpload, MediaUploadCheck, useBlockProps, useInnerBlocksProps} from '@wordpress/block-editor';
import {Button, Notice, PanelBody, RangeControl, TextControl, TextareaControl} from '@wordpress/components';
import {__} from '@wordpress/i18n';
import {select, useSelect} from '@wordpress/data';
import {useEffect, useRef, useState} from '@wordpress/element';
import {droppedImage} from './wrapped-image-drop';

const attributes = {
  imageId: {type: 'number', default: 0},
  imageUrl: {type: 'string', default: ''},
  alt: {type: 'string', default: ''},
  caption: {type: 'string', default: ''},
  imageWidth: {type: 'number', default: 32},
  maxImageWidth: {type: 'number', default: 320},
};

function Edit({attributes: values, setAttributes}) {
  const [uploading, setUploading] = useState(false);
  const [dropError, setDropError] = useState('');
  const active = useRef(true);
  useEffect(() => { active.current = true; return () => { active.current = false; }; }, []);
  const mediaUpload = useSelect((store) => store('core/block-editor').getSettings().mediaUpload, []);
  const onDrop = (event) => {
    const files = Array.from(event.dataTransfer.files || []);
    const image = files.length ? null : droppedImage(event.dataTransfer, (id) => select('core/block-editor').getBlock(id));
    if (!files.length && !image) return;
    event.preventDefault();
    event.stopPropagation();
    if (uploading) return;
    setDropError('');
    if (image) {
      setAttributes(image);
      return;
    }
    if (files.length !== 1 || !files[0].type.startsWith('image/')) {
      setDropError(__('Įmeskite vieną vaizdo failą.', 'alps'));
      return;
    }
    if (typeof mediaUpload !== 'function') {
      setDropError(__('Negalima įkelti failo. Patikrinkite įkėlimo teises.', 'alps'));
      return;
    }
    setUploading(true);
    const onError = (error) => {
      if (!active.current) return;
      setUploading(false);
      setDropError(error?.message || __('Nepavyko įkelti vaizdo.', 'alps'));
    };
    try {
      mediaUpload({
        allowedTypes: ['image'], filesList: files,
        onError,
        onFileChange: ([uploaded]) => {
          if (!active.current || !uploaded?.id) return; // Ignore temporary blob previews.
          setAttributes({imageId: uploaded.id, imageUrl: uploaded.url, alt: uploaded.alt || ''});
          setUploading(false);
        },
      });
    } catch (error) { onError(error); }
  };
  const media = useSelect((select) => values.imageId ? select('core').getMedia(values.imageId) : null, [values.imageId]);
  const imageUrl = values.imageUrl || media?.source_url || '';
  const blockProps = useBlockProps({
    className: 'alps-wrapped-text',
    style: {'--alps-wrap-width': `${values.imageWidth}%`, '--alps-wrap-max': `${values.maxImageWidth}px`},
    onDropCapture: onDrop,
    onDragOverCapture: (event) => {
      if (Array.from(event.dataTransfer.types).some(type => ['Files', 'wp-blocks', 'text/html', 'text/uri-list'].includes(type))) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
      }
    },
  });
  const innerProps = useInnerBlocksProps({className: 'alps-wrapped-text__flow'}, {
    allowedBlocks: ['core/paragraph', 'core/heading', 'core/list', 'core/quote'],
    template: [['core/paragraph', {placeholder: __('Rašykite tekstą…', 'alps')}]],
  });
  const picker = <MediaUploadCheck>
    <MediaUpload allowedTypes={['image']} value={values.imageId}
      onSelect={(media) => setAttributes({imageId: media.id, imageUrl: media.url, alt: media.alt || ''})}
      render={({open}) => <Button variant="secondary" onClick={open} disabled={uploading}>
        {imageUrl ? __('Pakeisti vaizdą', 'alps') : __('Pasirinkti vaizdą', 'alps')}
      </Button>} />
  </MediaUploadCheck>;
  return <>
    <InspectorControls>
      <PanelBody title={__('Vaizdo nustatymai', 'alps')}>
        {picker}
        {(imageUrl || values.imageId > 0) && <Button isDestructive disabled={uploading} onClick={() => setAttributes({imageId: 0, imageUrl: '', alt: '', caption: ''})}>{__('Pašalinti vaizdą', 'alps')}</Button>}
        <TextControl label={__('Vaizdo URL', 'alps')} value={values.imageUrl} disabled={uploading}
          onChange={(imageUrl) => setAttributes({imageUrl, imageId: 0})} />
        <TextareaControl label={__('Alternatyvus tekstas', 'alps')} value={values.alt}
          help={__('Trumpai aprašykite vaizdą. Dekoratyviam vaizdui palikite tuščią.', 'alps')}
          onChange={(alt) => setAttributes({alt})} />
        <TextControl label={__('Vaizdo antraštė', 'alps')} value={values.caption} onChange={(caption) => setAttributes({caption})} />
        <RangeControl label={__('Vaizdo plotis (%)', 'alps')} min={20} max={45} value={values.imageWidth}
          onChange={(imageWidth) => setAttributes({imageWidth: imageWidth || 32})} />
        <RangeControl label={__('Didžiausias vaizdo plotis (px)', 'alps')} min={160} max={600} step={10} value={values.maxImageWidth}
          onChange={(maxImageWidth) => setAttributes({maxImageWidth: maxImageWidth || 320})} />
        <p>{__('Siauroje srityje vaizdas automatiškai centruojamas virš teksto.', 'alps')}</p>
      </PanelBody>
    </InspectorControls>
    <div {...blockProps}>
      <div className="alps-wrapped-text__drop-hint" contentEditable={false} aria-live="polite">
        {uploading ? __('Įkeliamas vaizdas…', 'alps') : __('Įmeskite vaizdą iš kompiuterio arba šio įrašo.', 'alps')}
      </div>
      {dropError && <Notice status="error" onRemove={() => setDropError('')}>{dropError}</Notice>}
      {!imageUrl && picker}
      <div {...innerProps}>
        {imageUrl && <figure className="alps-wrapped-text__figure" contentEditable={false}>
          <img className="alps-wrapped-text__image" src={imageUrl} alt={values.alt} />
          {values.caption && <figcaption>{values.caption}</figcaption>}
        </figure>}
        {innerProps.children}
      </div>
    </div>
  </>;
}

registerBlockType('alps/wrapped-image-text', {
  apiVersion: 3,
  title: __('Vaizdas su aptekančiu tekstu', 'alps'),
  description: __('Tekstas apteka vaizdą ir tęsiasi visu pločiu po juo.', 'alps'),
  category: 'text',
  icon: 'align-pull-left',
  keywords: ['image', 'wrap', 'photo', 'vaizdas', 'tekstas'],
  attributes,
  supports: {anchor: true, align: ['wide', 'full'], html: false},
  edit: Edit,
  save: () => <InnerBlocks.Content />,
});
