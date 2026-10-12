// The theme's post and page fields as block editor sidebar panels, in place of
// the Carbon Fields boxes. Panels, labels and conditions come from
// App\Fields\PostPanels::layout() (window.alpsFieldPanels); the values are the
// post's `alps_fields` REST field, saved with the post.
import {registerPlugin} from '@wordpress/plugins';
import {PluginDocumentSettingPanel, store as editorStore} from '@wordpress/editor';
import {useEntityProp, store as coreStore} from '@wordpress/core-data';
import {useSelect} from '@wordpress/data';
import {useState} from '@wordpress/element';
import {MediaUpload, MediaUploadCheck} from '@wordpress/block-editor';
import {
  BaseControl,
  Button,
  CheckboxControl,
  RadioControl,
  SelectControl,
  TextControl,
  TextareaControl,
} from '@wordpress/components';
import {__, sprintf} from '@wordpress/i18n';

const config = () => window.alpsFieldPanels;

const choiceOptions = (choices) => Object.entries(choices).map(([value, label]) => ({value, label}));

function ImageField({label, help, value, onChange}) {
  const id = Number(value) || 0;
  const media = useSelect((select) => (id ? select(coreStore).getMedia(id, {context: 'view'}) : null), [id]);
  const src = media?.media_details?.sizes?.thumbnail?.source_url || media?.source_url;
  return <BaseControl __nextHasNoMarginBottom label={label} help={help} className="alps-field-panels__image">
    {id ? <div style={{marginBottom: 8}}>
      {src ? <img src={src} alt="" style={{display: 'block', maxWidth: '100%', maxHeight: 120}} /> : <span>{sprintf(__('Image %d', 'alps'), id)}</span>}
    </div> : null}
    <MediaUploadCheck>
      <MediaUpload allowedTypes={['image']} value={id || undefined} onSelect={(picked) => onChange(String(picked.id))}
        render={({open}) => <Button variant="secondary" size="compact" onClick={open}>
          {id ? __('Replace image', 'alps') : __('Select image', 'alps')}
        </Button>} />
    </MediaUploadCheck>
    {id ? <Button variant="link" isDestructive onClick={() => onChange('')} style={{marginLeft: 8}}>{__('Remove', 'alps')}</Button> : null}
  </BaseControl>;
}

function SimpleField({field, type, value, onChange}) {
  const common = {label: field.label, help: field.help, __nextHasNoMarginBottom: true};
  switch (type) {
    case 'checkbox':
      return <CheckboxControl {...common} checked={!!value} onChange={onChange} />;
    case 'radio':
      return <RadioControl {...common} selected={value} options={choiceOptions(field.choices)} onChange={onChange} />;
    case 'select':
      return <SelectControl {...common} __next40pxDefaultSize value={value} options={choiceOptions(field.choices)} onChange={onChange} />;
    case 'image':
      return <ImageField {...common} value={value} onChange={onChange} />;
    case 'textarea':
      return <TextareaControl {...common} rows={2} value={value || ''} onChange={onChange} />;
    default:
      return <TextControl {...common} __next40pxDefaultSize value={value || ''} onChange={onChange} />;
  }
}

function RowsField({field, value, onChange}) {
  const [min, max] = field.rows;
  const rows = Array.isArray(value) ? [...value] : [];
  const empty = () => Object.fromEntries(field.subs.map((sub) => [sub.name, '']));
  // Exactly-n lists (three columns) always show n rows, as Carbon Fields did.
  while (min === max && rows.length < min) {
    rows.push(empty());
  }
  const types = config().types;
  const setRow = (index, name, sub) => onChange(rows.map((row, i) => (i === index ? {...row, [name]: sub} : row)));
  const move = (index, step) => {
    const next = [...rows];
    [next[index], next[index + step]] = [next[index + step], next[index]];
    onChange(next);
  };
  return <BaseControl __nextHasNoMarginBottom label={field.label} help={field.help}>
    {rows.map((row, index) => <div key={index} className="alps-field-panels__row"
      style={{border: '1px solid #ddd', padding: 8, marginBottom: 8, display: 'grid', gap: 12}}>
      <div style={{display: 'flex', alignItems: 'center', justifyContent: 'space-between'}}>
        <strong>{sprintf('%s %d', field.rowLabel, index + 1)}</strong>
        {min !== max ? <span>
          <Button size="small" icon="arrow-up-alt2" label={__('Move up', 'alps')} disabled={index === 0} onClick={() => move(index, -1)} />
          <Button size="small" icon="arrow-down-alt2" label={__('Move down', 'alps')} disabled={index === rows.length - 1} onClick={() => move(index, 1)} />
          <Button size="small" icon="trash" isDestructive label={__('Remove', 'alps')} disabled={rows.length <= min}
            onClick={() => onChange(rows.filter((_, i) => i !== index))} />
        </span> : null}
      </div>
      {field.subs.map((sub) => <SimpleField key={sub.name} field={sub} type={types[`${field.name}.${sub.name}`]}
        value={row[sub.name]} onChange={(next) => setRow(index, sub.name, next)} />)}
    </div>)}
    {min !== max && rows.length < max ? <Button variant="secondary" size="compact" onClick={() => onChange([...rows, empty()])}>
      {sprintf(__('Add %s', 'alps'), field.rowLabel.toLowerCase())}
    </Button> : null}
  </BaseControl>;
}

