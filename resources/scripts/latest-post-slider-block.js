import {registerBlockType} from '@wordpress/blocks';
import {InspectorControls, useBlockProps} from '@wordpress/block-editor';
import {useSelect} from '@wordpress/data';
import {__} from '@wordpress/i18n';
import {Fragment} from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';
import {
  Button,
  FormTokenField,
  PanelBody,
  Placeholder,
  RangeControl,
  SelectControl,
  TextControl,
  ToggleControl,
} from '@wordpress/components';

const MAX_MODULES = 4;

const createModule = () => ({
  title: '',
  titleUrl: '',
  source: 'latest',
  categoryId: 0,
  items: [],
  count: 5,
  interval: 5,
  autoplay: true,
});

const normalizeModule = (module = {}) => ({
  ...createModule(),
  ...module,
  categoryId: Number(module.categoryId || 0),
  items: Array.isArray(module.items) ? module.items.map(Number).filter(Boolean) : [],
  count: Number(module.count || 5),
  interval: Number(module.interval || 5),
  autoplay: module.autoplay !== false,
});

const stripHtml = (value) => {
  const element = document.createElement('div');
  element.innerHTML = value || '';
  return element.textContent || element.innerText || '';
};

const recordLabel = (record) => {
  const title = stripHtml(record?.title?.rendered || record?.title || '');
  return `${title || __('Untitled', 'alps')} (#${record.id})`;
};

