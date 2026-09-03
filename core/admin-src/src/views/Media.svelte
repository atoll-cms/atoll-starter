<script>
  import { onMount } from 'svelte';
  import { addToast } from '../lib/stores.js';
  import { api, apiUpload } from '../lib/api.js';

  let uploading = $state(false);
  let processing = $state(false);
  let refreshing = $state(false);
  let dragover = $state(false);

  let files = $state([]);
  let selectedFile = $state('');
  let mode = $state('resize');
  let width = $state('1200');
  let height = $state('');
  let format = $state('');
  let quality = $state('82');
  let overwrite = $state(false);
  let savingMeta = $state(false);
  let metaEditor = $state({
    alt: '',
    caption: '',
    focal_point: { x: 0.5, y: 0.5 },
    copyright: '',
    license_url: ''
  });

  onMount(async () => {
    await loadMedia();
  });

  $effect(() => {
    if (files.length === 0) {
      selectedFile = '';
      return;
    }

    const current = files.find((file) => file.file === selectedFile);
    if (!current) {
      const firstImage = files.find((file) => file.is_image);
      selectedFile = firstImage?.file || files[0].file || '';
    }
  });

  $effect(() => {
    const current = selectedMeta();
    if (!current) return;
    const meta = current.meta || {};
    const focal = meta.focal_point || {};
    metaEditor = {
      alt: String(meta.alt || ''),
      caption: String(meta.caption || ''),
      focal_point: {
        x: Number(focal.x ?? 0.5),
        y: Number(focal.y ?? 0.5)
      },
      copyright: String(meta.copyright || ''),
      license_url: String(meta.license_url || '')
    };
  });

  async function loadMedia() {
    refreshing = true;
    try {
      const result = await api('/admin/api/media/list?limit=200');
      files = Array.isArray(result.files) ? result.files : [];
    } catch (err) {
      addToast(err.message || 'Media-Liste konnte nicht geladen werden.', 'error');
    } finally {
      refreshing = false;
    }
  }

  async function handleUpload(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    uploading = true;

    try {
      const result = await apiUpload('/admin/api/media/upload', formData);
      if (!result.ok) {
        addToast(result.error || 'Upload fehlgeschlagen.', 'error');
        return;
      }

      addToast(`Upload erfolgreich: ${result.file}`, 'success');
      event.target.reset();
      await loadMedia();
      selectedFile = result.file || selectedFile;
    } catch {
      addToast('Upload fehlgeschlagen.', 'error');
    } finally {
      uploading = false;
    }
  }

  async function applyTransform(event) {
    event.preventDefault();
    if (!selectedFile) {
      addToast('Bitte zuerst eine Datei waehlen.', 'error');
      return;
    }

    processing = true;
    try {
      const payload = {
        file: selectedFile,
        mode,
        width: Number(width || 0),
        height: Number(height || 0),
        quality: Number(quality || 82),
        format,
        overwrite,
        focal_point: metaEditor?.focal_point || { x: 0.5, y: 0.5 }
      };

      const result = await api('/admin/api/media/transform', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      addToast(`Bild verarbeitet: ${result.file}`, 'success');
      await loadMedia();
      selectedFile = result.file || selectedFile;
    } catch (err) {
      addToast(err.message || 'Bildverarbeitung fehlgeschlagen.', 'error');
    } finally {
      processing = false;
    }
  }

  async function saveMetadata() {
    if (!selectedFile || savingMeta) return;
    savingMeta = true;
    try {
      const result = await api('/admin/api/media/meta/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          file: selectedFile,
          meta: {
            ...metaEditor,
            focal_point: {
              x: Number(metaEditor?.focal_point?.x ?? 0.5),
              y: Number(metaEditor?.focal_point?.y ?? 0.5)
            }
          }
        })
      });

      files = files.map((file) => (
        file.file === selectedFile
          ? { ...file, meta: result.meta || file.meta }
          : file
      ));
      addToast('Metadaten gespeichert.', 'success');
    } catch (err) {
      addToast(err?.message || 'Metadaten konnten nicht gespeichert werden.', 'error');
    } finally {
      savingMeta = false;
    }
  }

  function handleDragOver(e) {
    e.preventDefault();
    dragover = true;
  }

  function handleDragLeave() {
    dragover = false;
  }

  function handleDrop(e) {
    e.preventDefault();
    dragover = false;
    const input = document.querySelector('.file-input');
    if (input && e.dataTransfer.files.length) {
      input.files = e.dataTransfer.files;
    }
  }

  function selectedMeta() {
    return files.find((file) => file.file === selectedFile) || null;
  }

  function formatBytes(value) {
    const bytes = Number(value || 0);
    if (bytes <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    const size = bytes / Math.pow(1024, i);
    return `${size.toFixed(size >= 10 || i === 0 ? 0 : 1)} ${units[i]}`;
  }
</script>

<div class="media-view">
  <div class="page-header">
    <h1>Media</h1>
    <button class="refresh-btn" onclick={loadMedia} disabled={refreshing}>
      {refreshing ? 'Lade...' : 'Aktualisieren'}
    </button>
  </div>

  <form onsubmit={handleUpload} class="upload-card">
    <div
      class="drop-zone"
      class:dragover
      ondragover={handleDragOver}
      ondragleave={handleDragLeave}
      ondrop={handleDrop}
      role="presentation"
    >
      <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      <p>Datei hierher ziehen oder klicken zum Auswaehlen</p>
      <input class="file-input" name="file" type="file" required>
    </div>
    <button type="submit" class="upload-btn" disabled={uploading}>
      {uploading ? 'Wird hochgeladen...' : 'Upload'}
    </button>
  </form>

  <div class="media-layout">
    <div class="library-card">
      <div class="card-head">
        <h3>Bibliothek</h3>
        <span>{files.length} Dateien</span>
      </div>
      <div class="media-grid">
        {#if files.length === 0}
          <div class="empty">Noch keine Uploads.</div>
        {:else}
          {#each files as file}
            <button
              class="media-item"
              class:active={file.file === selectedFile}
              onclick={() => selectedFile = file.file}
            >
              {#if file.is_image}
                <img src={file.file} alt={file.name} loading="lazy">
              {:else}
                <div class="placeholder">{file.name.split('.').pop()?.toUpperCase() || 'FILE'}</div>
              {/if}
              <div class="media-item__meta">
                <span class="name">{file.name}</span>
                <span class="sub">{formatBytes(file.size)}</span>
              </div>
            </button>
          {/each}
        {/if}
      </div>
    </div>

    <form class="transform-card" onsubmit={applyTransform}>
      <div class="card-head">
        <h3>Bildbearbeitung</h3>
      </div>

      {#if selectedMeta()}
        <div class="selected-preview">
          {#if selectedMeta().is_image}
            <img src={selectedMeta().file} alt={selectedMeta().name}>
          {/if}
          <div class="selected-preview__meta">
            <div>{selectedMeta().name}</div>
            <div class="sub">{selectedMeta().width || '-'} x {selectedMeta().height || '-'}</div>
            <div class="sub">{formatBytes(selectedMeta().size)}</div>
          </div>
        </div>
      {:else}
        <p class="empty">Bitte links eine Datei waehlen.</p>
      {/if}

      {#if selectedFile}
        <div class="meta-editor">
          <div class="meta-editor__head">
            <h4>Metadaten</h4>
            <button type="button" class="meta-save-btn" onclick={saveMetadata} disabled={savingMeta}>
              {savingMeta ? 'Speichert...' : 'Metadaten speichern'}
            </button>
          </div>
          <div class="meta-grid">
            <div class="field">
              <label for="meta-alt">Alt-Text</label>
              <input id="meta-alt" bind:value={metaEditor.alt} type="text" placeholder="Beschreibender Alt-Text">
            </div>
            <div class="field">
              <label for="meta-caption">Caption</label>
              <input id="meta-caption" bind:value={metaEditor.caption} type="text" placeholder="Bildunterschrift">
            </div>
            <div class="field">
              <label for="meta-focal-x">Focal X (0-1)</label>
              <input id="meta-focal-x" bind:value={metaEditor.focal_point.x} type="number" min="0" max="1" step="0.01">
            </div>
            <div class="field">
              <label for="meta-focal-y">Focal Y (0-1)</label>
              <input id="meta-focal-y" bind:value={metaEditor.focal_point.y} type="number" min="0" max="1" step="0.01">
            </div>
            <div class="field">
              <label for="meta-copyright">Copyright</label>
              <input id="meta-copyright" bind:value={metaEditor.copyright} type="text" placeholder="z. B. Foto: Max Mustermann">
            </div>
            <div class="field">
              <label for="meta-license">License URL</label>
              <input id="meta-license" bind:value={metaEditor.license_url} type="url" placeholder="https://...">
            </div>
          </div>
        </div>
      {/if}

      <div class="field-row">
        <div class="field">
          <label for="mode">Modus</label>
          <select id="mode" bind:value={mode}>
            <option value="resize">Resize</option>
            <option value="crop">Crop</option>
          </select>
        </div>
        <div class="field">
          <label for="format">Format</label>
          <select id="format" bind:value={format}>
            <option value="">Original</option>
            <option value="jpg">JPG</option>
            <option value="png">PNG</option>
            <option value="webp">WebP</option>
            <option value="avif">AVIF</option>
          </select>
        </div>
      </div>

      <div class="field-row">
        <div class="field">
          <label for="width">Breite</label>
          <input id="width" bind:value={width} type="number" min="1" placeholder="1200">
        </div>
        <div class="field">
          <label for="height">Hoehe</label>
          <input id="height" bind:value={height} type="number" min="1" placeholder={mode === 'crop' ? '630' : 'auto'}>
        </div>
      </div>

      <div class="field-row">
        <div class="field">
          <label for="quality">Qualitaet</label>
          <input id="quality" bind:value={quality} type="number" min="1" max="100" placeholder="82">
        </div>
        <div class="field field--check">
          <label><input type="checkbox" bind:checked={overwrite}> Original ueberschreiben (nur gleiches Format)</label>
        </div>
      </div>

      <button class="transform-btn" type="submit" disabled={processing || !selectedFile}>
        {processing ? 'Verarbeite...' : 'Transformation ausfuehren'}
      </button>
    </form>
  </div>
</div>

<style>
  .media-view {
    display: flex;
    flex-direction: column;
    gap: 1rem;
  }

  .page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .page-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    letter-spacing: -0.02em;
  }

  .refresh-btn {
    padding: 0.45rem 0.75rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--surface);
    color: var(--text);
    font: inherit;
    cursor: pointer;
  }

  .refresh-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .upload-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 1rem;
  }

  .drop-zone {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.65rem;
    padding: 1.5rem;
    border: 2px dashed var(--line);
    border-radius: 12px;
    text-align: center;
    color: var(--muted);
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
    margin-bottom: 0.75rem;
  }

  .drop-zone:hover,
  .drop-zone.dragover {
    border-color: var(--brand);
    background: rgba(245, 158, 11, 0.04);
    color: var(--text);
  }

  .drop-zone p {
    font-size: 0.85rem;
    margin: 0;
  }

  .file-input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
  }

  .upload-btn {
    width: 100%;
    padding: 0.6rem;
    background: var(--brand);
    border: none;
    border-radius: 8px;
    color: #1a1a1a;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
  }

  .upload-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  .media-layout {
    display: grid;
    grid-template-columns: 1.35fr 1fr;
    gap: 1rem;
    min-height: 420px;
  }

  .library-card,
  .transform-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    overflow: hidden;
  }

  .card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1rem;
    border-bottom: 1px solid var(--line);
  }

  .card-head h3 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
  }

  .card-head span {
    color: var(--muted);
    font-size: 0.8rem;
  }

  .media-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 0.6rem;
    padding: 0.85rem;
    max-height: 520px;
    overflow-y: auto;
  }

  .media-item {
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--bg);
    overflow: hidden;
    cursor: pointer;
    text-align: left;
    padding: 0;
    color: inherit;
  }

  .media-item.active {
    border-color: var(--brand);
    box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.4);
  }

  .media-item img,
  .placeholder {
    width: 100%;
    height: 92px;
    object-fit: cover;
    display: grid;
    place-items: center;
    background: rgba(255, 255, 255, 0.04);
    color: var(--muted);
    font-size: 0.7rem;
    font-weight: 600;
  }

  .media-item__meta {
    padding: 0.45rem 0.5rem 0.5rem;
    display: grid;
    gap: 0.15rem;
  }

  .media-item__meta .name {
    font-size: 0.72rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .media-item__meta .sub {
    color: var(--muted);
    font-size: 0.68rem;
  }

  .transform-card {
    padding-bottom: 1rem;
  }

  .selected-preview {
    display: grid;
    grid-template-columns: 110px 1fr;
    gap: 0.75rem;
    padding: 0.9rem 1rem;
  }

  .selected-preview img {
    width: 100%;
    height: 90px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--line);
  }

  .selected-preview__meta {
    display: grid;
    gap: 0.2rem;
    align-content: center;
    font-size: 0.82rem;
  }

  .sub {
    color: var(--muted);
    font-size: 0.74rem;
  }

  .meta-editor {
    margin: 0 1rem 0.85rem;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.02);
  }

  .meta-editor__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.6rem 0.8rem;
    border-bottom: 1px solid var(--line);
  }

  .meta-editor__head h4 {
    margin: 0;
    font-size: 0.82rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--muted);
  }

  .meta-save-btn {
    padding: 0.38rem 0.65rem;
    background: transparent;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text);
    font: inherit;
    font-size: 0.76rem;
    cursor: pointer;
  }

  .meta-save-btn:hover {
    border-color: var(--brand);
    color: var(--brand);
  }

  .meta-save-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .meta-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 0.6rem;
    padding-top: 0.75rem;
  }

  .field {
    padding: 0 1rem;
    margin-bottom: 0.75rem;
  }

  .field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.65rem;
  }

  .field label {
    display: block;
    font-size: 0.73rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .field input,
  .field select {
    width: 100%;
    padding: 0.55rem 0.65rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--bg);
    color: var(--text);
    font: inherit;
    font-size: 0.85rem;
  }

  .field--check {
    display: flex;
    align-items: end;
  }

  .field--check label {
    font-size: 0.78rem;
    text-transform: none;
    letter-spacing: 0;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    color: var(--text);
    margin: 0;
  }

  .field--check input[type='checkbox'] {
    width: auto;
  }

  .transform-btn {
    margin: 0 1rem;
    width: calc(100% - 2rem);
    padding: 0.62rem;
    background: var(--brand);
    border: none;
    border-radius: 8px;
    color: #1a1a1a;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
  }

  .transform-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  .empty {
    color: var(--muted);
    font-size: 0.84rem;
    padding: 1rem;
  }

  @media (max-width: 960px) {
    .media-layout {
      grid-template-columns: 1fr;
    }

    .meta-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
