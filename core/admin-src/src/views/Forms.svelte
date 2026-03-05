<script>
  import { submissions, addToast } from '../lib/stores.js';
  import { api } from '../lib/api.js';

  let loadingSubmissions = $state(false);

  async function loadSubmissions() {
    loadingSubmissions = true;
    try {
      const data = await api('/admin/api/forms/submissions?name=contact');
      submissions.set(data.submissions || []);
      addToast(`${(data.submissions || []).length} Submissions geladen.`, 'info');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loadingSubmissions = false;
    }
  }
</script>

<div class="forms-view">
  <div class="page-header">
    <h1>Forms</h1>
    <button class="load-btn" onclick={loadSubmissions} disabled={loadingSubmissions}>
      {loadingSubmissions ? 'Laden...' : 'Kontakt-Submissions laden'}
    </button>
  </div>

  {#if $submissions.length > 0}
    <div class="submissions-list">
      {#each $submissions as row}
        <div class="submission-card">
          <div class="submission-time">{row.timestamp || ''}</div>
          <pre class="submission-payload">{JSON.stringify(row.payload || {}, null, 2)}</pre>
        </div>
      {/each}
    </div>
  {:else}
    <div class="empty-state">
      <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
      <p>Klicke auf "Laden" um Submissions anzuzeigen.</p>
    </div>
  {/if}
</div>

<style>
  .forms-view {
    max-width: 900px;
  }

  .page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
  }

  .page-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    letter-spacing: -0.02em;
  }

  .load-btn {
    padding: 0.5rem 1rem;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text);
    font: inherit;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
  }

  .load-btn:hover:not(:disabled) {
    border-color: var(--brand);
    background: var(--surface-2);
  }

  .submissions-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
  }

  .submission-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 1rem;
  }

  .submission-time {
    font-size: 0.8rem;
    color: var(--muted);
    font-weight: 500;
    margin-bottom: 0.5rem;
  }

  .submission-payload {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.8rem;
    line-height: 1.5;
    color: var(--text);
    background: var(--bg);
    padding: 0.75rem;
    border-radius: 8px;
    overflow-x: auto;
    margin: 0;
  }

  .empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4rem 2rem;
    color: var(--muted);
    gap: 1rem;
  }

  .empty-state svg { opacity: 0.3; }
  .empty-state p { font-size: 0.9rem; }
</style>
