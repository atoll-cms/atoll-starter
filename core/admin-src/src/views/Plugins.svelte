<script>
  import { plugins, pluginRegistry, addToast } from '../lib/stores.js';
  import { api } from '../lib/api.js';

  let installSource = $state('');
  let installEnable = $state(true);
  let busy = $state({});
  let licenseByPlugin = $state({});
  let buyerByPlugin = $state({});
  let updateAllBusy = $state(false);

  function setBusy(id, value) {
    busy = { ...busy, [id]: value };
  }

  function priceLabel(row) {
    const value = Number(row?.price_eur ?? 0);
    return Number.isFinite(value) && value > 0 ? `${value.toFixed(0)} EUR` : 'Kostenlos';
  }

  function needsLicense(row) {
    return !!row?.requires_license;
  }

  function isPaid(row) {
    const value = Number(row?.price_eur ?? 0);
    return Number.isFinite(value) && value > 0;
  }

  function hasStoredLicense(row) {
    return !!row?.has_license;
  }

  function hasUsableLicense(row) {
    if (!hasStoredLicense(row)) return false;
    return row?.license_valid !== false;
  }

  function licenseWarning(row) {
    if (row?.license_valid === false) {
      const reason = String(row?.license_reason || 'invalid');
      return `Gespeicherte Lizenz ist ungueltig (${reason}).`;
    }
    return '';
  }

  async function refreshPluginData() {
    const [installed, registry] = await Promise.all([
      api('/admin/api/plugins'),
      api('/admin/api/plugin-registry')
    ]);
    plugins.set(installed.plugins || []);
    pluginRegistry.set(registry.registry || []);
  }

  async function togglePlugin(id, active) {
    if (busy[`toggle-${id}`]) return;
    setBusy(`toggle-${id}`, true);
    try {
      await api('/admin/api/plugins/toggle', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, active })
      });
      await refreshPluginData();
      addToast(`Plugin ${active ? 'aktiviert' : 'deaktiviert'}.`, 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      setBusy(`toggle-${id}`, false);
    }
  }

  async function updatePlugin(id) {
    if (!id || busy[`update-${id}`]) return;
    setBusy(`update-${id}`, true);
    try {
      await api('/admin/api/plugins/update', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
      });
      await refreshPluginData();
      addToast('Plugin aktualisiert.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      setBusy(`update-${id}`, false);
    }
  }

  async function updateAllPlugins() {
    if (updateAllBusy) return;
    updateAllBusy = true;
    try {
      const result = await api('/admin/api/plugins/update-all', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
      });

      const updatedCount = Array.isArray(result?.updated) ? result.updated.length : 0;
      const errorCount = Array.isArray(result?.errors) ? result.errors.length : 0;
      await refreshPluginData();
      if (errorCount > 0) {
        addToast(`${updatedCount} Plugin(s) aktualisiert, ${errorCount} Fehler.`, 'error');
      } else {
        addToast(`${updatedCount} Plugin(s) aktualisiert.`, 'success');
      }
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      updateAllBusy = false;
    }
  }

  async function uninstallPlugin(id) {
    if (!id || busy[`uninstall-${id}`]) return;
    if (!window.confirm('Plugin wirklich deinstallieren?')) return;

    setBusy(`uninstall-${id}`, true);
    try {
      await api('/admin/api/plugins/uninstall', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
      });
      await refreshPluginData();
      addToast('Plugin deinstalliert.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      setBusy(`uninstall-${id}`, false);
    }
  }

  async function installFromRegistry(row) {
    const id = row?.id;
    if (!id || busy[`install-${id}`]) return;

    const licenseKey = String(licenseByPlugin[id] || '').trim();
    if (needsLicense(row) && !hasUsableLicense(row) && licenseKey === '') {
      addToast('Dieses Plugin benoetigt einen Lizenzschluessel.', 'error');
      return;
    }

    setBusy(`install-${id}`, true);
    try {
      await api('/admin/api/plugins/install', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id,
          enable: true,
          license_key: licenseKey !== '' ? licenseKey : undefined
        })
      });
      licenseByPlugin = { ...licenseByPlugin, [id]: '' };
      await refreshPluginData();
      addToast('Plugin installiert.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      setBusy(`install-${id}`, false);
    }
  }

  async function purchasePluginLicense(row) {
    const id = row?.id;
    if (!id || busy[`purchase-${id}`]) return;

    const buyer = buyerByPlugin[id] || {};
    const buyerEmail = String(buyer?.email || '').trim();
    const buyerName = String(buyer?.name || '').trim();
    if (buyerEmail === '') {
      addToast('Bitte eine Buyer-E-Mail eingeben.', 'error');
      return;
    }

    setBusy(`purchase-${id}`, true);
    try {
      const result = await api('/admin/api/marketplace/purchase', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          kind: 'plugin',
          id,
          buyer_email: buyerEmail,
          buyer_name: buyerName
        })
      });

      const key = String(result?.purchase?.license_key || '').trim();
      if (key !== '') {
        licenseByPlugin = { ...licenseByPlugin, [id]: key };
      }
      await refreshPluginData();
      addToast('Lizenz gekauft und gespeichert.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      setBusy(`purchase-${id}`, false);
    }
  }

  async function installFromSource(event) {
    event.preventDefault();
    const source = installSource.trim();
    if (source === '') return;

    try {
      await api('/admin/api/plugins/install', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ source, enable: installEnable })
      });
      installSource = '';
      await refreshPluginData();
      addToast('Plugin installiert.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }
</script>

<div class="plugins-view">
  <div class="page-header">
    <h1>Plugins</h1>
    <p>Marketplace fuer freie und kostenpflichtige Plugins.</p>
  </div>

  <div class="section-card">
    <div class="section-header">
      <h3>Installierte Plugins</h3>
      <button class="action-btn" onclick={updateAllPlugins} disabled={updateAllBusy}>
        {updateAllBusy ? 'Aktualisiere...' : 'Alle updaten'}
      </button>
    </div>
    {#if $plugins.length > 0}
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Name</th><th>Version</th><th>Status</th><th>Aktionen</th></tr>
          </thead>
          <tbody>
            {#each $plugins as p}
              <tr>
                <td>
                  <div class="plugin-name">{p.name}</div>
                  {#if p.description}<div class="plugin-desc">{p.description}</div>{/if}
                </td>
                <td>
                  <code>{p.version}</code>
                  {#if p.registry_version}
                    <div class="plugin-version-meta">Registry: {p.registry_version}</div>
                  {/if}
                </td>
                <td>
                  <span class="badge" class:badge--active={p.active} class:badge--inactive={!p.active}>
                    {p.active ? 'Aktiv' : 'Inaktiv'}
                  </span>
                  {#if p.update_available}
                    <span class="badge badge--update">Update verfuegbar</span>
                  {/if}
                </td>
                <td>
                  <div class="action-row">
                    <button class="action-btn" disabled={!!busy[`toggle-${p.id}`]} onclick={() => togglePlugin(p.id, !p.active)}>
                      {p.active ? 'Deaktivieren' : 'Aktivieren'}
                    </button>
                    <button
                      class="action-btn"
                      disabled={!!busy[`update-${p.id}`] || !p.update_supported}
                      onclick={() => updatePlugin(p.id)}
                    >
                      {busy[`update-${p.id}`] ? 'Update...' : 'Updaten'}
                    </button>
                    <button
                      class="action-btn action-btn--danger"
                      disabled={!!busy[`uninstall-${p.id}`]}
                      onclick={() => uninstallPlugin(p.id)}
                    >
                      {busy[`uninstall-${p.id}`] ? 'Entferne...' : 'Deinstallieren'}
                    </button>
                  </div>
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {:else}
      <div class="empty-msg">Keine Plugins installiert.</div>
    {/if}
  </div>

  {#if $pluginRegistry.length > 0}
    <div class="section-card">
      <div class="section-header">
        <h3>Plugin Marketplace</h3>
      </div>
      <div class="registry-grid">
        {#each $pluginRegistry as p}
          <article class="registry-card">
            <div class="card-head">
              <strong>{p.name || p.id}</strong>
              <code>{p.id}</code>
            </div>

            {#if p.description}<p class="registry-desc">{p.description}</p>{/if}

            <p class="meta-line">
              <span>{priceLabel(p)}</span>
              <span>{p.seller || 'Community'}</span>
            </p>

            {#if p.installed}
              <div class="badge badge--active">Installiert</div>
            {/if}

            {#if !p.installed}
              {#if needsLicense(p) && licenseWarning(p) !== ''}
                <p class="license-warning">{licenseWarning(p)}</p>
              {/if}

              {#if needsLicense(p) && !hasUsableLicense(p)}
                <div class="license-row">
                  <input
                    type="text"
                    placeholder="Lizenzschluessel"
                    value={licenseByPlugin[p.id] || ''}
                    oninput={(event) => {
                      const value = event.currentTarget?.value || '';
                      licenseByPlugin = { ...licenseByPlugin, [p.id]: value };
                    }}
                  >
                </div>
              {/if}

              {#if isPaid(p) && (!needsLicense(p) || !hasUsableLicense(p))}
                <div class="buyer-grid">
                  <input
                    type="text"
                    placeholder="Buyer name (optional)"
                    value={buyerByPlugin[p.id]?.name || ''}
                    oninput={(event) => {
                      const value = event.currentTarget?.value || '';
                      buyerByPlugin = {
                        ...buyerByPlugin,
                        [p.id]: { ...(buyerByPlugin[p.id] || {}), name: value }
                      };
                    }}
                  >
                  <input
                    type="email"
                    placeholder="Buyer email"
                    value={buyerByPlugin[p.id]?.email || ''}
                    oninput={(event) => {
                      const value = event.currentTarget?.value || '';
                      buyerByPlugin = {
                        ...buyerByPlugin,
                        [p.id]: { ...(buyerByPlugin[p.id] || {}), email: value }
                      };
                    }}
                  >
                </div>
              {/if}

              <div class="card-actions">
                {#if isPaid(p) && (!needsLicense(p) || !hasUsableLicense(p))}
                  <button class="buy-btn" disabled={!!busy[`purchase-${p.id}`]} onclick={() => purchasePluginLicense(p)}>
                    {busy[`purchase-${p.id}`] ? 'Kaufe...' : 'Kaufen & Lizenz'}
                  </button>
                {/if}
                <button class="install-btn" disabled={!!busy[`install-${p.id}`]} onclick={() => installFromRegistry(p)}>
                  {busy[`install-${p.id}`] ? 'Installiere...' : 'Installieren'}
                </button>
                {#if p.checkout_url}
                  <a class="buy-link" href={p.checkout_url} target="_blank" rel="noreferrer">Kaufen</a>
                {/if}
              </div>
            {/if}
          </article>
        {/each}
      </div>
    </div>
  {/if}

  <div class="section-card">
    <div class="section-header">
      <h3>Von Source installieren</h3>
    </div>
    <form class="install-form" onsubmit={installFromSource}>
      <input bind:value={installSource} placeholder="/pfad/zu/plugin oder https://...zip" required>
      <label class="checkbox-label">
        <input type="checkbox" bind:checked={installEnable}> Aktivieren
      </label>
      <button type="submit" class="submit-btn">Installieren</button>
    </form>
  </div>
</div>

<style>
  .plugins-view { max-width: 1020px; }

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
    margin-top: 0.3rem;
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
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
  }

  .section-header h3 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
  }

  .table-wrap { overflow-x: auto; }

  table {
    width: 100%;
    border-collapse: collapse;
  }

  th, td {
    padding: 0.75rem 1.25rem;
    text-align: left;
    font-size: 0.9rem;
  }

  th {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--muted);
    border-bottom: 1px solid var(--line);
  }

  td {
    border-bottom: 1px solid var(--line);
  }

  tr:last-child td { border-bottom: none; }

  .plugin-name { font-weight: 500; }
  .plugin-desc { font-size: 0.8rem; color: var(--muted); margin-top: 0.15rem; }
  .plugin-version-meta { font-size: 0.7rem; color: var(--muted); margin-top: 0.25rem; }

  code {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.78rem;
    padding: 0.1rem 0.4rem;
    background: rgba(138, 163, 168, 0.15);
    border-radius: 4px;
  }

  .badge {
    display: inline-block;
    padding: 0.16rem 0.55rem;
    border-radius: 999px;
    font-size: 0.74rem;
    font-weight: 600;
  }

  .badge--active { background: rgba(34, 197, 94, 0.12); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.28); }
  .badge--inactive { background: rgba(107, 114, 128, 0.1); color: #9ca3af; border: 1px solid rgba(107, 114, 128, 0.25); }
  .badge--update { background: rgba(245, 158, 11, 0.12); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); margin-left: 0.35rem; }

  .action-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
  }

  .action-btn {
    padding: 0.35rem 0.75rem;
    background: transparent;
    border: 1px solid var(--line);
    border-radius: 6px;
    color: var(--text);
    font: inherit;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.15s;
  }

  .action-btn:hover {
    border-color: var(--brand);
    color: var(--brand);
  }

  .action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  .action-btn--danger {
    border-color: rgba(239, 68, 68, 0.35);
    color: #fca5a5;
  }

  .action-btn--danger:hover:not(:disabled) {
    border-color: rgba(239, 68, 68, 0.6);
    color: #fecaca;
  }

  .registry-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 0.75rem;
    padding: 1rem;
  }

  .registry-card {
    padding: 1rem;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 10px;
    display: grid;
    gap: 0.6rem;
  }

  .card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.5rem;
  }

  .registry-desc {
    font-size: 0.82rem;
    color: var(--muted);
    margin: 0;
    min-height: 2.6em;
  }

  .meta-line {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
    font-size: 0.78rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .license-row input {
    width: 100%;
    padding: 0.45rem 0.6rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: #0a1518;
    color: var(--text);
    font: inherit;
    font-size: 0.85rem;
  }

  .buyer-grid {
    display: grid;
    gap: 0.5rem;
  }

  .buyer-grid input {
    width: 100%;
    padding: 0.45rem 0.6rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: #0a1518;
    color: var(--text);
    font: inherit;
    font-size: 0.85rem;
  }

  .license-warning {
    margin: 0;
    color: #fda4af;
    font-size: 0.78rem;
  }

  .card-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
  }

  .install-btn,
  .submit-btn,
  .buy-btn {
    padding: 0.42rem 0.75rem;
    background: var(--brand);
    border: none;
    border-radius: 7px;
    color: #1a1a1a;
    font: inherit;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
  }

  .install-btn:disabled {
    opacity: 0.65;
    cursor: not-allowed;
  }

  .buy-btn {
    background: #22c55e;
    color: #07210f;
  }

  .buy-btn:disabled {
    opacity: 0.65;
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
    padding: 0.5rem 0.75rem;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text);
    font: inherit;
    font-size: 0.9rem;
  }

  .checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.85rem;
    white-space: nowrap;
    cursor: pointer;
  }

  .checkbox-label input { width: auto; margin: 0; }

  .empty-msg {
    padding: 2rem;
    text-align: center;
    color: var(--muted);
    font-size: 0.9rem;
  }

  @media (max-width: 860px) {
    .install-form {
      flex-direction: column;
      align-items: stretch;
    }
  }
</style>
