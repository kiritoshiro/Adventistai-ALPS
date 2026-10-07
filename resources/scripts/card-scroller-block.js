import {registerBlockType} from '@wordpress/blocks';
import {InspectorControls, MediaUpload, MediaUploadCheck, useBlockProps} from '@wordpress/block-editor';
import {Button, PanelBody, SelectControl, TextControl, ToggleControl} from '@wordpress/components';
import {useSelect} from '@wordpress/data';
import {__} from '@wordpress/i18n';

// Keep in sync with App\CardScrollerBlock::attributes().
const attributes = {
  layout: {type: 'string', default: 'cover'},
  heading: {type: 'string', default: ''},
  headingUrl: {type: 'string', default: ''},
  items: {type: 'array', default: []},
  moreLabel: {type: 'string', default: ''},
  moreUrl: {type: 'string', default: ''},
};

const emptyItem = {imageId: 0, imageUrl: '', alt: '', title: '', description: '', url: '', newTab: false};

function CardImage({item}) {
  const media = useSelect((select) => (item.imageId ? select('core').getMedia(item.imageId) : null), [item.imageId]);
  const src = media?.media_details?.sizes?.medium?.source_url || media?.source_url || item.imageUrl;
  return src
    ? <img className="alps-card-scroller__image" src={src} alt={item.alt || item.title} />
    : <span className="alps-card-scroller__placeholder">{__('Nėra vaizdo', 'alps')}</span>;
}

function CardEditor({item, index, count, layout, onChange, onMove, onRemove}) {
  const set = (values) => onChange({...item, ...values});
  return <div className="alps-card-scroller__item alps-card-scroller__item--editor">
    <div className="alps-card-scroller__card">
      <span className="alps-card-scroller__media"><CardImage item={item} /></span>
      <MediaUploadCheck>
        <MediaUpload allowedTypes={['image']} value={item.imageId}
          onSelect={(media) => set({imageId: media.id, imageUrl: media.url, alt: media.alt || item.alt})}
          render={({open}) => <Button variant="secondary" size="small" onClick={open}>
            {item.imageId || item.imageUrl ? __('Keisti vaizdą', 'alps') : __('Pasirinkti vaizdą', 'alps')}
          </Button>} />
      </MediaUploadCheck>
      <TextControl __nextHasNoMarginBottom label={__('Pavadinimas', 'alps')} value={item.title} onChange={(title) => set({title})} />
      {layout === 'logo' && <TextControl __nextHasNoMarginBottom label={__('Aprašymas', 'alps')} value={item.description}
        onChange={(description) => set({description})} />}
      <TextControl __nextHasNoMarginBottom label={__('Nuoroda', 'alps')} type="url" value={item.url}
        help={__('Be nuorodos kortelė rodoma, bet jos paspausti negalima.', 'alps')} onChange={(url) => set({url})} />
      <TextControl __nextHasNoMarginBottom label={__('Alternatyvus tekstas', 'alps')} value={item.alt}
        help={__('Tuščias – naudojamas pavadinimas.', 'alps')} onChange={(alt) => set({alt})} />
      <ToggleControl __nextHasNoMarginBottom label={__('Atidaryti naujame lange', 'alps')} checked={item.newTab}
        onChange={(newTab) => set({newTab})} />
      <div className="alps-card-scroller__editor-actions">
        <Button size="small" icon="arrow-left-alt2" label={__('Perkelti kairėn', 'alps')} disabled={index === 0} onClick={() => onMove(-1)} />
        <Button size="small" icon="arrow-right-alt2" label={__('Perkelti dešinėn', 'alps')} disabled={index === count - 1} onClick={() => onMove(1)} />
        <Button size="small" icon="trash" isDestructive label={__('Pašalinti kortelę', 'alps')} onClick={onRemove} />
      </div>
    </div>
  </div>;
}

function Edit({attributes: values, setAttributes}) {
  const items = Array.isArray(values.items) ? values.items : [];
  const setItems = (next) => setAttributes({items: next});
  const blockProps = useBlockProps({className: `alps-card-scroller alps-card-scroller--${values.layout} is-editing`});

  return <>
    <InspectorControls>
      <PanelBody title={__('Slankiklio nustatymai', 'alps')}>
        <SelectControl __nextHasNoMarginBottom label={__('Kortelių tipas', 'alps')} value={values.layout}
          options={[
            {label: __('Viršeliai (knygos)', 'alps'), value: 'cover'},
            {label: __('Logotipai (svetainės)', 'alps'), value: 'logo'},
          ]}
          onChange={(layout) => setAttributes({layout})} />
        <TextControl __nextHasNoMarginBottom label={__('Antraštė', 'alps')} value={values.heading} onChange={(heading) => setAttributes({heading})} />
        <TextControl __nextHasNoMarginBottom label={__('Antraštės nuoroda', 'alps')} type="url" value={values.headingUrl}
          onChange={(headingUrl) => setAttributes({headingUrl})} />
        <TextControl __nextHasNoMarginBottom label={__('Mygtuko „Daugiau“ tekstas', 'alps')} value={values.moreLabel}
          help={__('Paskutinė kortelė rodoma, kai užpildytas tekstas ir nuoroda.', 'alps')}
          onChange={(moreLabel) => setAttributes({moreLabel})} />
        <TextControl __nextHasNoMarginBottom label={__('Mygtuko „Daugiau“ nuoroda', 'alps')} type="url" value={values.moreUrl}
          help={__('Pasiekus eilės galą, rodyklė → veda čia (tuščia – į antraštės nuorodą).', 'alps')}
          onChange={(moreUrl) => setAttributes({moreUrl})} />
      </PanelBody>
    </InspectorControls>
    <div {...blockProps}>
      {values.heading && <h2 className="alps-card-scroller__heading">{values.heading}</h2>}
      <div className="alps-card-scroller__frame">
        <div className="alps-card-scroller__track">
          {items.map((item, index) => <CardEditor key={index} item={{...emptyItem, ...item}} index={index} count={items.length}
            layout={values.layout}
            onChange={(changed) => setItems(items.map((current, i) => (i === index ? changed : current)))}
            onMove={(direction) => {
              const next = [...items];
              [next[index], next[index + direction]] = [next[index + direction], next[index]];
              setItems(next);
            }}
            onRemove={() => setItems(items.filter((_, i) => i !== index))} />)}
          <div className="alps-card-scroller__item">
            <Button variant="primary" className="alps-card-scroller__add" onClick={() => setItems([...items, {...emptyItem}])}>
              {__('Pridėti kortelę', 'alps')}
            </Button>
          </div>
        </div>
      </div>
    </div>
  </>;
}

registerBlockType('alps/card-scroller', {
  apiVersion: 3,
  title: __('Kortelių slankiklis', 'alps'),
  description: __('Horizontaliai slenkama kortelių eilė su vaizdais ir nuorodomis, pvz. knygų viršeliai arba naudingos svetainės.', 'alps'),
  category: 'design',
  icon: 'slides',
  keywords: ['slider', 'carousel', 'books', 'logos', 'slankiklis', 'knygos', 'kortelės'],
  attributes,
  supports: {anchor: true, align: ['wide', 'full'], html: false},
  // Shown in the block inserter's preview.
  example: {
    attributes: {
      heading: __('Knygos', 'alps'),
      items: [
        {title: __('Kelias pas Kristų', 'alps'), url: '#'},
        {title: __('Didžioji kova', 'alps'), url: '#'},
        {title: __('Ugdymas', 'alps'), url: '#'},
      ],
    },
  },
  edit: Edit,
  save: () => null,
});
