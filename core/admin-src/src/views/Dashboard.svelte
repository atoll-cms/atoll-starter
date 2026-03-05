<script>
  import { collections, plugins, themes, security, dashboardWidgets, currentView } from '../lib/stores.js';

  function navigate(view) {
    currentView.set(view);
  }
</script>

<div class="dashboard">
  <div class="page-header">
    <h1>Dashboard</h1>
    <p class="subtitle">Willkommen zurueck. Hier ist eine Uebersicht deines CMS.</p>
  </div>

  <div class="stats-grid">
    <button class="stat-card" onclick={() => navigate('content')}>
      <div class="stat-icon" style="--accent: #3b82f6; --accent-bg: rgba(59, 130, 246, 0.1);">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      </div>
      <div class="stat-value">{$collections.length}</div>
      <div class="stat-label">Collections</div>
    </button>

    <button class="stat-card" onclick={() => navigate('plugins')}>
      <div class="stat-icon" style="--accent: #8b5cf6; --accent-bg: rgba(139, 92, 246, 0.1);">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 14v6m-3-3h6M6 10h2a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2zm10 0h2a2 2 0 002-2V6a2 2 0 00-2-2h-2a2 2 0 00-2 2v2a2 2 0 002 2zM6 20h2a2 2 0 002-2v-2a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2z"/></svg>
      </div>
      <div class="stat-value">{$plugins.length}</div>
      <div class="stat-label">Plugins</div>
    </button>

    <button class="stat-card" onclick={() => navigate('themes')}>
      <div class="stat-icon" style="--accent: #10b981; --accent-bg: rgba(16, 185, 129, 0.1);">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
      </div>
      <div class="stat-value">{$themes.length}</div>
      <div class="stat-label">Themes</div>
    </button>

    <button class="stat-card" onclick={() => navigate('security')}>
      <div class="stat-icon" style="--accent: #f59e0b; --accent-bg: rgba(245, 158, 11, 0.1);">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
      </div>
      <div class="stat-value">{$security.auditEntries.length}</div>
      <div class="stat-label">Audit Events</div>
    </button>
  </div>

  <div class="widgets-grid">
    <div class="widget">
      <div class="widget-header">
        <h3>Schnellaktionen</h3>
      </div>
      <div class="quick-actions">
        <button class="action-btn" onclick={() => navigate('content')}>
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Neuer Eintrag
        </button>
        <button class="action-btn" onclick={() => navigate('media')}>
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Media Upload
        </button>
        <button class="action-btn" onclick={() => navigate('settings')}>
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
          Backup erstellen
        </button>
      </div>
    </div>

    <div class="widget">
      <div class="widget-header">
        <h3>System</h3>
      </div>
      <div class="system-info">
        <div class="info-row">
          <span class="info-label">2FA Status</span>
          <span class="badge" class:badge--active={$security.twofaEnabled} class:badge--inactive={!$security.twofaEnabled}>
            {$security.twofaEnabled ? 'Aktiv' : 'Inaktiv'}
          </span>
        </div>
        <div class="info-row">
          <span class="info-label">Updates</span>
          <code>php bin/atoll core:check</code>
        </div>
      </div>
    </div>
  </div>

  {#if $dashboardWidgets.length > 0}
    <div class="widget plugin-widget-block">
      <div class="widget-header">
        <h3>Plugin Widgets</h3>
      </div>
      <div class="plugin-widget-grid">
        {#each $dashboardWidgets as widget}
          <article class="plugin-widget-card">
            <h4>{widget.title}</h4>
            {#if widget.value}
              <p class="plugin-widget-value">{widget.value}</p>
            {/if}
            {#if widget.text}
              <p class="plugin-widget-text">{widget.text}</p>
            {/if}
          </article>
        {/each}
      </div>
    </div>
  {/if}
</div>

<style>
  .dashboard {
    max-width: 1100px;
  }

  .page-header {
    margin-bottom: 2rem;
  }

  .page-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 0.25rem;
    letter-spacing: -0.02em;
  }

  .subtitle {
    color: var(--muted);
    margin: 0;
    font-size: 0.9rem;
  }

  .stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
  }

  .stat-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 1.25rem;
    text-align: left;
    cursor: pointer;
    font: inherit;
    color: var(--text);
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
  }

  .stat-card:hover {
    border-color: var(--brand);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
  }

  .stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: var(--accent-bg);
    color: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.75rem;
  }

  .stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 0.25rem;
  }

  .stat-label {
    font-size: 0.8rem;
    color: var(--muted);
    font-weight: 500;
  }

  .widgets-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
  }

  .widget {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    overflow: hidden;
  }

  .plugin-widget-block {
    margin-top: 1rem;
  }

  .plugin-widget-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.75rem;
    padding: 1rem 1.25rem 1.25rem;
  }

  .plugin-widget-card {
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 0.8rem;
    background: var(--surface-2);
  }

  .plugin-widget-card h4 {
    margin: 0;
    font-size: 0.85rem;
    font-weight: 600;
  }

  .plugin-widget-value {
    margin-top: 0.4rem;
    font-size: 1.2rem;
    font-weight: 700;
    line-height: 1.1;
  }

  .plugin-widget-text {
    margin-top: 0.35rem;
    font-size: 0.8rem;
    color: var(--muted);
  }

  .widget-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--line);
  }

  .widget-header h3 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
  }

  .quick-actions {
    padding: 0.75rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
  }

  .action-btn {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    width: 100%;
    padding: 0.65rem 0.75rem;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 8px;
    color: var(--text);
    font: inherit;
    font-size: 0.9rem;
    cursor: pointer;
    text-align: left;
    transition: all 0.15s;
  }

  .action-btn:hover {
    background: var(--surface-2);
    border-color: var(--line);
  }

  .system-info {
    padding: 1rem 1.25rem;
  }

  .info-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem 0;
  }

  .info-row + .info-row {
    border-top: 1px solid var(--line);
  }

  .info-label {
    font-size: 0.85rem;
    color: var(--muted);
  }

  .badge {
    display: inline-block;
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
  }

  .badge--active {
    background: rgba(34, 197, 94, 0.1);
    color: #22c55e;
    border: 1px solid rgba(34, 197, 94, 0.25);
  }

  .badge--inactive {
    background: rgba(245, 158, 11, 0.1);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.25);
  }

  code {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.8rem;
    padding: 0.2rem 0.5rem;
    background: var(--bg);
    border-radius: 6px;
    color: var(--brand);
  }

  @media (max-width: 900px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .widgets-grid { grid-template-columns: 1fr; }
  }

  @media (max-width: 480px) {
    .stats-grid { grid-template-columns: 1fr; }
  }
</style>