function PostTitle({entry}) {
  const record = useSelect((select) => select(coreStore).getEntityRecord('postType', entry.subtype, entry.id), [entry.subtype, entry.id]);
  const title = record?.title?.rendered || record?.title?.raw;
  return <span>{title ? title.replace(/<[^>]+>/g, '') : sprintf('#%d', entry.id)} <em>({entry.subtype})</em></span>;
}

function PostsField({field, value, onChange}) {
  const entries = Array.isArray(value) ? value : [];
  const [search, setSearch] = useState('');
  const results = useSelect((select) => {
    if (search.trim().length < 2) {
      return [];
    }
    const query = {search, per_page: 10, status: 'publish', _fields: 'id,title,type'};
    return field.postTypes.flatMap((type) => (select(coreStore).getEntityRecords('postType', type, query) || [])
      .map((post) => ({id: post.id, subtype: type, title: post.title?.rendered || ''})));
  }, [search]);
  const chosen = (id) => entries.some((entry) => entry.id === id);
  const move = (index, step) => {
    const next = [...entries];
    [next[index], next[index + step]] = [next[index + step], next[index]];
    onChange(next);
  };
  return <BaseControl __nextHasNoMarginBottom label={field.label} help={field.help}>
    <ul style={{margin: '0 0 8px'}}>
      {entries.map((entry, index) => <li key={`${entry.subtype}-${entry.id}`} className="alps-field-panels__post"
        style={{display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 4}}>
        <PostTitle entry={entry} />
        <span style={{whiteSpace: 'nowrap'}}>
          <Button size="small" icon="arrow-up-alt2" label={__('Move up', 'alps')} disabled={index === 0} onClick={() => move(index, -1)} />
          <Button size="small" icon="arrow-down-alt2" label={__('Move down', 'alps')} disabled={index === entries.length - 1} onClick={() => move(index, 1)} />
          <Button size="small" icon="no-alt" isDestructive label={__('Remove', 'alps')} onClick={() => onChange(entries.filter((_, i) => i !== index))} />
        </span>
      </li>)}
    </ul>
    <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Search pages and posts', 'alps')} value={search} onChange={setSearch} />
    <ul style={{marginTop: 4}}>
      {results.filter((post) => !chosen(post.id)).map((post) => <li key={`${post.subtype}-${post.id}`}>
        <Button variant="link" className="alps-field-panels__result"
          onClick={() => onChange([...entries, {type: 'post', subtype: post.subtype, id: post.id}])}>
          {`${post.title.replace(/<[^>]+>/g, '') || sprintf('#%d', post.id)} (${post.subtype})`}
        </Button>
      </li>)}
    </ul>
  </BaseControl>;
}

function visible(field, values) {
  if (!field.showIf) {
    return true;
  }
  const [name, allowed] = field.showIf;
  return allowed.includes(values[name]);
}

function FieldPanels() {
  const postType = useSelect((select) => select(editorStore).getCurrentPostType(), []);
  const settings = config();
  const [values, setValues] = useEntityProp('postType', postType, settings?.restField || 'alps_fields');
  if (!settings || !values) {
    return null;
  }
  const set = (name, value) => setValues({...values, [name]: value});
  return <>
    {settings.panels.filter((panel) => !panel.postTypes || panel.postTypes.includes(postType)).map((panel) =>
      <PluginDocumentSettingPanel key={panel.name} name={panel.name} title={panel.title} className="alps-field-panels">
        {panel.help ? <p style={{marginTop: 0}}>{panel.help}</p> : null}
        <div style={{display: 'grid', gap: 16}}>
          {panel.fields.filter((field) => field.name in values && visible(field, values)).map((field) => {
            const type = settings.types[field.name];
            if (type === 'complex') {
              return <RowsField key={field.name} field={field} value={values[field.name]} onChange={(next) => set(field.name, next)} />;
            }
            if (type === 'association') {
              return <PostsField key={field.name} field={field} value={values[field.name]} onChange={(next) => set(field.name, next)} />;
            }
            return <SimpleField key={field.name} field={field} type={type} value={values[field.name]} onChange={(next) => set(field.name, next)} />;
          })}
        </div>
      </PluginDocumentSettingPanel>)}
  </>;
}

if (window.alpsFieldPanels) {
  registerPlugin('alps-field-panels', {render: FieldPanels});
}
