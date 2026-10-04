<script>
  import { onMount } from 'svelte';
  import { addToast } from '../lib/stores.js';
  import { api } from '../lib/api.js';

  let rows = $state([]);
  let forms = $state([]);
  let loading = $state(false);
  let updatingStatus = $state({});

  let selectedForm = $state('all');
  let selectedStatus = $state('all');
  let searchQuery = $state('');
  let dateFrom = $state('');
  let dateTo = $state('');

  onMount(async () => {
    await loadSubmissions();
  });

  async function loadSubmissions() {
    loading = true;
    try {
      const params = new URLSearchParams();
      params.set('name', selectedForm || 'all');
      params.set('status', selectedStatus || 'all');
      if (searchQuery.trim() !== '') params.set('q', searchQuery.trim());
      if (dateFrom) params.set('date_from', dateFrom);
      if (dateTo) params.set('date_to', dateTo);
      params.set('limit', '1000');

      const data = await api(`/admin/api/forms/submissions?${params.toString()}`);
      rows = Array.isArray(data?.submissions) ? data.submissions : [];
      forms = Array.isArray(data?.forms) ? data.forms : [];

      if (selectedForm !== 'all' && selectedForm !== '' && !forms.includes(selectedForm)) {
        selectedForm = 'all';
      }
    } catch (err) {
      addToast(err?.message || 'Submissions konnten nicht geladen werden.', 'error');
    } finally {
      loading = false;
    }
  }

  async function setStatus(row, status) {
    if (!row?.id || !row?.form || !status) return;
    const key = `${row.form}:${row.id}`;
    if (updatingStatus[key]) return;

    updatingStatus = { ...updatingStatus, [key]: true };
    try {
      await api('/admin/api/forms/submissions/status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          form: row.form,
          id: row.id,
          status
        })
      });
      rows = rows.map((entry) => (
        entry.id === row.id && entry.form === row.form
          ? { ...entry, status }
          : entry
      ));
      addToast('Status aktualisiert.', 'success');
    } catch (err) {
      addToast(err?.message || 'Status konnte nicht gespeichert werden.', 'error');
    } finally {
      updatingStatus = { ...updatingStatus, [key]: false };
    }
  }

  function exportCsv() {
    const params = new URLSearchParams();
    params.set('name', selectedForm || 'all');
    params.set('status', selectedStatus || 'all');
    if (searchQuery.trim() !== '') params.set('q', searchQuery.trim());
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    params.set('limit', '5000');

    window.open(`/admin/api/forms/submissions/export?${params.toString()}`, '_blank', 'noopener');
  }

  function payloadPreview(payload) {
    if (!payload || typeof payload !== 'object') return '';
    const entries = Object.entries(payload).slice(0, 4);
    return entries.map(([k, v]) => `${k}: ${String(v)}`).join(' · ');
  }
</script>

<div class="forms-view">
  <div class="page-header">
    <h1>Forms Inbox</h1>
    <p>CRM-lite Workflow fuer eingehende Formulare.</p>
  </div>

  <div class="filters">
    <select bind:value={selectedForm}>
      <option value="all">Alle Formulare</option>
      {#each forms as name}
        <option value={name}>{name}</option>
      {/each}
    </select>

    <select bind:value={selectedStatus}>
      <option value="all">Alle Status</option>
      <option value="new">Neu</option>
      <option value="in-progress">In Bearbeitung</option>
      <option value="done">Erledigt</option>
    </select>

    <input type="date" bind:value={dateFrom}>
    <input type="date" bind:value={dateTo}>
    <input type="text" bind:value={searchQuery} placeholder="Suche (ID, Formular, Felder)">

    <button class="action-btn" onclick={loadSubmissions} disabled={loading}>
      {loading ? 'Lade...' : 'Filtern'}
    </button>
    <button class="action-btn" onclick={exportCsv}>CSV Export</button>
  </div>

  <div class="section-card">
    {#if rows.length === 0}
      <div class="empty-state">
        <p>Keine Submissions fuer die aktuelle Filterung.</p>
      </div>
    {:else}
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Zeit</th>
              <th>Formular</th>
              <th>Payload</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {#each rows as row}
              {@const rowKey = `${row.form}:${row.id}`}
              <tr>
                <td>
                  <div class="timestamp">{row.timestamp || ''}</div>
                  <code>{row.id}</code>
                </td>
                <td>{row.form}</td>
                <td>
                  <div class="payload-preview">{payloadPreview(row.payload)}</div>
                  <details>
                    <summary>Details</summary>
                    <pre>{JSON.stringify(row.payload || {}, null, 2)}</pre>
                  </details>
                </td>
                <td>
                  <select
                    value={row.status || 'new'}
                    onchange={(event) => setStatus(row, event.currentTarget?.value || 'new')}
                    disabled={!!updatingStatus[rowKey]}
                  >
                    <option value="new">Neu</option>
                    <option value="in-progress">In Bearbeitung</option>
                    <option value="done">Erledigt</option>
                  </select>
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  </div>
</div>

<style>
  .forms-view {
    max-width: 1200px;
  }

  .page-header {
    margin-bottom: 1rem;
  }

  .page-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    letter-spacing: -0.02em;
  }

  .page-header p {
    margin-top: 0.25rem;
    color: var(--muted);
    font-size: 0.9rem;
  }

  .filters {
    display: grid;
    grid-template-columns: 170px 170px 150px 150px 1fr auto auto;
    gap: 0.55rem;
    margin-bottom: 1rem;
  }

  .filters select,
  .filters input {
    width: 100%;
    padding: 0.45rem 0.55rem;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text);
    font: inherit;
    font-size: 0.85rem;
  }

  .action-btn {
    padding: 0.45rem 0.72rem;
    background: transparent;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text);
    font: inherit;
    font-size: 0.8rem;
    cursor: pointer;
  }

  .action-btn:hover {
    border-color: var(--brand);
    color: var(--brand);
  }

  .action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  .section-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    overflow: hidden;
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
    vertical-align: top;
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
    font-size: 0.86rem;
  }

  tr:last-child td {
    border-bottom: none;
  }

  td code {
    display: inline-block;
    margin-top: 0.25rem;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.72rem;
    padding: 0.1rem 0.35rem;
    background: rgba(148, 163, 184, 0.14);
    border-radius: 4px;
  }

  .timestamp {
    font-size: 0.8rem;
    color: var(--muted);
  }

  .payload-preview {
    font-size: 0.8rem;
    color: var(--muted);
    line-height: 1.45;
    margin-bottom: 0.35rem;
  }

  details summary {
    cursor: pointer;
    color: var(--brand);
    font-size: 0.78rem;
    user-select: none;
  }

  details pre {
    margin-top: 0.4rem;
    padding: 0.65rem;
    border-radius: 8px;
    background: var(--bg);
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.75rem;
    overflow-x: auto;
  }

  td select {
    width: 100%;
    padding: 0.45rem 0.5rem;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text);
    font: inherit;
    font-size: 0.8rem;
  }

  .empty-state {
    padding: 2.4rem 1rem;
    text-align: center;
    color: var(--muted);
  }

  @media (max-width: 1080px) {
    .filters {
      grid-template-columns: 1fr 1fr;
    }
  }
</style>
