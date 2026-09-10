import {registerBlockType} from '@wordpress/blocks';
import {InnerBlocks, InspectorControls, MediaUpload, MediaUploadCheck, useBlockProps, useInnerBlocksProps} from '@wordpress/block-editor';
import {Button, PanelBody, RangeControl, TextControl, TextareaControl} from '@wordpress/components';
import {__} from '@wordpress/i18n';
import {useSelect} from '@wordpress/data';

const attributes = {
  imageId: {type: 'number', default: 0},
  imageUrl: {type: 'string', default: ''},
  alt: {type: 'string', default: ''},
  caption: {type: 'string', default: ''},
  imageWidth: {type: 'number', default: 32},
  maxImageWidth: {type: 'number', default: 320},
};

function Edit({attributes: values, setAttributes}) {
  const media = useSelect((select) => values.imageId ? select('core').getMedia(values.imageId) : null, [values.imageId]);
  const imageUrl = values.imageUrl || media?.source_url || '';
  const blockProps = useBlockProps({
    className: 'alps-wrapped-text',
    style: {'--alps-wrap-width': `${values.imageWidth}%`, '--alps-wrap-max': `${values.maxImageWidth}px`},
  });
  const innerProps = useInnerBlocksProps({className: 'alps-wrapped-text__flow'}, {
    allowedBlocks: ['core/paragraph', 'core/heading', 'core/list', 'core/quote'],
    template: [['core/paragraph', {placeholder: __('Rašykite tekstą…', 'alps')}]],
  });
  const picker = <MediaUploadCheck>
    <MediaUpload allowedTypes={['image']} value={values.imageId}
      onSelect={(media) => setAttributes({imageId: media.id, imageUrl: media.url, alt: media.alt || ''})}
      render={({open}) => <Button variant="secondary" onClick={open}>
        {imageUrl ? __('Pakeisti vaizdą', 'alps') : __('Pasirinkti vaizdą', 'alps')}
      </Button>} />
  </MediaUploadCheck>;
  return <>
    <InspectorControls>
      <PanelBody title={__('Vaizdo nustatymai', 'alps')}>
        {picker}
        {(imageUrl || values.imageId > 0) && <Button isDestructive onClick={() => setAttributes({imageId: 0, imageUrl: '', alt: '', caption: ''})}>{__('Pašalinti vaizdą', 'alps')}</Button>}
        <TextControl label={__('Vaizdo URL', 'alps')} value={values.imageUrl}
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
