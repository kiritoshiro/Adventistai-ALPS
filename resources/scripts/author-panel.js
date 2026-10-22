// The block editor's Author panel (App\Authors\EditorPanel): choose a post's
// authors from the list (Posts → Authors), in order, or add a new author on
// the spot. The authors are the post's `alps_author` REST field, saved with
// the post. Settings come from window.alpsAuthorPanel.
import {registerPlugin} from '@wordpress/plugins';
import {PluginDocumentSettingPanel, PluginPrePublishPanel, store as editorStore} from '@wordpress/editor';
import {useEntityProp, store as coreStore} from '@wordpress/core-data';
import {useSelect} from '@wordpress/data';
import {useState} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {BaseControl, Button, Notice, TextControl, TextareaControl} from '@wordpress/components';
import {__, sprintf} from '@wordpress/i18n';

const config = () => window.alpsAuthorPanel;

// A term name as plain text (entities decoded); React escapes it again.
const plainText = (html) => new DOMParser().parseFromString(html || '', 'text/html').body.textContent || '';

function AuthorName({id}) {
  const term = useSelect((select) => select(coreStore).getEntityRecord('taxonomy', config().field, id, {context: 'view', _fields: 'id,name'}), [id]);
  return <span className="alps-author-panel__name">{term ? plainText(term.name) : sprintf('#%d', id)}</span>;
}

function NewAuthor({onAdd}) {
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [bio, setBio] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [existing, setExisting] = useState(null);
  const reset = () => {
    setOpen(false); setName(''); setBio(''); setError(''); setExisting(null);
  };
  const add = async () => {
    const trimmed = name.trim();
    if (!trimmed) {
      setError(__('Enter a name.', 'alps'));
      return;
    }
    setBusy(true);
    setError('');
    setExisting(null);
    try {
      // Offer an author with the same name instead of a duplicate.
      const found = await apiFetch({path: `${config().path}?search=${encodeURIComponent(trimmed)}&per_page=100&_fields=id,name`});
      const same = found.find((term) => plainText(term.name).toLowerCase() === trimmed.toLowerCase());
      if (same) {
        setExisting({id: same.id, name: plainText(same.name)});
        return;
      }
      const term = await apiFetch({path: config().path, method: 'POST', data: {name: trimmed, description: bio.trim()}});
      onAdd(term.id);
      reset();
    } catch (failure) {
      if (failure?.code === 'term_exists' && failure?.data?.term_id) {
        setExisting({id: failure.data.term_id, name: trimmed});
      } else {
        setError(failure?.message || __('The author could not be added.', 'alps'));
      }
    } finally {
      setBusy(false);
    }
  };
  if (!open) {
    return <Button variant="secondary" size="compact" className="alps-author-panel__new" onClick={() => setOpen(true)}>
      {__('+ New author', 'alps')}
    </Button>;
  }
  return <div className="alps-author-panel__new-form" style={{display: 'grid', gap: 8, borderTop: '1px solid #ddd', paddingTop: 8}}>
    <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Name', 'alps')} value={name} onChange={setName} />
    <TextareaControl __nextHasNoMarginBottom label={__('Short bio (optional)', 'alps')} rows={3} value={bio} onChange={setBio}
      help={__('Add a photo and more later under Posts → Authors.', 'alps')} />
    {error ? <Notice status="error" isDismissible={false}>{error}</Notice> : null}
    {existing ? <Notice status="warning" isDismissible={false}>
      {sprintf(__('An author named “%s” already exists.', 'alps'), existing.name)}{' '}
      <Button variant="link" onClick={() => {
        onAdd(existing.id); reset();
      }}>{__('Use this author', 'alps')}</Button>
    </Notice> : null}
    <div style={{display: 'flex', gap: 8}}>
      <Button variant="primary" size="compact" isBusy={busy} disabled={busy} onClick={add}>{__('Add author', 'alps')}</Button>
      <Button variant="tertiary" size="compact" disabled={busy} onClick={reset}>{__('Cancel', 'alps')}</Button>
    </div>
  </div>;
}

function useAuthors() {
  const postType = useSelect((select) => select(editorStore).getCurrentPostType(), []);
  const [ids, setIds] = useEntityProp('postType', postType, config().field);
  return [Array.isArray(ids) ? ids : null, setIds];
}

function AuthorPanel() {
  const [ids, setIds] = useAuthors();
  const [search, setSearch] = useState('');
  const results = useSelect((select) => (search.trim()
    ? select(coreStore).getEntityRecords('taxonomy', config().field, {search: search.trim(), per_page: 20, context: 'view', _fields: 'id,name'}) || []
    : []), [search]);
  if (!ids) {
    return null;
  }
  const add = (id) => {
    if (!ids.includes(id)) {
      setIds([...ids, id]);
    }
    setSearch('');
  };
  const move = (index, step) => {
    const next = [...ids];
    [next[index], next[index + step]] = [next[index + step], next[index]];
    setIds(next);
  };
  return <PluginDocumentSettingPanel name="alps-author" title={__('Author', 'alps')} className="alps-author-panel">
    <div style={{display: 'grid', gap: 12}}>
      {ids.length ? <ol className="alps-author-panel__chosen" style={{margin: '0 0 0 1.5em'}}>
        {ids.map((id, index) => <li key={id} style={{display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 4}}>
          <AuthorName id={id} />
          <span style={{whiteSpace: 'nowrap'}}>
            <Button size="small" icon="arrow-up-alt2" label={__('Move up', 'alps')} disabled={index === 0} onClick={() => move(index, -1)} />
            <Button size="small" icon="arrow-down-alt2" label={__('Move down', 'alps')} disabled={index === ids.length - 1} onClick={() => move(index, 1)} />
            <Button size="small" icon="no-alt" isDestructive label={__('Remove', 'alps')} onClick={() => setIds(ids.filter((other) => other !== id))} />
          </span>
        </li>)}
      </ol> : <p style={{margin: 0}}>{__('No author chosen.', 'alps')}</p>}
      <BaseControl __nextHasNoMarginBottom>
        <TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={__('Find an author', 'alps')} value={search} onChange={setSearch} />
        <ul className="alps-author-panel__results" style={{marginTop: 4}}>
          {results.filter((term) => !ids.includes(term.id)).map((term) => <li key={term.id}>
            <Button variant="link" className="alps-author-panel__result" onClick={() => add(term.id)}>{plainText(term.name)}</Button>
          </li>)}
          {search.trim() && results.length === 0 ? <li>{__('No authors found.', 'alps')}</li> : null}
        </ul>
      </BaseControl>
      {config().canCreate ? <NewAuthor onAdd={add} /> : null}
    </div>
  </PluginDocumentSettingPanel>;
}

function PrePublishCheck() {
  const [ids] = useAuthors();
  if (!ids || ids.length) {
    return null;
  }
  const fallback = config().fallback;
  return <PluginPrePublishPanel title={__('Author', 'alps')} initialOpen className="alps-author-prepublish">
    <p>{fallback
      ? sprintf(__('No author is chosen. The post will be credited to “%s”.', 'alps'), fallback.name)
      : __('No author is chosen, and no default author is set: the post will have no author.', 'alps')}</p>
  </PluginPrePublishPanel>;
}

if (window.alpsAuthorPanel) {
  registerPlugin('alps-author-panel', {render: () => <><AuthorPanel /><PrePublishCheck /></>});
}
