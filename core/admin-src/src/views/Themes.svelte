<script>
  import { themes, themeRegistry, settings, addToast, confirm } from '../lib/stores.js';
  import { api } from '../lib/api.js';

  let installSource = $state('');
  let busy = $state({});
  let licenseByTheme = $state({});

  function normalizePreview(value) {
    const raw = String(value || '').trim();
    if (raw === '') return null;
    if (raw.startsWith('http://') || raw.startsWith('https://') || raw.startsWith('/')) return raw;
    return '/' + raw;
  }

  function setBusy(id, value) {
    busy = { ...busy, [id]: value };
  }

  function priceLabel(row) {
    const value = Number(row?.price_eur ?? 0);
    return Number.isFinite(value) && value > 0 ? `${value.toFixed(0)} EUR` : 'Kostenlos';
  }

  function buildCatalog(installed, registry) {
    const map = new Map();

    for (const item of registry || []) {
      if (!item?.id) continue;
      map.set(item.id, {
        id: item.id,
        name: item.name || item.id,
        description: item.description || '',
        preview: normalizePreview(item.preview || item.screenshot || item.thumbnail),
        installed: !!item.installed,
        active: !!item.active,
        source: item.type || 'registry',
        price_eur: Number(item.price_eur ?? 0),
        seller: item.seller || 'Community',
        requires_license: !!item.requires_license,
        has_license: !!item.has_license,
        checkout_url: item.checkout_url || ''
      });
    }

    for (const item of installed || []) {
      if (!item?.id) continue;
      const existing = map.get(item.id) || {
        id: item.id,
        name: item.id,
        description: '',
        preview: null,
        source: item.source || 'site',
        price_eur: 0,
        seller: item.source === 'core' ? 'atoll-cms' : 'Site',
        requires_license: false,
        has_license: false,
        checkout_url: ''
      };

      map.set(item.id, {
        ...existing,
        preview: normalizePreview(item.preview) || existing.preview,
        installed: true,
        active: !!item.active,
        source: item.source || existing.source
      });
    }

    return [...map.values()].sort((a, b) => {
      const rankA = a.active ? 0 : a.installed ? 1 : 2;
      const rankB = b.active ? 0 : b.installed ? 1 : 2;
      if (rankA !== rankB) return rankA - rankB;
      return a.name.localeCompare(b.name);
    });
  }

  async function refreshThemeData() {
    const [t, s, reg] = await Promise.all([
      api('/admin/api/themes'),
      api('/admin/api/settings'),
      api('/admin/api/theme-registry')
    ]);
    themes.set(t.themes || []);
    settings.set(s.settings || {});
    themeRegistry.set(reg.registry || []);
  }

  async function activateTheme(id) {
    if (busy[id]) return;
    setBusy(id, true);
    try {
      await api('/admin/api/themes/activate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
      });
      await refreshThemeData();
      addToast('Theme aktiviert.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      setBusy(id, false);
    }
  }

  async function installFromRegistry(row) {
    const id = row?.id;
    if (!id || busy[id]) return;

    const licenseKey = String(licenseByTheme[id] || '').trim();
    if (row?.requires_license && !row?.has_license && licenseKey === '') {
      addToast('Dieses Theme benoetigt einen Lizenzschluessel.', 'error');
      return;
    }

    setBusy(id, true);
    try {
      await api('/admin/api/themes/install', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id,
          license_key: licenseKey !== '' ? licenseKey : undefined
        })
      });
      licenseByTheme = { ...licenseByTheme, [id]: '' };
      await refreshThemeData();
      addToast('Theme installiert.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      setBusy(id, false);
    }
  }

  async function uninstallTheme(id) {
    if (busy[id]) return;

    const accepted = await confirm(
      'Theme deinstallieren',
      `Soll ${id} wirklich deinstalliert werden?`
    );
    if (!accepted) return;

    setBusy(id, true);
    try {
      await api('/admin/api/themes/uninstall', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
      });
      await refreshThemeData();
      addToast('Theme deinstalliert.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      setBusy(id, false);
    }
  }

  async function installFromSource(event) {
    event.preventDefault();
    const source = installSource.trim();
    if (source === '') return;

    try {
      await api('/admin/api/themes/install', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ source })
      });
      installSource = '';
      await refreshThemeData();
      addToast('Theme installiert.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }
</script>

<div class="themes-view">
  <div class="page-header">
    <h1>Themes</h1>
    <p>Marketplace fuer freie und kostenpflichtige Themes.</p>
  </div>

  <div class="section-card">
    <div class="section-header">
      <h3>Theme Marketplace</h3>
    </div>

    <div class="catalog-grid">
      {#each buildCatalog($themes, $themeRegistry) as t}
        <article class="theme-card">
          <div class="theme-preview-wrap">
            {#if t.preview}
              <img class="theme-preview" src={t.preview} alt={`Preview ${t.name}`} loading="lazy">
            {:else}
              <div class="preview-empty">No Preview</div>
            {/if}
          </div>

          <div class="theme-content">
            <div class="theme-head">
              <h4>{t.name}</h4>
              <code>{t.id}</code>
            </div>

            {#if t.description}
              <p class="theme-description">{t.description}</p>
            {/if}

            <p class="price-line">
              <strong>{priceLabel(t)}</strong>
              <span>{t.seller || 'Community'}</span>
            </p>

            <div class="theme-meta">
              {#if t.active}
                <span class="badge badge--active">Aktiv</span>
              {:else if t.installed}
                <span class="badge badge--installed">Installiert</span>
              {:else}
                <span class="badge badge--available">Verfuegbar</span>
              {/if}
              <span class="source-pill">{t.source}</span>
            </div>

            <div class="theme-actions">
              {#if !t.installed}
                {#if t.requires_license && !t.has_license}
                  <input
                    class="license-input"
                    type="text"
                    placeholder="Lizenzschluessel"
                    value={licenseByTheme[t.id] || ''}
                    oninput={(event) => {
                      const value = event.currentTarget?.value || '';
                      licenseByTheme = { ...licenseByTheme, [t.id]: value };
                    }}
                  >
                {/if}

                <button class="btn btn--primary" disabled={!!busy[t.id]} onclick={() => installFromRegistry(t)}>
                  {busy[t.id] ? 'Installiere...' : 'Installieren'}
                </button>

                {#if t.checkout_url}
                  <a class="buy-link" href={t.checkout_url} target="_blank" rel="noreferrer">Kaufen</a>
                {/if}
              {:else}
                {#if !t.active}
                  <button class="btn" disabled={!!busy[t.id]} onclick={() => activateTheme(t.id)}>
                    {busy[t.id] ? 'Aktiviere...' : 'Aktivieren'}
                  </button>
                {/if}

                {#if t.source === 'site' && !t.active}
                  <button class="btn btn--danger" disabled={!!busy[t.id]} onclick={() => uninstallTheme(t.id)}>
                    Deinstallieren
                  </button>
                {/if}
              {/if}
            </div>
          </div>
        </article>
      {/each}
    </div>
  </div>

  <div class="section-card">
    <div class="section-header">
      <h3>Von Source installieren</h3>
    </div>
    <form class="install-form" onsubmit={installFromSource}>
      <input bind:value={installSource} placeholder="/pfad/zu/theme oder https://...zip" required>
      <button type="submit" class="btn btn--primary">Installieren</button>
    </form>
  </div>
</div>

<style>
  .themes-view {
    max-width: 1100px;
  }

  .page-header {
    margin-bottom: 1.5rem;
  }

  .page-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    letter-spacing: -0.02em;
  }

  .page-header p {
    margin-top: 0.35rem;
    color: var(--muted);
    font-size: 0.9rem;
  }

  .section-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    overflow: hidden;
    margin-bottom: 1rem;
  }

  .section-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--line);
  }

  .section-header h3 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
  }

  .catalog-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
    gap: 0.9rem;
    padding: 1rem;
  }

  .theme-card {
    border: 1px solid var(--line);
    border-radius: 12px;
    overflow: hidden;
    background: var(--bg);
    display: flex;
    flex-direction: column;
  }

  .theme-preview-wrap {
    height: 170px;
    background: #0f1c20;
    border-bottom: 1px solid var(--line);
  }

  .theme-preview {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .preview-empty {
    height: 100%;
    display: grid;
    place-items: center;
    color: var(--muted);
    font-size: 0.85rem;
  }

  .theme-content {
    padding: 0.9rem;
    display: grid;
    gap: 0.65rem;
  }

  .theme-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
  }

  .theme-head h4 {
    margin: 0;
    font-size: 0.98rem;
    font-weight: 650;
  }

  code {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.78rem;
    padding: 0.14rem 0.4rem;
    background: rgba(138, 163, 168, 0.15);
    border-radius: 6px;
  }

  .theme-description {
    margin: 0;
    font-size: 0.85rem;
    color: var(--muted);
    min-height: 2.4em;
  }

  .price-line {
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.82rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .price-line strong {
    color: var(--text);
    font-size: 0.9rem;
    letter-spacing: 0;
    text-transform: none;
  }

  .theme-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
  }

  .badge {
    display: inline-block;
    padding: 0.16rem 0.52rem;
    border-radius: 999px;
    font-size: 0.74rem;
    border: 1px solid transparent;
  }

  .badge--active {
    background: rgba(34, 197, 94, 0.12);
    border-color: rgba(34, 197, 94, 0.3);
    color: #39d87f;
  }

  .badge--installed {
    background: rgba(14, 165, 233, 0.12);
    border-color: rgba(14, 165, 233, 0.3);
    color: #38bdf8;
  }

  .badge--available {
    background: rgba(245, 158, 11, 0.12);
    border-color: rgba(245, 158, 11, 0.3);
    color: #fbbf24;
  }

  .source-pill {
    font-size: 0.74rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }

  .theme-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    align-items: center;
  }

  .license-input {
    width: 100%;
    padding: 0.45rem 0.6rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: #0a1518;
    color: var(--text);
    font: inherit;
    font-size: 0.85rem;
  }

  .btn {
    padding: 0.42rem 0.78rem;
    border: 1px solid var(--line);
    border-radius: 7px;
    background: transparent;
    color: var(--text);
    font: inherit;
    font-size: 0.8rem;
    cursor: pointer;
  }

  .btn:hover {
    border-color: var(--brand);
    color: var(--brand);
  }

  .btn--primary {
    border-color: transparent;
    background: var(--brand);
    color: #1d1d1d;
    font-weight: 600;
  }

  .btn--primary:hover {
    color: #1d1d1d;
    border-color: transparent;
    filter: brightness(1.05);
  }

  .btn--danger {
    border-color: rgba(248, 113, 113, 0.45);
    color: #fda4af;
  }

  .btn--danger:hover {
    border-color: #f87171;
    color: #fecdd3;
  }

  .btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .buy-link {
    font-size: 0.8rem;
    color: #fbbf24;
    text-decoration: none;
  }

  .buy-link:hover {
    text-decoration: underline;
  }

  .install-form {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.25rem;
  }

  .install-form input {
    flex: 1;
    padding: 0.55rem 0.75rem;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text);
    font: inherit;
    font-size: 0.9rem;
  }

  @media (max-width: 720px) {
    .catalog-grid {
      grid-template-columns: 1fr;
    }

    .install-form {
      flex-direction: column;
      align-items: stretch;
    }
  }
</style>
