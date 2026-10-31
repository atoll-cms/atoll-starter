<script>
  import { collections, entries, currentCollection, currentEntry, currentEntryId, addToast } from '../lib/stores.js';
  import { api } from '../lib/api.js';

  let saving = $state(false);
  let editorTitle = $state('');
  let editorSlug = $state('');
  let editorFrontmatter = $state('');
  let editorMarkdown = $state('');
  let previewHtml = $state('');
  let showPreview = $state(false);
  let seoScore = $state(0);
  let seoChecks = $state([]);
  let seoSnippetTitle = $state('');
  let seoSnippetUrl = $state('');
  let seoSnippetDescription = $state('');
  let collectionMeta = $state({});
  let schemaErrorByField = $state({});
  let showFrontmatterAdvanced = $state(false);
  let revisions = $state([]);
  let revisionsLoading = $state(false);
  let selectedRevision = $state(null);
  let compareOpen = $state(false);
  let restoringRevision = $state(false);

  const SYSTEM_FIELDS = new Set([
    'title',
    'slug',
    'excerpt',
    'seo_title',
    'seo_description',
    'seo_image',
    'draft'
  ]);

  $effect(() => {
    if ($currentEntry) {
      editorTitle = $currentEntry.title || '';
      const fm = { ...$currentEntry };
      delete fm.content;
      delete fm.markdown;
      editorFrontmatter = JSON.stringify(fm, null, 2);
      editorSlug = String(fm.slug || $currentEntry.slug || '').trim();
      editorMarkdown = $currentEntry.markdown || '';
      previewHtml = $currentEntry.content || '';
    }
  });

  $effect(() => {
    if (!$currentEntry) {
      seoScore = 0;
      seoChecks = [];
      seoSnippetTitle = '';
      seoSnippetUrl = '';
      seoSnippetDescription = '';
      revisions = [];
      selectedRevision = null;
      compareOpen = false;
      return;
    }

    const frontmatter = parseFrontmatter(editorFrontmatter);
    const title = String(frontmatter.seo_title || editorTitle || frontmatter.title || '').trim();
    const description = String(
      frontmatter.seo_description || frontmatter.excerpt || markdownExcerpt(editorMarkdown, 160)
    ).trim();
    const slug = String(frontmatter.slug || $currentEntry.slug || $currentEntry.id || '').trim();
    const canonicalUrl = String($currentEntry.url || buildEntryUrl($currentCollection, slug)).trim();

    seoSnippetTitle = title || '(Fehlender SEO-Titel)';
    seoSnippetUrl = canonicalUrl || '/';
    seoSnippetDescription = description || 'Keine Meta-Description gesetzt.';

    const checks = [];
    let score = 0;

    const titleLength = title.length;
    if (titleLength >= 30 && titleLength <= 60) {
      checks.push({
        label: 'SEO-Titel',
        status: 'good',
        message: `${titleLength} Zeichen (ideal 30-60).`,
        points: 20,
        max: 20
      });
      score += 20;
    } else if (titleLength > 0) {
      checks.push({
        label: 'SEO-Titel',
        status: 'warn',
        message: `${titleLength} Zeichen, besser 30-60.`,
        points: 10,
        max: 20
      });
      score += 10;
    } else {
      checks.push({
        label: 'SEO-Titel',
        status: 'bad',
        message: 'Fehlt komplett.',
        points: 0,
        max: 20
      });
    }

    const descriptionLength = description.length;
    if (descriptionLength >= 120 && descriptionLength <= 160) {
      checks.push({
        label: 'Meta-Description',
        status: 'good',
        message: `${descriptionLength} Zeichen (ideal 120-160).`,
        points: 25,
        max: 25
      });
      score += 25;
    } else if (descriptionLength >= 80 && descriptionLength <= 220) {
      checks.push({
        label: 'Meta-Description',
        status: 'warn',
        message: `${descriptionLength} Zeichen, noch optimierbar.`,
        points: 12,
        max: 25
      });
      score += 12;
    } else {
      checks.push({
        label: 'Meta-Description',
        status: 'bad',
        message: 'Zu kurz/lang oder fehlt.',
        points: 0,
        max: 25
      });
    }

    if (/^#\s+/m.test(editorMarkdown)) {
      checks.push({
        label: 'H1 im Content',
        status: 'good',
        message: 'Mindestens eine H1 gefunden.',
        points: 10,
        max: 10
      });
      score += 10;
    } else {
      checks.push({
        label: 'H1 im Content',
        status: 'warn',
        message: 'Keine H1-Markdown-Headline gefunden.',
        points: 0,
        max: 10
      });
    }

    if (/^##\s+/m.test(editorMarkdown)) {
      checks.push({
        label: 'Zwischenueberschriften',
        status: 'good',
        message: 'H2-Struktur vorhanden.',
        points: 10,
        max: 10
      });
      score += 10;
    } else {
      checks.push({
        label: 'Zwischenueberschriften',
        status: 'warn',
        message: 'Fuer Lesbarkeit H2 nutzen.',
        points: 0,
        max: 10
      });
    }

    const hasImage = !!frontmatter.seo_image || !!frontmatter.featured_image || /!\[[^\]]*]\([^)]+\)/.test(editorMarkdown);
    if (hasImage) {
      checks.push({
        label: 'Preview-Bild',
        status: 'good',
        message: 'OG/Featured Image vorhanden.',
        points: 15,
        max: 15
      });
      score += 15;
    } else {
      checks.push({
        label: 'Preview-Bild',
        status: 'warn',
        message: 'Kein OG/Featured Image gesetzt.',
        points: 0,
        max: 15
      });
    }

    const textLength = plainMarkdown(editorMarkdown).length;
    if (textLength >= 300) {
      checks.push({
        label: 'Inhaltstiefe',
        status: 'good',
        message: `${textLength} Zeichen Text.`,
        points: 10,
        max: 10
      });
      score += 10;
    } else if (textLength >= 120) {
      checks.push({
        label: 'Inhaltstiefe',
        status: 'warn',
        message: `${textLength} Zeichen Text, etwas knapp.`,
        points: 5,
        max: 10
      });
      score += 5;
    } else {
      checks.push({
        label: 'Inhaltstiefe',
        status: 'bad',
        message: 'Sehr wenig Content.',
        points: 0,
        max: 10
      });
    }

    seoChecks = checks;
    seoScore = Math.max(0, Math.min(100, score));
  });

  async function selectCollection(event) {
    currentCollection.set(event.target.value);
    await loadEntries();
    await loadCollectionMeta();
  }

  async function loadEntries() {
    const data = await api(`/admin/api/entries?collection=${encodeURIComponent($currentCollection)}`);
    entries.set(data.entries);
    currentEntry.set(null);
  }

  async function loadCollectionMeta() {
    const collection = String($currentCollection || '').trim();
    if (!collection) {
      collectionMeta = {};
      return;
    }

    try {
      const data = await api(`/admin/api/collection/meta?collection=${encodeURIComponent(collection)}`);
      collectionMeta = data.meta || {};
    } catch {
      collectionMeta = {};
    }
  }

  async function loadRevisions() {
    if (!$currentCollection || !$currentEntryId) {
      revisions = [];
      return;
    }

    revisionsLoading = true;
    try {
      const data = await api(`/admin/api/entry/revisions?collection=${encodeURIComponent($currentCollection)}&id=${encodeURIComponent($currentEntryId)}&limit=30`);
      revisions = Array.isArray(data?.revisions) ? data.revisions : [];
    } catch {
      revisions = [];
    } finally {
      revisionsLoading = false;
    }
  }

  async function compareRevision(revisionId) {
    if (!$currentCollection || !$currentEntryId || !revisionId) return;
    try {
      const data = await api(`/admin/api/entry/revision?collection=${encodeURIComponent($currentCollection)}&id=${encodeURIComponent($currentEntryId)}&revision=${encodeURIComponent(revisionId)}`);
      selectedRevision = data?.revision || null;
      compareOpen = selectedRevision !== null;
    } catch (err) {
      addToast(err?.message || 'Revision konnte nicht geladen werden.', 'error');
    }
  }

  async function restoreRevision(revisionId) {
    if (!$currentCollection || !$currentEntryId || !revisionId) return;
    if (!window.confirm('Diese Revision wirklich wiederherstellen?')) return;

    restoringRevision = true;
    try {
      await api('/admin/api/entry/revision/restore', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          collection: $currentCollection,
          id: $currentEntryId,
          revision: revisionId
        })
      });
      addToast('Revision wiederhergestellt.', 'success');
      compareOpen = false;
      selectedRevision = null;
      await loadEntries();
      await selectEntry($currentEntryId);
      await loadRevisions();
    } catch (err) {
      addToast(err?.message || 'Restore fehlgeschlagen.', 'error');
    } finally {
      restoringRevision = false;
    }
  }

  function revisionLabel(row) {
    const createdAt = String(row?.created_at || '');
    if (!createdAt) return row?.id || 'Unbekannt';
    const date = new Date(createdAt);
    if (Number.isNaN(date.getTime())) return createdAt;
    return date.toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' });
  }

  function diffSummary(currentMarkdown, revisionMarkdown) {
    const currentLines = String(currentMarkdown || '').split('\n');
    const revisionLines = String(revisionMarkdown || '').split('\n');
    const max = Math.max(currentLines.length, revisionLines.length);
    let changed = 0;
    for (let i = 0; i < max; i += 1) {
      if ((currentLines[i] || '') !== (revisionLines[i] || '')) {
        changed += 1;
      }
    }

    return {
      current: currentLines.length,
      revision: revisionLines.length,
      changed
    };
  }

  async function selectEntry(id) {
    currentEntryId.set(id);
    const data = await api(`/admin/api/entry?collection=${encodeURIComponent($currentCollection)}&id=${encodeURIComponent(id)}`);
    currentEntry.set(data.entry);
    await loadRevisions();
  }

  async function saveEntry(event) {
    event.preventDefault();
    saving = true;
    schemaErrorByField = {};

    let frontmatter;
    try {
      frontmatter = JSON.parse(editorFrontmatter);
    } catch {
      addToast('Frontmatter JSON ist ungueltig.', 'error');
      saving = false;
      return;
    }

    if (editorTitle.trim() !== '') {
      frontmatter.title = editorTitle.trim();
    }
    if (editorSlug.trim() !== '') {
      frontmatter.slug = editorSlug.trim();
    } else if (Object.prototype.hasOwnProperty.call(frontmatter, 'slug')) {
      delete frontmatter.slug;
    }

    try {
      const saveResult = await api('/admin/api/entry/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          collection: $currentCollection,
          id: $currentEntryId,
          frontmatter,
          markdown: editorMarkdown
        })
      });

      addToast('Eintrag gespeichert.', 'success');
      if (saveResult?.auto_redirect?.created) {
        addToast(`Auto-Redirect: ${saveResult.auto_redirect.from} -> ${saveResult.auto_redirect.to}`, 'info');
      } else if (saveResult?.auto_redirect?.reason === 'conflict') {
        addToast('Slug geaendert, aber Redirect-Konflikt erkannt. Bitte unter Redirects pruefen.', 'error');
      }
      await loadEntries();
      await loadCollectionMeta();
      await selectEntry($currentEntryId);
    } catch (err) {
      const message = err?.message || 'Speichern fehlgeschlagen.';
      if (err?.fields && typeof err.fields === 'object') {
        schemaErrorByField = err.fields;
      }
      addToast(message, 'error');
    } finally {
      saving = false;
    }
  }

  function schemaEntries() {
    const schema = collectionMeta?.schema;
    if (!schema || typeof schema !== 'object') {
      return [];
    }

    return Object.entries(schema)
      .filter(([name, rules]) => {
        if (!name || SYSTEM_FIELDS.has(name)) return false;
        return rules && typeof rules === 'object';
      })
      .sort(([a], [b]) => a.localeCompare(b));
  }

  function fieldType(rules) {
    return String(rules?.type || 'string').toLowerCase();
  }

  function fieldHelp(name, rules) {
    const parts = [];
    if (rules?.required) parts.push('Pflichtfeld');
    if (rules?.max_length) parts.push(`max ${rules.max_length} Zeichen`);
    if (rules?.min_items) parts.push(`min ${rules.min_items} Eintraege`);
    if (rules?.max_items) parts.push(`max ${rules.max_items} Eintraege`);
    if (rules?.of) parts.push(`Liste aus ${rules.of}`);
    if (rules?.collection) parts.push(`Quelle: ${rules.collection}`);
    if (parts.length === 0) return name;
    return `${name} · ${parts.join(' · ')}`;
  }

  function getFrontmatterObject() {
    return parseFrontmatter(editorFrontmatter);
  }

  function writeFrontmatterObject(frontmatter) {
    editorFrontmatter = JSON.stringify(frontmatter, null, 2);
  }

  function getFieldValue(name, rules) {
    const frontmatter = getFrontmatterObject();
    if (Object.prototype.hasOwnProperty.call(frontmatter, name)) {
      return frontmatter[name];
    }
    if (Object.prototype.hasOwnProperty.call(rules || {}, 'default')) {
      return rules.default;
    }
    return null;
  }

  function setFieldValue(name, rules, rawValue) {
    const frontmatter = getFrontmatterObject();
    const type = fieldType(rules);
    const next = normalizeFieldValue(type, rawValue);

    if (next === null || next === undefined || next === '') {
      if (rules?.required) {
        schemaErrorByField = { ...schemaErrorByField, [name]: 'required' };
      } else {
        const copy = { ...schemaErrorByField };
        delete copy[name];
        schemaErrorByField = copy;
      }
      delete frontmatter[name];
      writeFrontmatterObject(frontmatter);
      return;
    }

    const copy = { ...schemaErrorByField };
    delete copy[name];
    schemaErrorByField = copy;

    frontmatter[name] = next;
    writeFrontmatterObject(frontmatter);
  }

  function setFieldFromJson(name, rules, value) {
    const raw = String(value || '').trim();
    if (raw === '') {
      setFieldValue(name, rules, null);
      return;
    }

    try {
      const parsed = JSON.parse(raw);
      setFieldValue(name, rules, parsed);
    } catch {
      schemaErrorByField = { ...schemaErrorByField, [name]: 'invalid_json' };
      addToast(`Ungueltiges JSON im Feld "${name}".`, 'error');
    }
  }

  function normalizeFieldValue(type, rawValue) {
    switch (type) {
      case 'boolean':
        return !!rawValue;
      case 'number':
        if (rawValue === '' || rawValue === null) return null;
        return Number(rawValue);
      case 'integer':
        if (rawValue === '' || rawValue === null) return null;
        return parseInt(rawValue, 10);
      case 'date':
      case 'image':
      case 'text':
      case 'string':
        return String(rawValue ?? '').trim();
      case 'list':
      case 'relation': {
        if (Array.isArray(rawValue)) {
          return rawValue.map((v) => String(v).trim()).filter(Boolean);
        }
        return String(rawValue ?? '')
          .split(',')
          .map((v) => v.trim())
          .filter(Boolean);
      }
      case 'json':
        return typeof rawValue === 'object' ? rawValue : {};
      case 'repeater':
      case 'flexible':
        return Array.isArray(rawValue) ? rawValue : [];
      default:
        return rawValue;
    }
  }

  $effect(() => {
    if ($currentCollection) {
      loadCollectionMeta().catch(() => {});
    }
  });

  function parseFrontmatter(json) {
    try {
      const parsed = JSON.parse(json || '{}');
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
      return {};
    }
  }

  function plainMarkdown(markdown) {
    return String(markdown || '')
      .replace(/```[\s\S]*?```/g, ' ')
      .replace(/`[^`]*`/g, ' ')
      .replace(/!\[[^\]]*]\([^)]+\)/g, ' ')
      .replace(/\[[^\]]*]\([^)]+\)/g, ' ')
      .replace(/^#{1,6}\s+/gm, '')
      .replace(/[*_>~-]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function markdownExcerpt(markdown, maxLength = 160) {
    const text = plainMarkdown(markdown);
    if (text.length <= maxLength) return text;
    return `${text.slice(0, Math.max(0, maxLength - 1)).trim()}…`;
  }

  function buildEntryUrl(collection, slug) {
    const cleanSlug = String(slug || '').trim();
    if (!cleanSlug || cleanSlug === 'index') {
      return '/';
    }
    if (collection === 'pages') {
      return `/${cleanSlug.replace(/^\/+/, '')}`;
    }
    return `/${String(collection || '').replace(/^\/+|\/+$/g, '')}/${cleanSlug.replace(/^\/+/, '')}`;
  }
</script>

<div class="content-view">
  <div class="page-header">
    <h1>Content</h1>
    <div class="header-actions">
      <select class="collection-select" value={$currentCollection} onchange={selectCollection}>
        {#each $collections as col}
          <option value={col}>{col}</option>
        {/each}
      </select>
    </div>
  </div>

  <div class="content-layout">
    <div class="entry-list">
      <div class="list-header">
        <span class="list-count">{$entries.length} Eintraege</span>
      </div>
      <div class="list-items">
        {#each $entries as entry}
          <button
            class="entry-item"
            class:active={$currentEntryId === entry.id}
            onclick={() => selectEntry(entry.id)}
          >
            <span class="entry-title">{entry.title || '(ohne Titel)'}</span>
            <div class="entry-meta">
              <span class="entry-id">{entry.id}</span>
              {#if entry.draft}
                <span class="badge badge--draft">Draft</span>
              {:else}
                <span class="badge badge--live">Live</span>
              {/if}
            </div>
          </button>
        {/each}
      </div>
    </div>

    <div class="editor-area">
      {#if $currentEntry}
        <form onsubmit={saveEntry}>
          <div class="editor-toolbar">
            <div class="title-group">
              <input
                type="text"
                class="title-input"
                bind:value={editorTitle}
                placeholder="Titel"
              >
              <input
                type="text"
                class="slug-input"
                bind:value={editorSlug}
                placeholder="slug (z. B. hallo-welt)"
              >
            </div>
            <div class="toolbar-actions">
              <button
                type="button"
                class="toggle-preview"
                class:active={showPreview}
                onclick={() => showPreview = !showPreview}
              >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                Preview
              </button>
              <button type="submit" class="save-btn" disabled={saving}>
                {saving ? 'Speichert...' : 'Speichern'}
              </button>
            </div>
          </div>

          <div class="seo-panel">
            <div class="seo-score">
              <div class="seo-score__value">{seoScore}</div>
              <div class="seo-score__label">SEO Score</div>
              <div class="seo-progress">
                <span style={`width: ${seoScore}%`}></span>
              </div>
            </div>
            <div class="seo-snippet">
              <div class="seo-snippet__title">{seoSnippetTitle}</div>
              <div class="seo-snippet__url">{seoSnippetUrl}</div>
              <div class="seo-snippet__description">{seoSnippetDescription}</div>
            </div>
            <div class="seo-checks">
              {#each seoChecks as check}
                <div class="seo-check" class:good={check.status === 'good'} class:warn={check.status === 'warn'} class:bad={check.status === 'bad'}>
                  <span class="seo-check__name">{check.label}</span>
                  <span class="seo-check__points">{check.points}/{check.max}</span>
                  <span class="seo-check__message">{check.message}</span>
                </div>
              {/each}
            </div>
          </div>

          <div class="revisions-panel">
            <div class="revisions-header">
              <div>
                <strong>Versionen</strong>
                <span>{revisions.length} Snapshot(s)</span>
              </div>
              <button type="button" class="revision-refresh" onclick={loadRevisions} disabled={revisionsLoading}>
                {revisionsLoading ? 'Lade...' : 'Neu laden'}
              </button>
            </div>

            {#if revisions.length === 0}
              <p class="revisions-empty">Noch keine Revision vorhanden.</p>
            {:else}
              <div class="revisions-list">
                {#each revisions as revision}
                  <div class="revision-row">
                    <div class="revision-meta">
                      <div class="revision-time">{revisionLabel(revision)}</div>
                      <div class="revision-info">
                        <span>{revision.reason || 'save'}</span>
                        {#if revision.user}
                          <span>{revision.user}</span>
                        {/if}
                      </div>
                    </div>
                    <div class="revision-actions">
                      <button
                        type="button"
                        class="revision-btn"
                        class:active={selectedRevision?.id === revision.id && compareOpen}
                        onclick={() => compareRevision(revision.id)}
                      >
                        Vergleichen
                      </button>
                      <button
                        type="button"
                        class="revision-btn revision-btn--restore"
                        onclick={() => restoreRevision(revision.id)}
                        disabled={restoringRevision}
                      >
                        Wiederherstellen
                      </button>
                    </div>
                  </div>
                {/each}
              </div>
            {/if}

            {#if compareOpen && selectedRevision}
              {@const summary = diffSummary(editorMarkdown, selectedRevision.markdown)}
              <div class="revision-compare">
                <div class="revision-compare__meta">
                  <strong>Vergleich mit {revisionLabel(selectedRevision)}</strong>
                  <span>{summary.changed} geaenderte Zeile(n) · Aktuell {summary.current} · Revision {summary.revision}</span>
                </div>
                <div class="revision-compare__grid">
                  <div>
                    <div class="pane-label">Aktuell</div>
                    <pre>{editorMarkdown}</pre>
                  </div>
                  <div>
                    <div class="pane-label">Revision</div>
                    <pre>{selectedRevision.markdown}</pre>
                  </div>
                </div>
              </div>
            {/if}
          </div>

          <div class="editor-split" class:with-preview={showPreview}>
            <div class="editor-panes">
              {#if schemaEntries().length > 0}
                <div class="pane pane--fields">
                  <div class="pane-label">Custom Fields ({schemaEntries().length})</div>
                  <div class="fields-grid">
                    {#each schemaEntries() as [name, rules]}
                      {@const type = fieldType(rules)}
                      {@const value = getFieldValue(name, rules)}
                      <label class="field">
                        <span class="field__label">{name}</span>
                        <span class="field__meta">{fieldHelp(name, rules)}</span>

                        {#if type === 'boolean'}
                          <input
                            type="checkbox"
                            checked={!!value}
                            onchange={(event) => setFieldValue(name, rules, event.currentTarget?.checked)}
                          >
                        {:else if type === 'text'}
                          <textarea
                            rows="3"
                            value={String(value ?? '')}
                            oninput={(event) => setFieldValue(name, rules, event.currentTarget?.value)}
                          ></textarea>
                        {:else if type === 'date'}
                          <input
                            type="date"
                            value={String(value ?? '')}
                            oninput={(event) => setFieldValue(name, rules, event.currentTarget?.value)}
                          >
                        {:else if type === 'number' || type === 'integer'}
                          <input
                            type="number"
                            value={value ?? ''}
                            step={type === 'integer' ? '1' : 'any'}
                            oninput={(event) => setFieldValue(name, rules, event.currentTarget?.value)}
                          >
                        {:else if type === 'list' || type === 'relation'}
                          <input
                            type="text"
                            value={Array.isArray(value) ? value.join(', ') : String(value ?? '')}
                            placeholder="wert-1, wert-2"
                            oninput={(event) => setFieldValue(name, rules, event.currentTarget?.value)}
                          >
                        {:else if type === 'json' || type === 'repeater' || type === 'flexible'}
                          <textarea
                            rows="4"
                            value={JSON.stringify(value ?? (type === 'json' ? {} : []), null, 2)}
                            onblur={(event) => setFieldFromJson(name, rules, event.currentTarget?.value)}
                          ></textarea>
                        {:else}
                          <input
                            type="text"
                            value={String(value ?? '')}
                            oninput={(event) => setFieldValue(name, rules, event.currentTarget?.value)}
                          >
                        {/if}

                        {#if schemaErrorByField[name]}
                          <span class="field__error">{schemaErrorByField[name]}</span>
                        {/if}
                      </label>
                    {/each}
                  </div>
                </div>
              {/if}

              <div class="pane">
                <div class="pane-label pane-label--row">
                  <span>Frontmatter (JSON)</span>
                  <button type="button" class="json-toggle" onclick={() => showFrontmatterAdvanced = !showFrontmatterAdvanced}>
                    {showFrontmatterAdvanced ? 'Ausblenden' : 'Anzeigen'}
                  </button>
                </div>
                {#if showFrontmatterAdvanced}
                  <textarea
                    class="code-editor"
                    bind:value={editorFrontmatter}
                    rows="8"
                    spellcheck="false"
                  ></textarea>
                {/if}
              </div>
              <div class="pane pane--markdown">
                <div class="pane-label">Markdown</div>
                <textarea
                  class="code-editor markdown-editor"
                  bind:value={editorMarkdown}
                  spellcheck="false"
                ></textarea>
              </div>
            </div>
            {#if showPreview}
              <div class="preview-pane">
                <div class="pane-label">Vorschau</div>
                <div class="preview-content">{@html previewHtml}</div>
              </div>
            {/if}
          </div>
        </form>
      {:else}
        <div class="empty-state">
          <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          <p>Waehle links einen Eintrag zum Bearbeiten.</p>
        </div>
      {/if}
    </div>
  </div>
</div>

<style>
  .content-view {
    height: calc(100vh - 2rem);
    display: flex;
    flex-direction: column;
  }

  .page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
    flex-shrink: 0;
  }

  .page-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    letter-spacing: -0.02em;
  }

  .collection-select {
    padding: 0.5rem 0.8rem;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text);
    font: inherit;
    font-size: 0.9rem;
    cursor: pointer;
  }

  .content-layout {
    flex: 1;
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 1rem;
    min-height: 0;
  }

  .entry-list {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .list-header {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--line);
    flex-shrink: 0;
  }

  .list-count {
    font-size: 0.8rem;
    color: var(--muted);
    font-weight: 500;
  }

  .list-items {
    overflow-y: auto;
    flex: 1;
    padding: 0.5rem;
  }

  .entry-item {
    display: block;
    width: 100%;
    padding: 0.65rem 0.75rem;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 8px;
    color: var(--text);
    font: inherit;
    font-size: 0.9rem;
    text-align: left;
    cursor: pointer;
    transition: all 0.15s;
    margin-bottom: 2px;
  }

  .entry-item:hover {
    background: var(--surface-2);
  }

  .entry-item.active {
    background: rgba(245, 158, 11, 0.08);
    border-color: rgba(245, 158, 11, 0.2);
  }

  .entry-title {
    display: block;
    font-weight: 500;
    margin-bottom: 0.2rem;
  }

  .entry-meta {
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .entry-id {
    font-size: 0.75rem;
    color: var(--muted);
    font-family: 'JetBrains Mono', monospace;
  }

  .badge {
    display: inline-block;
    padding: 0.1rem 0.45rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
  }

  .badge--live {
    background: rgba(34, 197, 94, 0.1);
    color: #22c55e;
    border: 1px solid rgba(34, 197, 94, 0.25);
  }

  .badge--draft {
    background: rgba(245, 158, 11, 0.1);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.25);
  }

  .editor-area {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .editor-toolbar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--line);
    flex-shrink: 0;
  }

  .title-group {
    flex: 1;
    min-width: 0;
    display: grid;
    gap: 0.2rem;
  }

  .title-input {
    width: 100%;
    padding: 0.4rem 0;
    background: transparent;
    border: none;
    color: var(--text);
    font: inherit;
    font-size: 1.1rem;
    font-weight: 600;
  }

  .title-input:focus {
    outline: none;
  }

  .slug-input {
    width: 100%;
    padding: 0.2rem 0;
    background: transparent;
    border: none;
    color: var(--muted);
    font: inherit;
    font-size: 0.75rem;
    font-family: 'JetBrains Mono', monospace;
  }

  .slug-input:focus {
    outline: none;
    color: var(--text);
  }

  .toolbar-actions {
    display: flex;
    gap: 0.5rem;
    flex-shrink: 0;
  }

  .toggle-preview {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.75rem;
    background: transparent;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--muted);
    font: inherit;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.15s;
  }

  .toggle-preview:hover,
  .toggle-preview.active {
    background: var(--surface-2);
    color: var(--text);
    border-color: var(--brand);
  }

  .save-btn {
    padding: 0.4rem 1rem;
    background: var(--brand);
    border: none;
    border-radius: 8px;
    color: #1a1a1a;
    font: inherit;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.15s;
  }

  .save-btn:hover:not(:disabled) {
    opacity: 0.9;
  }

  .save-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  .editor-split {
    flex: 1;
    display: flex;
    min-height: 0;
  }

  .seo-panel {
    display: grid;
    grid-template-columns: 190px minmax(0, 1fr);
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--line);
    background: linear-gradient(180deg, rgba(15, 28, 31, 0.5), rgba(15, 28, 31, 0.18));
  }

  .seo-score {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 0.6rem 0.75rem;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: rgba(8, 20, 24, 0.65);
  }

  .seo-score__value {
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: -0.03em;
    color: var(--brand);
    line-height: 1;
  }

  .seo-score__label {
    font-size: 0.72rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--muted);
    font-weight: 600;
  }

  .seo-progress {
    margin-top: 0.35rem;
    width: 100%;
    height: 6px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
    overflow: hidden;
  }

  .seo-progress span {
    display: block;
    height: 100%;
    background: linear-gradient(90deg, #ef4444, #f59e0b 50%, #22c55e);
  }

  .seo-snippet {
    min-width: 0;
    padding: 0.6rem 0.75rem;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: rgba(8, 20, 24, 0.65);
  }

  .seo-snippet__title {
    color: #8ab4f8;
    font-size: 0.95rem;
    margin-bottom: 0.2rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .seo-snippet__url {
    color: #34a853;
    font-size: 0.75rem;
    margin-bottom: 0.3rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .seo-snippet__description {
    color: var(--muted);
    font-size: 0.8rem;
    line-height: 1.45;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  .seo-checks {
    grid-column: 1 / -1;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 0.5rem;
  }

  .seo-check {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 0.15rem 0.5rem;
    padding: 0.55rem 0.65rem;
    border-radius: 9px;
    border: 1px solid var(--line);
    background: rgba(8, 20, 24, 0.5);
  }

  .seo-check.good {
    border-color: rgba(34, 197, 94, 0.35);
    background: rgba(34, 197, 94, 0.1);
  }

  .seo-check.warn {
    border-color: rgba(245, 158, 11, 0.35);
    background: rgba(245, 158, 11, 0.1);
  }

  .seo-check.bad {
    border-color: rgba(239, 68, 68, 0.35);
    background: rgba(239, 68, 68, 0.1);
  }

  .seo-check__name {
    font-size: 0.78rem;
    font-weight: 600;
  }

  .seo-check__points {
    font-size: 0.72rem;
    color: var(--muted);
  }

  .seo-check__message {
    grid-column: 1 / -1;
    font-size: 0.74rem;
    color: var(--muted);
    line-height: 1.4;
  }

  .revisions-panel {
    border-bottom: 1px solid var(--line);
    background: rgba(8, 20, 24, 0.3);
    padding: 0.7rem 1rem;
    display: grid;
    gap: 0.55rem;
  }

  .revisions-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
  }

  .revisions-header strong {
    font-size: 0.86rem;
    display: block;
  }

  .revisions-header span {
    font-size: 0.73rem;
    color: var(--muted);
  }

  .revision-refresh {
    border: 1px solid var(--line);
    background: transparent;
    color: var(--muted);
    border-radius: 8px;
    padding: 0.3rem 0.7rem;
    font-size: 0.75rem;
    cursor: pointer;
  }

  .revision-refresh:hover:not(:disabled) {
    color: var(--text);
    border-color: var(--brand);
  }

  .revision-refresh:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .revisions-empty {
    margin: 0;
    color: var(--muted);
    font-size: 0.78rem;
  }

  .revisions-list {
    display: grid;
    gap: 0.35rem;
    max-height: 180px;
    overflow: auto;
    padding-right: 0.15rem;
  }

  .revision-row {
    border: 1px solid var(--line);
    border-radius: 10px;
    background: rgba(8, 20, 24, 0.55);
    padding: 0.48rem 0.58rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
  }

  .revision-meta {
    min-width: 0;
  }

  .revision-time {
    font-size: 0.78rem;
    font-weight: 600;
  }

  .revision-info {
    display: flex;
    gap: 0.35rem;
    margin-top: 0.15rem;
    color: var(--muted);
    font-size: 0.7rem;
  }

  .revision-info span {
    border: 1px solid var(--line);
    border-radius: 999px;
    padding: 0.08rem 0.4rem;
  }

  .revision-actions {
    display: flex;
    gap: 0.35rem;
    flex-shrink: 0;
  }

  .revision-btn {
    border: 1px solid var(--line);
    background: transparent;
    color: var(--muted);
    border-radius: 8px;
    padding: 0.3rem 0.58rem;
    font-size: 0.74rem;
    cursor: pointer;
  }

  .revision-btn:hover {
    color: var(--text);
    border-color: var(--brand);
  }

  .revision-btn.active {
    color: var(--text);
    border-color: var(--brand);
    background: rgba(245, 158, 11, 0.12);
  }

  .revision-btn--restore {
    color: #86efac;
    border-color: rgba(34, 197, 94, 0.35);
  }

  .revision-btn--restore:hover {
    border-color: rgba(34, 197, 94, 0.6);
    color: #bbf7d0;
  }

  .revision-compare {
    border: 1px solid var(--line);
    border-radius: 10px;
    overflow: hidden;
    background: rgba(7, 17, 20, 0.8);
  }

  .revision-compare__meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.45rem 0.7rem;
    border-bottom: 1px solid var(--line);
    background: rgba(8, 20, 24, 0.85);
  }

  .revision-compare__meta strong {
    font-size: 0.77rem;
  }

  .revision-compare__meta span {
    font-size: 0.7rem;
    color: var(--muted);
  }

  .revision-compare__grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0;
  }

  .revision-compare__grid > div + div {
    border-left: 1px solid var(--line);
  }

  .revision-compare__grid pre {
    margin: 0;
    padding: 0.7rem;
    max-height: 180px;
    overflow: auto;
    white-space: pre-wrap;
    font-size: 0.73rem;
    line-height: 1.5;
    font-family: 'JetBrains Mono', monospace;
  }

  .editor-panes {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
  }

  .editor-split.with-preview .editor-panes {
    flex: 1;
    border-right: 1px solid var(--line);
  }

  .pane {
    display: flex;
    flex-direction: column;
  }

  .pane--fields {
    border-bottom: 1px solid var(--line);
    background: rgba(8, 20, 24, 0.35);
  }

  .fields-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 0.75rem;
    padding: 0.75rem 1rem;
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    padding: 0.6rem;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: rgba(8, 20, 24, 0.55);
  }

  .field__label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text);
  }

  .field__meta {
    font-size: 0.7rem;
    color: var(--muted);
  }

  .field input[type='text'],
  .field input[type='date'],
  .field input[type='number'],
  .field textarea {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text);
    padding: 0.45rem 0.55rem;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.75rem;
  }

  .field input[type='checkbox'] {
    width: 16px;
    height: 16px;
  }

  .field__error {
    color: #fda4af;
    font-size: 0.72rem;
  }

  .pane--markdown {
    flex: 1;
    min-height: 0;
    border-top: 1px solid var(--line);
  }

  .pane-label {
    padding: 0.4rem 1rem;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--muted);
    background: var(--bg);
    border-bottom: 1px solid var(--line);
    flex-shrink: 0;
  }

  .pane-label--row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
  }

  .json-toggle {
    border: 1px solid var(--line);
    border-radius: 999px;
    background: transparent;
    color: var(--muted);
    font-size: 0.68rem;
    text-transform: none;
    letter-spacing: 0;
    padding: 0.15rem 0.5rem;
    cursor: pointer;
  }

  .json-toggle:hover {
    color: var(--text);
    border-color: var(--brand);
  }

  .code-editor {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--bg);
    border: none;
    color: var(--text);
    font-family: 'JetBrains Mono', 'Fira Code', monospace;
    font-size: 0.85rem;
    line-height: 1.6;
    resize: none;
    tab-size: 2;
  }

  .code-editor:focus {
    outline: none;
  }

  .markdown-editor {
    flex: 1;
    min-height: 300px;
  }

  .preview-pane {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
  }

  .preview-content {
    flex: 1;
    padding: 1rem;
    overflow-y: auto;
    font-size: 0.9rem;
    line-height: 1.7;
  }

  .preview-content :global(h1),
  .preview-content :global(h2),
  .preview-content :global(h3) {
    margin-top: 1.5em;
    margin-bottom: 0.5em;
  }

  .preview-content :global(p) {
    margin: 0.75em 0;
  }

  .preview-content :global(code) {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.85em;
    padding: 0.1em 0.3em;
    background: var(--surface-2);
    border-radius: 4px;
  }

  .empty-state {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--muted);
    gap: 1rem;
  }

  .empty-state svg {
    opacity: 0.3;
  }

  .empty-state p {
    font-size: 0.9rem;
  }

  @media (max-width: 768px) {
    .content-layout {
      grid-template-columns: 1fr;
    }

    .entry-list {
      max-height: 200px;
    }

    .seo-panel {
      grid-template-columns: 1fr;
    }

    .revision-row {
      flex-direction: column;
      align-items: flex-start;
    }

    .revision-actions {
      width: 100%;
    }

    .revision-btn {
      flex: 1;
    }

    .revision-compare__grid {
      grid-template-columns: 1fr;
    }

    .revision-compare__grid > div + div {
      border-left: none;
      border-top: 1px solid var(--line);
    }
  }
</style>
