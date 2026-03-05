<script>
  import { currentView } from '../lib/stores.js';
  import { api } from '../lib/api.js';

  let loading = $state(false);
  let frameUrl = $state('');
  let title = $state('Plugin');
  let pluginId = $state('');
  let error = $state('');

  async function loadPluginPage() {
    const view = String($currentView || '').trim();
    if (view === '') return;

    loading = true;
    error = '';

    try {
      const data = await api(`/admin/api/plugin-page?view=${encodeURIComponent(view)}`);
      title = data.title || 'Plugin';
      pluginId = data.plugin || '';
      frameUrl = data.url || '';
    } catch (err) {
      frameUrl = '';
      pluginId = '';
      error = err.message || 'Plugin-Seite konnte nicht geladen werden.';
    } finally {
      loading = false;
    }
  }

  $effect(() => {
    loadPluginPage();
  });
</script>

<section class="plugin-frame-view">
  <header class="page-header">
    <h1>{title}</h1>
    {#if pluginId !== ''}
      <p>Plugin: <code>{pluginId}</code></p>
    {/if}
  </header>

  {#if loading}
    <div class="state-card">Lade Plugin-Seite...</div>
  {:else if error}
    <div class="state-card state-card--error">{error}</div>
  {:else if frameUrl === ''}
    <div class="state-card">Keine Plugin-Seite gefunden.</div>
  {:else}
    <div class="frame-shell">
      <iframe title={title} src={frameUrl}></iframe>
    </div>
  {/if}
</section>

<style>
  .plugin-frame-view {
    max-width: 1180px;
  }

  .page-header {
    margin-bottom: 1rem;
  }

  .page-header h1 {
    font-size: 1.45rem;
    font-weight: 700;
    letter-spacing: -0.02em;
  }

  .page-header p {
    margin-top: 0.3rem;
    color: var(--muted);
    font-size: 0.9rem;
  }

  code {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.76rem;
    padding: 0.12rem 0.45rem;
    background: rgba(138, 163, 168, 0.16);
    border-radius: 6px;
  }

  .state-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 0.9rem 1rem;
    color: var(--muted);
  }

  .state-card--error {
    border-color: rgba(239, 68, 68, 0.45);
    color: #fda4af;
  }

  .frame-shell {
    border: 1px solid var(--line);
    border-radius: 12px;
    overflow: hidden;
    background: #0b0f13;
    min-height: 72vh;
  }

  iframe {
    width: 100%;
    min-height: 72vh;
    border: 0;
    background: #ffffff;
  }
</style>
