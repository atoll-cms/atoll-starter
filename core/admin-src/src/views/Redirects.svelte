<script>
  import { onMount } from 'svelte';
  import { addToast } from '../lib/stores.js';
  import { api } from '../lib/api.js';

  let redirects = $state([]);
  let saving = $state(false);
  let loading = $state(true);

  onMount(async () => {
    await loadRedirects();
  });

  async function loadRedirects() {
    loading = true;
    try {
      const data = await api('/admin/api/redirects');
      redirects = Array.isArray(data?.redirects) ? data.redirects : [];
    } catch (err) {
      addToast(err?.message || 'Redirects konnten nicht geladen werden.', 'error');
    } finally {
      loading = false;
    }
  }

  function addRule() {
    redirects = [
      ...redirects,
      {
        id: `new-${Date.now()}-${Math.floor(Math.random() * 10000)}`,
        from: '',
        to: '',
        status: 301,
        auto: false
      }
    ];
  }

  function removeRule(id) {
    redirects = redirects.filter((row) => row.id !== id);
  }

  function updateRule(id, patch) {
    redirects = redirects.map((row) => (row.id === id ? { ...row, ...patch } : row));
  }

  async function saveRules() {
    if (saving) return;
    saving = true;
    try {
      const data = await api('/admin/api/redirects/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ redirects })
      });
      redirects = Array.isArray(data?.redirects) ? data.redirects : [];
      addToast('Redirects gespeichert.', 'success');
    } catch (err) {
      addToast(err?.message || 'Speichern fehlgeschlagen.', 'error');
    } finally {
      saving = false;
    }
  }
</script>

<div class="redirects-view">
  <div class="page-header">
    <h1>Redirects</h1>
    <p>301/302 Redirects inkl. Wildcards (`*`) verwalten.</p>
  </div>

  <div class="section-card">
    <div class="section-header">
      <h3>Regeln</h3>
      <div class="header-actions">
        <button class="action-btn" type="button" onclick={loadRedirects} disabled={loading}>
          {loading ? 'Lade...' : 'Neu laden'}
        </button>
        <button class="action-btn" type="button" onclick={addRule}>Neue Regel</button>
        <button class="save-btn" type="button" onclick={saveRules} disabled={saving}>
          {saving ? 'Speichert...' : 'Speichern'}
        </button>
      </div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Von</th>
            <th>Nach</th>
            <th>Status</th>
            <th>Typ</th>
            <th>Aktion</th>
          </tr>
        </thead>
        <tbody>
          {#if redirects.length === 0}
            <tr>
              <td colspan="5" class="empty">Keine Redirects konfiguriert.</td>
            </tr>
          {:else}
            {#each redirects as row}
              <tr>
                <td>
                  <input
                    type="text"
                    value={row.from || ''}
                    placeholder="/alter-pfad oder /blog/*"
                    oninput={(event) => updateRule(row.id, { from: event.currentTarget?.value || '' })}
                  >
                </td>
                <td>
                  <input
                    type="text"
                    value={row.to || ''}
                    placeholder="/neuer-pfad oder /blog/$1"
                    oninput={(event) => updateRule(row.id, { to: event.currentTarget?.value || '' })}
                  >
                </td>
                <td>
                  <select
                    value={String(row.status || 301)}
                    onchange={(event) => updateRule(row.id, { status: Number(event.currentTarget?.value || '301') })}
                  >
                    <option value="301">301</option>
                    <option value="302">302</option>
                  </select>
                </td>
                <td>
                  {#if row.auto}
                    <span class="badge badge--auto">Auto</span>
                  {:else}
                    <span class="badge badge--manual">Manuell</span>
                  {/if}
                </td>
                <td>
                  <button class="remove-btn" type="button" onclick={() => removeRule(row.id)}>Entfernen</button>
                </td>
              </tr>
            {/each}
          {/if}
        </tbody>
      </table>
    </div>
  </div>
</div>

<style>
  .redirects-view { max-width: 1100px; }

  .page-header {
    margin-bottom: 1.2rem;
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
  }

  .section-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--line);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
  }

  .section-header h3 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
  }

  .header-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
  }

  .action-btn,
  .save-btn,
  .remove-btn {
    padding: 0.4rem 0.72rem;
    border-radius: 8px;
    font: inherit;
    font-size: 0.8rem;
    cursor: pointer;
    border: 1px solid var(--line);
    background: transparent;
    color: var(--text);
  }

  .action-btn:hover,
  .remove-btn:hover {
    border-color: var(--brand);
    color: var(--brand);
  }

  .save-btn {
    background: var(--brand);
    color: #1a1a1a;
    border-color: transparent;
    font-weight: 600;
  }

  .save-btn:disabled,
  .action-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .table-wrap {
    overflow-x: auto;
  }

  table {
    width: 100%;
    border-collapse: collapse;
  }

  th, td {
    padding: 0.7rem 1rem;
    text-align: left;
  }

  th {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--muted);
    border-bottom: 1px solid var(--line);
  }

  td {
    border-bottom: 1px solid var(--line);
    vertical-align: middle;
  }

  tr:last-child td {
    border-bottom: none;
  }

  td input,
  td select {
    width: 100%;
    padding: 0.45rem 0.55rem;
    border: 1px solid var(--line);
    border-radius: 7px;
    background: var(--bg);
    color: var(--text);
    font: inherit;
    font-size: 0.85rem;
  }

  .badge {
    display: inline-block;
    padding: 0.14rem 0.52rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 600;
  }

  .badge--auto {
    background: rgba(14, 165, 233, 0.12);
    border: 1px solid rgba(14, 165, 233, 0.28);
    color: #38bdf8;
  }

  .badge--manual {
    background: rgba(148, 163, 184, 0.12);
    border: 1px solid rgba(148, 163, 184, 0.28);
    color: #cbd5e1;
  }

  .empty {
    text-align: center;
    color: var(--muted);
    padding: 1.6rem 1rem;
  }

  @media (max-width: 860px) {
    .section-header {
      flex-direction: column;
      align-items: stretch;
    }

    .header-actions {
      flex-wrap: wrap;
    }
  }
</style>
