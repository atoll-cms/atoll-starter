<script>
  import { security, addToast, confirm } from '../lib/stores.js';
  import { api } from '../lib/api.js';

  let twofaSecret = $state('');
  let twofaCode = $state('');
  let generatedSecret = $state('');
  let generatedUri = $state('');

  async function loadAudit() {
    const result = await api('/admin/api/security/audit?limit=100');
    security.update((s) => ({ ...s, auditEntries: result.entries || [] }));
  }

  async function generateSecret() {
    try {
      const result = await api('/admin/api/security/2fa/setup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
      });
      generatedSecret = result.secret || '';
      generatedUri = result.otpauth || '';
      twofaSecret = generatedSecret;
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function enableTwoFa(event) {
    event.preventDefault();
    try {
      await api('/admin/api/security/2fa/setup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ secret: twofaSecret, code: twofaCode })
      });
      security.update((s) => ({ ...s, twofaEnabled: true }));
      generatedSecret = '';
      generatedUri = '';
      twofaSecret = '';
      twofaCode = '';
      addToast('2FA aktiviert.', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function disableTwoFa() {
    const confirmed = await confirm('2FA deaktivieren', 'Bist du sicher, dass du 2FA deaktivieren moechtest?');
    if (!confirmed) return;

    try {
      await api('/admin/api/security/2fa/disable', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
      });
      security.update((s) => ({ ...s, twofaEnabled: false, twofaSecret: '', twofaUri: '' }));
      addToast('2FA deaktiviert.', 'info');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }
</script>

<div class="security-view">
  <div class="page-header">
    <h1>Security</h1>
  </div>

  <div class="security-grid">
    <div class="section-card">
      <div class="section-header">
        <h3>Zwei-Faktor-Authentifizierung</h3>
        <span class="badge" class:badge--active={$security.twofaEnabled} class:badge--inactive={!$security.twofaEnabled}>
          {$security.twofaEnabled ? 'Aktiv' : 'Inaktiv'}
        </span>
      </div>
      <div class="section-body">
        {#if $security.twofaEnabled}
          <p class="info-text">2FA ist aktiviert. Dein Account ist geschuetzt.</p>
          <button class="danger-btn" onclick={disableTwoFa}>2FA deaktivieren</button>
        {:else}
          <div class="twofa-setup">
            <button class="action-btn" onclick={generateSecret}>Secret erzeugen</button>

            {#if generatedSecret}
              <div class="secret-display">
                <span class="field-label">Secret</span>
                <code class="secret-code">{generatedSecret}</code>
                {#if generatedUri}
                  <p class="secret-uri">{generatedUri}</p>
                {/if}
              </div>
            {/if}

            <form class="twofa-form" onsubmit={enableTwoFa}>
              <input bind:value={twofaSecret} placeholder="Secret" required>
              <input bind:value={twofaCode} placeholder="6-stelliger Code" required inputmode="numeric">
              <button type="submit" class="submit-btn">2FA aktivieren</button>
            </form>
          </div>
        {/if}
      </div>
    </div>

    <div class="section-card">
      <div class="section-header">
        <h3>Audit Log</h3>
        <button class="refresh-btn" onclick={loadAudit} aria-label="Audit Log neu laden">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        </button>
      </div>
      <div class="audit-list">
        {#each ($security.auditEntries || []).slice(0, 30) as entry}
          <div class="audit-item">
            <div class="audit-event">{entry.event || ''}</div>
            <div class="audit-meta">{entry.timestamp || ''} &middot; {entry.user || '-'}</div>
          </div>
        {:else}
          <div class="empty-msg">Keine Audit-Eintraege.</div>
        {/each}
      </div>
    </div>
  </div>
</div>

<style>
  .security-view { max-width: 1000px; }

  .page-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 1.5rem;
    letter-spacing: -0.02em;
  }

  .security-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    align-items: start;
  }

  .section-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    overflow: hidden;
  }

  .section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--line);
  }

  .section-header h3 { margin: 0; font-size: 0.95rem; font-weight: 600; }

  .section-body { padding: 1.25rem; }

  .badge {
    display: inline-block;
    padding: 0.15rem 0.55rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
  }

  .badge--active { background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.25); }
  .badge--inactive { background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.25); }

  .info-text {
    color: var(--muted);
    font-size: 0.9rem;
    margin: 0 0 1rem;
  }

  .twofa-setup {
    display: flex;
    flex-direction: column;
    gap: 1rem;
  }

  .action-btn {
    padding: 0.5rem 1rem;
    background: transparent;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text);
    font: inherit;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.15s;
  }

  .action-btn:hover { border-color: var(--brand); }

  .secret-display {
    padding: 0.75rem;
    background: var(--bg);
    border-radius: 8px;
  }

  .field-label {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--muted);
  }

  .secret-code {
    display: block;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.85rem;
    margin: 0.25rem 0;
    word-break: break-all;
  }

  .secret-uri {
    font-size: 0.75rem;
    color: var(--muted);
    word-break: break-all;
    margin: 0;
  }

  .twofa-form {
    display: flex;
    gap: 0.5rem;
  }

  .twofa-form input {
    flex: 1;
    padding: 0.5rem 0.75rem;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text);
    font: inherit;
    font-size: 0.9rem;
  }

  .submit-btn {
    padding: 0.5rem 1rem;
    background: var(--brand);
    border: none;
    border-radius: 8px;
    color: #1a1a1a;
    font: inherit;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
  }

  .danger-btn {
    padding: 0.5rem 1rem;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.25);
    border-radius: 8px;
    color: #ef4444;
    font: inherit;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
  }

  .danger-btn:hover { background: rgba(239, 68, 68, 0.15); }

  .refresh-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: transparent;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--muted);
    cursor: pointer;
    padding: 0;
    transition: all 0.15s;
  }

  .refresh-btn:hover { border-color: var(--brand); color: var(--text); }

  .audit-list {
    max-height: 400px;
    overflow-y: auto;
    padding: 0.5rem;
  }

  .audit-item {
    padding: 0.6rem 0.75rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--bg);
    margin-bottom: 0.4rem;
  }

  .audit-event {
    font-weight: 500;
    font-size: 0.9rem;
  }

  .audit-meta {
    font-size: 0.75rem;
    color: var(--muted);
    margin-top: 0.15rem;
  }

  .empty-msg {
    padding: 2rem;
    text-align: center;
    color: var(--muted);
    font-size: 0.9rem;
  }

  @media (max-width: 768px) {
    .security-grid { grid-template-columns: 1fr; }
    .twofa-form { flex-direction: column; }
  }
</style>
