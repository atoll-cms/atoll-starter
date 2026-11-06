<script>
  import { currentView, user, sidebarCollapsed, adminMenu, security } from '../lib/stores.js';
  import { api } from '../lib/api.js';

  const coreMenuItems = [
    { id: 'dashboard', viewId: 'dashboard', label: 'Dashboard', icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
    { id: 'content', viewId: 'content', label: 'Content', icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z' },
    { id: 'media', viewId: 'media', label: 'Media', icon: 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z' },
    { id: 'forms', viewId: 'forms', label: 'Forms', icon: 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z' },
    { id: 'redirects', viewId: 'redirects', label: 'Redirects', icon: 'M17 7l-10 10m0 0h7m-7 0V10m10-3h-7m7 0v7' },
    { id: 'seo', viewId: 'seo', label: 'SEO', icon: 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z' },
    { id: 'plugins', viewId: 'plugins', label: 'Plugins', icon: 'M17 14v6m-3-3h6M6 10h2a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2zm10 0h2a2 2 0 002-2V6a2 2 0 00-2-2h-2a2 2 0 00-2 2v2a2 2 0 002 2zM6 20h2a2 2 0 002-2v-2a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2z' },
    { id: 'themes', viewId: 'themes', label: 'Themes', icon: 'M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01' },
    { id: 'security', viewId: 'security', label: 'Security', icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z' },
    { id: 'settings', viewId: 'settings', label: 'Settings', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z' }
  ];

  const pluginIconMap = {
    search: 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
    mail: 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
    plugin: 'M17 14v6m-3-3h6M6 10h2a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2zm10 0h2a2 2 0 002-2V6a2 2 0 00-2-2h-2a2 2 0 00-2 2v2a2 2 0 002 2zM6 20h2a2 2 0 002-2v-2a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2z'
  };

  function routeToViewId(route) {
    const value = String(route || '').trim();
    if (!value) return '';
    if (value.includes('#')) return value.split('#').pop().replace(/^\/+/, '');
    const path = value.replace(/^https?:\/\/[^/]+/i, '');
    const match = path.match(/^\/admin\/?(.+)?$/);
    if (!match) return '';
    return (match[1] || 'dashboard').replace(/^\/+/, '');
  }

  function resolveIconPath(icon) {
    const value = String(icon || '').trim();
    if (!value) return pluginIconMap.plugin;
    if (value.includes(' ') || value.includes('M')) return value;
    return pluginIconMap[value] || pluginIconMap.plugin;
  }

  function hasPermission(permission) {
    const required = String(permission || '').trim().toLowerCase();
    if (!required) return true;
    const grants = Array.isArray($security?.permissions) ? $security.permissions : [];
    if (grants.includes('*')) return true;
    for (const grantRaw of grants) {
      const grant = String(grantRaw || '').trim().toLowerCase();
      if (!grant) continue;
      if (grant === required) return true;
      if (grant.endsWith('.*')) {
        const prefix = grant.slice(0, -1);
        if (prefix && required.startsWith(prefix)) return true;
      }
    }
    return false;
  }

  const permissionByView = {
    dashboard: 'dashboard.read',
    content: 'content.read',
    media: 'media.read',
    forms: 'forms.read',
    redirects: 'redirects.read',
    seo: 'seo.read',
    plugins: 'plugins.read',
    themes: 'themes.read',
    security: 'security.read',
    settings: 'settings.read'
  };

  $: pluginMenuItems = ($adminMenu || [])
    .map((item) => {
      const viewId = routeToViewId(item?.route);
      if (!viewId) return null;
      return {
        id: String(item?.id || viewId),
        viewId,
        label: String(item?.label || viewId),
        icon: resolveIconPath(item?.icon)
      };
    })
    .filter(Boolean);

  $: menuItems = (() => {
    const rows = coreMenuItems.filter((item) => hasPermission(permissionByView[item.viewId] || ''));
    for (const item of pluginMenuItems) {
      if (!rows.some((row) => row.id === item.id || row.viewId === item.viewId)) {
        rows.push(item);
      }
    }
    return rows;
  })();

  function navigate(viewId) {
    currentView.set(viewId);
  }

  async function logout() {
    await api('/admin/api/auth/logout', { method: 'POST' });
    user.set(null);
  }

  function toggleSidebar() {
    sidebarCollapsed.update((v) => !v);
  }
</script>

<aside class="sidebar" class:collapsed={$sidebarCollapsed}>
  <div class="sidebar-header">
    {#if !$sidebarCollapsed}
      <span class="sidebar-brand">atoll</span>
    {/if}
    <button class="collapse-btn" onclick={toggleSidebar} title={$sidebarCollapsed ? 'Expand' : 'Collapse'}>
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        {#if $sidebarCollapsed}
          <polyline points="9 18 15 12 9 6"/>
        {:else}
          <polyline points="15 18 9 12 15 6"/>
        {/if}
      </svg>
    </button>
  </div>

  <nav class="sidebar-nav">
    {#each menuItems as item}
      <button
        class="nav-item"
        class:active={$currentView === item.viewId}
        onclick={() => navigate(item.viewId)}
        title={$sidebarCollapsed ? item.label : ''}
      >
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d={item.icon}/>
        </svg>
        {#if !$sidebarCollapsed}
          <span>{item.label}</span>
        {/if}
      </button>
    {/each}
  </nav>

  <div class="sidebar-footer">
    {#if !$sidebarCollapsed}
      <div class="user-info">
        <div class="user-avatar">{($user || 'A').charAt(0).toUpperCase()}</div>
        <span class="user-name">{$user}</span>
      </div>
    {/if}
    <button class="nav-item logout-btn" onclick={logout} title="Logout">
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>
      </svg>
      {#if !$sidebarCollapsed}
        <span>Logout</span>
      {/if}
    </button>
  </div>
</aside>

<style>
  .sidebar {
    width: 260px;
    min-height: 100vh;
    background: var(--surface);
    border-right: 1px solid var(--line);
    display: flex;
    flex-direction: column;
    transition: width 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    overflow: hidden;
  }

  .sidebar.collapsed {
    width: 64px;
  }

  .sidebar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.25rem 1rem;
    border-bottom: 1px solid var(--line);
    min-height: 64px;
  }

  .sidebar-brand {
    font-size: 1.25rem;
    font-weight: 700;
    letter-spacing: -0.03em;
    background: linear-gradient(135deg, var(--brand), #fbbf24);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }

  .collapse-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: transparent;
    border: none;
    border-radius: 8px;
    color: var(--muted);
    cursor: pointer;
    padding: 0;
    transition: background 0.15s, color 0.15s;
  }

  .collapse-btn:hover {
    background: var(--surface-2);
    color: var(--text);
  }

  .sidebar-nav {
    flex: 1;
    padding: 0.75rem 0.5rem;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .nav-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    width: 100%;
    padding: 0.6rem 0.75rem;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 10px;
    color: var(--muted);
    cursor: pointer;
    font: inherit;
    font-size: 0.9rem;
    font-weight: 500;
    text-align: left;
    white-space: nowrap;
    transition: all 0.15s ease;
  }

  .nav-item:hover {
    background: var(--surface-2);
    color: var(--text);
  }

  .nav-item.active {
    background: rgba(245, 158, 11, 0.1);
    border-color: rgba(245, 158, 11, 0.2);
    color: var(--brand);
  }

  .nav-item svg {
    flex-shrink: 0;
  }

  .sidebar-footer {
    padding: 0.75rem 0.5rem;
    border-top: 1px solid var(--line);
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
  }

  .user-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 0.75rem;
  }

  .user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--brand), #fbbf24);
    color: #1a1a1a;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.85rem;
    flex-shrink: 0;
  }

  .user-name {
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--text);
  }

  .logout-btn {
    color: var(--muted);
  }

  .logout-btn:hover {
    color: #ef4444;
    background: rgba(239, 68, 68, 0.08);
  }

  @media (max-width: 768px) {
    .sidebar {
      position: fixed;
      top: 0;
      left: 0;
      z-index: 50;
      transform: translateX(-100%);
      transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .sidebar.mobile-open {
      transform: translateX(0);
    }
  }
</style>
