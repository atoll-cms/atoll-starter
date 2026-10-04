<script>
  import { onMount } from 'svelte';
  import { user, csrf, currentView, collections, plugins, pluginRegistry, themes, themeRegistry, adminMenu, dashboardWidgets, entries, currentCollection, currentEntry, currentEntryId, settings, security, loading } from './lib/stores.js';
  import { api } from './lib/api.js';

  import Sidebar from './components/Sidebar.svelte';
  import Toast from './components/Toast.svelte';
  import Modal from './components/Modal.svelte';
  import Login from './views/Login.svelte';
  import Dashboard from './views/Dashboard.svelte';
  import Content from './views/Content.svelte';
  import Media from './views/Media.svelte';
  import Forms from './views/Forms.svelte';
  import Redirects from './views/Redirects.svelte';
  import Seo from './views/Seo.svelte';
  import Plugins from './views/Plugins.svelte';
  import Themes from './views/Themes.svelte';
  import Security from './views/Security.svelte';
  import Settings from './views/Settings.svelte';
  import PluginFrame from './views/PluginFrame.svelte';

  let initialized = $state(false);

  async function hydrate() {
    try {
      const me = await api('/admin/api/me');
      user.set(me.user);
      csrf.set(me.csrf);
      security.update((s) => ({
        ...s,
        twofaEnabled: !!me?.security?.twofa_enabled,
        role: String(me?.security?.role || 'owner'),
        permissions: Array.isArray(me?.security?.permissions) ? me.security.permissions : []
      }));

      const [col, plug, plugReg, thm, thmReg, menuData, widgetData, sett, audit] = await Promise.all([
        api('/admin/api/collections'),
        api('/admin/api/plugins'),
        api('/admin/api/plugin-registry'),
        api('/admin/api/themes'),
        api('/admin/api/theme-registry'),
        api('/admin/api/menu'),
        api('/admin/api/dashboard/widgets'),
        api('/admin/api/settings'),
        api('/admin/api/security/audit?limit=100')
      ]);

      collections.set(col.collections);
      plugins.set(plug.plugins);
      pluginRegistry.set(plugReg.registry || []);
      themes.set(thm.themes || []);
      themeRegistry.set(thmReg.registry || []);
      adminMenu.set(menuData.items || []);
      dashboardWidgets.set(widgetData.widgets || []);
      settings.set(sett.settings);
      security.update((s) => ({ ...s, auditEntries: audit.entries || [] }));

      if (!col.collections.includes($currentCollection) && col.collections.length) {
        currentCollection.set(col.collections[0]);
      }

      const entryData = await api(`/admin/api/entries?collection=${encodeURIComponent($currentCollection)}`);
      entries.set(entryData.entries);
    } catch {
      user.set(null);
    }
  }

  // Re-hydrate when user logs in
  let prevUser = $state(null);
  $effect(() => {
    if ($user && !prevUser) {
      hydrate();
    }
    prevUser = $user;
  });

  // Load view-specific data
  $effect(() => {
    if ($user && $currentView === 'content') {
      api(`/admin/api/entries?collection=${encodeURIComponent($currentCollection)}`)
        .then((data) => entries.set(data.entries))
        .catch(() => {});
    }
    if ($user && $currentView === 'security') {
      api('/admin/api/security/audit?limit=100')
        .then((data) => security.update((s) => ({ ...s, auditEntries: data.entries || [] })))
        .catch(() => {});
    }
  });

  onMount(async () => {
    await hydrate();
    initialized = true;
  });
</script>

{#if !initialized}
  <div class="loading-screen">
    <div class="loading-spinner"></div>
  </div>
{:else if !$user}
  <Login />
{:else}
  <div class="app-layout">
    <Sidebar />
    <main class="app-content">
      {#if $currentView === 'dashboard'}
        <Dashboard />
      {:else if $currentView === 'content'}
        <Content />
      {:else if $currentView === 'media'}
        <Media />
      {:else if $currentView === 'forms'}
        <Forms />
      {:else if $currentView === 'redirects'}
        <Redirects />
      {:else if $currentView === 'seo'}
        <Seo />
      {:else if $currentView === 'plugins'}
        <Plugins />
      {:else if $currentView === 'themes'}
        <Themes />
      {:else if $currentView === 'security'}
        <Security />
      {:else if $currentView === 'settings'}
        <Settings />
      {:else}
        <PluginFrame />
      {/if}
    </main>
  </div>
{/if}

<Toast />
<Modal />

<style>
  :global(:root) {
    --bg: #0b1416;
    --surface: #111f23;
    --surface-2: #192c31;
    --line: #243a40;
    --text: #ecf2f0;
    --muted: #8aa3a8;
    --brand: #f59e0b;
  }

  :global(*) {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
  }

  :global(body) {
    font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
  }

  :global(a) {
    color: #fbbf24;
  }

  :global(:focus-visible) {
    outline: 2px solid var(--brand);
    outline-offset: 2px;
    border-radius: 4px;
  }

  :global(::selection) {
    background: rgba(245, 158, 11, 0.25);
    color: var(--text);
  }

  :global(::-webkit-scrollbar) {
    width: 8px;
    height: 8px;
  }

  :global(::-webkit-scrollbar-track) {
    background: transparent;
  }

  :global(::-webkit-scrollbar-thumb) {
    background: var(--line);
    border-radius: 4px;
  }

  :global(::-webkit-scrollbar-thumb:hover) {
    background: var(--muted);
  }

  .loading-screen {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .loading-spinner {
    width: 32px;
    height: 32px;
    border: 3px solid var(--line);
    border-top-color: var(--brand);
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
  }

  @keyframes spin {
    to { transform: rotate(360deg); }
  }

  .app-layout {
    display: flex;
    min-height: 100vh;
  }

  .app-content {
    flex: 1;
    padding: 1.5rem;
    min-width: 0;
    overflow-y: auto;
  }
</style>