const recordIdFromToken = (token) => {
  const match = String(token).match(/#(\d+)\)?$/) || String(token).match(/^(\d+)$/);
  return match ? Number(match[1]) : 0;
};

const sourceOptions = [
  {label: __('Latest posts', 'alps'), value: 'latest'},
  {label: __('Posts from a category', 'alps'), value: 'category'},
  {label: __('Selected posts or pages', 'alps'), value: 'custom'},
];

const ModuleSettings = ({module, index, categories, records, onChange, onRemove, canRemove}) => {
  const categoryOptions = [
    {label: __('Select a category', 'alps'), value: 0},
    ...(categories || []).map((category) => ({
      label: category.name,
      value: category.id,
    })),
  ];
  const labelsById = (records || []).reduce((labels, record) => {
    labels[record.id] = recordLabel(record);
    return labels;
  }, {});
  const suggestions = (records || []).map(recordLabel);
  const selectedTokens = module.items.map((id) => labelsById[id] || `#${id}`);
  const panelTitle = module.title || `${__('Slider', 'alps')} ${index + 1}`;

  const setValue = (key, value) => onChange(index, {[key]: value});

  return (
    <PanelBody title={panelTitle} initialOpen={index === 0}>
      <TextControl
        label={__('List name', 'alps')}
        help={__('Optional heading displayed above this slider.', 'alps')}
        value={module.title}
        onChange={(value) => setValue('title', value)}
      />

      <TextControl
        label={__('Antraštės nuoroda (URL)', 'alps')}
        help={__('Palikite tuščią, kad būtų naudojama pasirinktos kategorijos nuoroda.', 'alps')}
        value={module.titleUrl}
        onChange={(value) => setValue('titleUrl', value)}
      />

      <SelectControl
        label={__('Content source', 'alps')}
        value={module.source}
        options={sourceOptions}
        onChange={(value) => setValue('source', value)}
      />

      {module.source === 'category' && (
        <SelectControl
          label={__('Category', 'alps')}
          help={__('The newest posts in this category will be shown.', 'alps')}
          value={module.categoryId}
          options={categoryOptions}
          onChange={(value) => setValue('categoryId', Number(value))}
        />
      )}

      {module.source === 'custom' && (
        <FormTokenField
          label={__('Posts or pages', 'alps')}
          help={__('Choose published posts or pages from the suggestions. Items appear in the order selected.', 'alps')}
          value={selectedTokens}
          suggestions={suggestions}
          onChange={(tokens) => setValue('items', tokens.map(recordIdFromToken).filter(Boolean).slice(0, 20))}
        />
      )}

      {module.source !== 'custom' && (
        <RangeControl
          label={__('Number of posts', 'alps')}
          value={module.count}
          min={1}
          max={20}
          onChange={(value) => setValue('count', Number(value || 5))}
        />
      )}

      <RangeControl
        label={__('Seconds between slides', 'alps')}
        help={__('The slider pauses while hovered or focused.', 'alps')}
        value={module.interval}
        min={2}
        max={30}
        onChange={(value) => setValue('interval', Number(value || 5))}
      />

      <ToggleControl
        label={__('Automatically advance slides', 'alps')}
        checked={module.autoplay}
        onChange={(value) => setValue('autoplay', value)}
      />

      {canRemove && (
        <Button
          isDestructive
          variant="secondary"
          onClick={() => onRemove(index)}
        >
          {__('Remove slider', 'alps')}
        </Button>
      )}
    </PanelBody>
  );
};

const Edit = ({attributes, setAttributes}) => {
  const modules = (Array.isArray(attributes.modules) && attributes.modules.length
    ? attributes.modules
    : [createModule()]
  ).map(normalizeModule);
  const blockProps = useBlockProps({className: 'alps-latest-post-slider-block-editor'});
  const categories = useSelect((select) => select('core').getEntityRecords(
    'taxonomy',
    'category',
    {per_page: 100, hide_empty: false},
  ), []);
  const posts = useSelect((select) => select('core').getEntityRecords(
    'postType',
    'post',
    {per_page: 100, orderby: 'date', order: 'desc', status: 'publish'},
  ), []);
  const pages = useSelect((select) => select('core').getEntityRecords(
    'postType',
    'page',
    {per_page: 100, orderby: 'date', order: 'desc', status: 'publish'},
  ), []);
  const records = [...(posts || []), ...(pages || [])];

  const updateModule = (index, changes) => {
    setAttributes({
      modules: modules.map((module, moduleIndex) => (
        moduleIndex === index ? normalizeModule({...module, ...changes}) : module
      )),
    });
  };

  const addModule = () => {
    if (modules.length >= MAX_MODULES) {
      return;
    }

    setAttributes({modules: [...modules, createModule()]});
  };

  const removeModule = (index) => {
    if (modules.length === 1) {
      return;
    }

    setAttributes({modules: modules.filter((module, moduleIndex) => moduleIndex !== index)});
  };

  return (
    <Fragment>
      <InspectorControls>
        <PanelBody title={__('Slider layout', 'alps')} initialOpen>
          <RangeControl
            label={__('Modules per row', 'alps')}
            help={__('Choose how many independent sliders appear in one row. They stack on small screens.', 'alps')}
            value={Number(attributes.columns || 1)}
            min={1}
            max={4}
            onChange={(value) => setAttributes({columns: Number(value || 1)})}
          />
        </PanelBody>

        {modules.map((module, index) => (
          <ModuleSettings
            key={index}
            module={module}
            index={index}
            categories={categories}
            records={records}
            onChange={updateModule}
            onRemove={removeModule}
            canRemove={modules.length > 1}
          />
        ))}

        <PanelBody title={__('Add another slider', 'alps')} initialOpen={false}>
          <Button
            variant="primary"
            onClick={addModule}
            disabled={modules.length >= MAX_MODULES}
          >
            {modules.length >= MAX_MODULES
              ? __('Maximum of four sliders reached', 'alps')
              : __('Add slider module', 'alps')}
          </Button>
        </PanelBody>
      </InspectorControls>

      <div {...blockProps}>
        <ServerSideRender
          block="alps/latest-post-slider"
          attributes={{columns: Number(attributes.columns || 1), modules}}
          EmptyResponsePlaceholder={() => (
            <Placeholder label={__('Latest Post Slider', 'alps')}>
              {__('Choose a category or add selected posts/pages to display slider content.', 'alps')}
            </Placeholder>
          )}
        />
      </div>
    </Fragment>
  );
};

registerBlockType('alps/latest-post-slider', {
  apiVersion: 3,
  title: __('Latest Post Slider', 'alps'),
  description: __('Show one or more independently configured post sliders anywhere in your content.', 'alps'),
  category: 'widgets',
  icon: 'slides',
  keywords: [__('posts', 'alps'), __('carousel', 'alps'), __('featured', 'alps')],
  attributes: {
    columns: {type: 'number', default: 1},
    modules: {type: 'array', default: [createModule()]},
  },
  supports: {
    align: ['wide', 'full'],
    anchor: true,
  },
  edit: Edit,
  save: () => null,
});
