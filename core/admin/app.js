const state = {
  csrf: window.__ATOLL_CSRF__ || '',
  user: null,
  view: 'dashboard',
  collections: [],
  entries: [],
  currentCollection: 'pages',
  currentEntryId: 'index',
  currentEntry: null,
  plugins: [],
  pluginRegistry: [],
  themes: [],
  themeRegistry: [],
  submissions: [],
  settings: {},
  security: {
    twofaEnabled: false,
    twofaSecret: '',
    twofaUri: '',
    auditEntries: []
  }
};

const app = document.getElementById('app');

const h = (strings, ...values) => strings.map((s, i) => s + (values[i] ?? '')).join('');

const api = async (url, options = {}) => {
  const headers = {
    ...(options.headers || {}),
    'X-CSRF-Token': state.csrf
  };

  const response = await fetch(url, {
    credentials: 'same-origin',
    ...options,
    headers
  });

  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const message = data.error || `Request failed (${response.status})`;
    const details = data.fields ? `\n${JSON.stringify(data.fields)}` : '';
    throw new Error(message + details);
  }
  return data;
};

const loginView = () => h`
  <main class="content">
    <section class="card" style="max-width:460px;margin:5rem auto;">
      <h1>atoll-cms Login</h1>
      <p class="muted">Standard: admin / admin123</p>
      <form id="login-form">
        <label>Benutzername <input name="username" required value="admin"></label>
        <label>Passwort <input name="password" type="password" required value="admin123"></label>
        <label>2FA Code (optional) <input name="otp" inputmode="numeric" pattern="[0-9]{6}" placeholder="123456"></label>
        <button class="primary" type="submit">Einloggen</button>
      </form>
      <p id="login-error" class="muted"></p>
    </section>
  </main>
`;

const menuButton = (view, label) => `<button class="${state.view === view ? 'active' : ''}" data-view="${view}">${label}</button>`;

const shell = () => h`
  <div class="layout">
    <aside class="sidebar">
      <div class="brand">atoll-cms Admin</div>
      <nav class="menu">
        ${menuButton('dashboard', 'Dashboard')}
        ${menuButton('content', 'Content')}
        ${menuButton('media', 'Media')}
        ${menuButton('forms', 'Forms')}
        ${menuButton('seo', 'SEO')}
        ${menuButton('plugins', 'Plugins')}
        ${menuButton('themes', 'Themes')}
        ${menuButton('security', 'Security')}
        ${menuButton('settings', 'Settings')}
      </nav>
      <hr>
      <p class="muted">Angemeldet als ${state.user}</p>
      <button id="logout-btn">Logout</button>
    </aside>
    <main class="content">${viewContent()}</main>
  </div>
`;

const viewContent = () => {
  if (state.view === 'dashboard') {
    return h`
      <section class="card">
        <h2>Dashboard</h2>
        <div class="grid-3">
          <div class="card stat"><strong>${state.collections.length}</strong><br>Collections</div>
          <div class="card stat"><strong>${state.plugins.length}</strong><br>Plugins</div>
          <div class="card stat"><strong>${state.themes.length}</strong><br>Themes</div>
        </div>
        <p class="muted">Core-Updates: nutze <code>php bin/atoll core:check</code> und <code>core:update:remote</code>.</p>
      </section>
    `;
  }

  if (state.view === 'content') {
    return h`
      <section class="card">
        <h2>Content</h2>
        <div class="grid-2">
          <div>
            <label>Collection
              <select id="collection-select">
                ${state.collections.map((c) => `<option value="${c}" ${state.currentCollection === c ? 'selected' : ''}>${c}</option>`).join('')}
              </select>
            </label>
            <table>
              <thead><tr><th>ID</th><th>Titel</th><th>Status</th></tr></thead>
              <tbody>
                ${state.entries.map((entry) => `
                  <tr data-entry-id="${entry.id}" class="entry-row ${state.currentEntryId === entry.id ? 'entry-active' : ''}">
                    <td>${entry.id}</td>
                    <td>${entry.title || '(ohne Titel)'}</td>
                    <td>${entry.draft ? '<span class="badge">Draft</span>' : '<span class="badge">Live</span>'}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>
          <div>
            ${state.currentEntry ? entryEditor() : '<p class="muted">Waehle links einen Eintrag.</p>'}
          </div>
        </div>
      </section>
    `;
  }

  if (state.view === 'media') {
    return h`
      <section class="card">
        <h2>Media</h2>
        <form id="media-upload-form">
          <label>Datei
            <input name="file" type="file" required>
          </label>
          <button class="primary" type="submit">Upload</button>
        </form>
        <p class="muted" id="media-result"></p>
      </section>
    `;
  }

  if (state.view === 'forms') {
    return h`
      <section class="card">
        <h2>Forms</h2>
        <button id="load-submissions">Kontakt-Submissions laden</button>
        <table>
          <thead><tr><th>Zeit</th><th>Payload</th></tr></thead>
          <tbody>
            ${state.submissions.map((row) => `<tr><td>${row.timestamp || ''}</td><td><pre>${escapeHtml(JSON.stringify(row.payload || {}, null, 2))}</pre></td></tr>`).join('')}
          </tbody>
        </table>
      </section>
    `;
  }

  if (state.view === 'seo') {
    return h`
      <section class="card">
        <h2>SEO</h2>
        <p>Sitemap: <a target="_blank" href="/sitemap.xml">/sitemap.xml</a></p>
        <p>Robots: <a target="_blank" href="/robots.txt">/robots.txt</a></p>
      </section>
    `;
  }

  if (state.view === 'plugins') {
    return h`
      <section class="card">
        <h2>Plugins</h2>
        <table>
          <thead><tr><th>Name</th><th>Version</th><th>Status</th><th>Aktion</th></tr></thead>
          <tbody>
            ${state.plugins.map((p) => `
              <tr>
                <td>${p.name}<br><span class="muted">${p.description || ''}</span></td>
                <td>${p.version}</td>
                <td>${p.active ? '<span class="badge">aktiv</span>' : '<span class="badge">inaktiv</span>'}</td>
                <td><button class="toggle-plugin" data-id="${p.id}" data-active="${p.active ? '0' : '1'}">${p.active ? 'Deaktivieren' : 'Aktivieren'}</button></td>
              </tr>
            `).join('')}
          </tbody>
        </table>

        <h3>Registry</h3>
        <div class="grid-2">
          ${state.pluginRegistry.map((p) => `
            <div class="card">
              <strong>${p.name}</strong>
              <p class="muted">${p.description || ''}</p>
              <button class="plugin-install-registry" data-id="${p.id}">Installieren</button>
            </div>
          `).join('')}
        </div>

        <h3>Von Source installieren</h3>
        <form id="plugin-install-form" class="inline-form">
          <input name="source" placeholder="/pfad/zu/plugin oder https://...zip" required>
          <label class="checkbox"><input type="checkbox" name="enable" checked> Aktivieren</label>
          <button class="primary" type="submit">Installieren</button>
        </form>
      </section>
    `;
  }

  if (state.view === 'themes') {
    return h`
      <section class="card">
        <h2>Themes</h2>
        <table>
          <thead><tr><th>ID</th><th>Quelle</th><th>Status</th><th>Aktion</th></tr></thead>
          <tbody>
            ${state.themes.map((t) => `
              <tr>
                <td>${t.id}</td>
                <td>${t.source}</td>
                <td>${t.active ? '<span class="badge">aktiv</span>' : ''}</td>
                <td>${t.active ? '' : `<button class="theme-activate" data-id="${t.id}">Aktivieren</button>`}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>

        <h3>Theme Registry</h3>
        <div class="grid-2">
          ${state.themeRegistry.map((t) => `
            <div class="card">
              <strong>${t.name}</strong>
              <p class="muted">${t.description || ''}</p>
              <button class="theme-install-registry" data-id="${t.id}">Installieren</button>
            </div>
          `).join('')}
        </div>

        <h3>Von Source installieren</h3>
        <form id="theme-install-form" class="inline-form">
          <input name="source" placeholder="/pfad/zu/theme oder https://...zip" required>
          <button class="primary" type="submit">Installieren</button>
        </form>
      </section>
    `;
  }

  if (state.view === 'security') {
    return h`
      <section class="card">
        <h2>Security</h2>
        <div class="grid-2">
          <div class="card">
            <h3>2FA</h3>
            <p>Status: ${state.security.twofaEnabled ? '<span class="badge">aktiv</span>' : '<span class="badge">inaktiv</span>'}</p>
            ${state.security.twofaEnabled ? '<button id="twofa-disable">2FA deaktivieren</button>' : `
              <button id="twofa-generate">Secret erzeugen</button>
              ${state.security.twofaSecret ? `<p><code>${state.security.twofaSecret}</code></p><p class="muted">${escapeHtml(state.security.twofaUri)}</p>` : ''}
              <form id="twofa-enable-form" class="inline-form">
                <input name="secret" placeholder="Secret" value="${state.security.twofaSecret || ''}" required>
                <input name="code" placeholder="6-stelliger Code" required>
                <button class="primary" type="submit">2FA aktivieren</button>
              </form>
            `}
          </div>
          <div class="card">
            <h3>Audit Log</h3>
            <button id="load-audit">Neu laden</button>
            <div class="audit-list">
              ${(state.security.auditEntries || []).slice(0, 30).map((entry) => `<div class="audit-item"><strong>${entry.event || ''}</strong><br><span class="muted">${entry.timestamp || ''} · ${entry.user || '-'}</span></div>`).join('')}
            </div>
          </div>
        </div>
      </section>
    `;
  }

  if (state.view === 'settings') {
    const updater = state.settings?.updater || {};
    const appearance = state.settings?.appearance || {};

    return h`
      <section class="card">
        <h2>Settings</h2>
        <form id="settings-form">
          <label>Site Name <input name="name" value="${state.settings?.name || ''}"></label>
          <label>Base URL <input name="base_url" value="${state.settings?.base_url || ''}"></label>
          <label>Update Channel <input name="updater_channel" value="${updater.channel || 'stable'}"></label>
          <label>Update Manifest URL <input name="updater_manifest_url" value="${updater.manifest_url || ''}"></label>
          <label>Theme
            <select name="theme">
              ${state.themes.map((t) => `<option value="${t.id}" ${appearance.theme === t.id ? 'selected' : ''}>${t.id}</option>`).join('')}
            </select>
          </label>
          <button class="primary" type="submit">Speichern</button>
        </form>
        <hr>
        <div class="grid-2">
          <button id="backup-btn">Backup erstellen</button>
          <button id="clear-cache-btn">Cache leeren</button>
        </div>
        <p id="settings-status" class="muted"></p>
      </section>
    `;
  }

  return '<section class="card"><p>Unbekannte Ansicht</p></section>';
};

const entryEditor = () => {
  const frontmatter = { ...state.currentEntry };
  delete frontmatter.content;
  delete frontmatter.markdown;

  return h`
    <form id="entry-form">
      <input type="hidden" name="id" value="${state.currentEntry.id}">
      <label>Titel <input name="title" value="${state.currentEntry.title || ''}"></label>
      <label>Frontmatter (JSON)
        <textarea name="frontmatter" rows="10">${escapeHtml(JSON.stringify(frontmatter, null, 2))}</textarea>
      </label>
      <label>Markdown
        <textarea name="markdown" rows="16">${escapeHtml(state.currentEntry.markdown || '')}</textarea>
      </label>
      <button class="primary" type="submit">Speichern</button>
    </form>
  `;
};

const render = () => {
  app.innerHTML = state.user ? shell() : loginView();
  bindEvents();
};

const bindEvents = () => {
  if (!state.user) {
    document.getElementById('login-form')?.addEventListener('submit', login);
    return;
  }

  document.querySelectorAll('[data-view]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      state.view = btn.dataset.view;
      if (state.view === 'content') await loadEntries(state.currentCollection);
      if (state.view === 'security') await loadAudit();
      render();
    });
  });

  document.getElementById('logout-btn')?.addEventListener('click', logout);
  document.getElementById('collection-select')?.addEventListener('change', async (event) => {
    state.currentCollection = event.target.value;
    await loadEntries(state.currentCollection);
    render();
  });

  document.querySelectorAll('.entry-row').forEach((row) => {
    row.addEventListener('click', async () => {
      await loadEntry(state.currentCollection, row.dataset.entryId);
      render();
    });
  });

  document.getElementById('entry-form')?.addEventListener('submit', saveEntry);
  document.getElementById('media-upload-form')?.addEventListener('submit', uploadMedia);
  document.getElementById('load-submissions')?.addEventListener('click', loadSubmissions);
  document.querySelectorAll('.toggle-plugin').forEach((btn) => btn.addEventListener('click', togglePlugin));
  document.querySelectorAll('.plugin-install-registry').forEach((btn) => btn.addEventListener('click', installPluginRegistry));
  document.getElementById('plugin-install-form')?.addEventListener('submit', installPluginSource);
  document.querySelectorAll('.theme-install-registry').forEach((btn) => btn.addEventListener('click', installThemeRegistry));
  document.getElementById('theme-install-form')?.addEventListener('submit', installThemeSource);
  document.querySelectorAll('.theme-activate').forEach((btn) => btn.addEventListener('click', activateTheme));
  document.getElementById('backup-btn')?.addEventListener('click', createBackup);
  document.getElementById('clear-cache-btn')?.addEventListener('click', clearCache);
  document.getElementById('settings-form')?.addEventListener('submit', saveSettings);
  document.getElementById('load-audit')?.addEventListener('click', async () => {
    await loadAudit();
    render();
  });
  document.getElementById('twofa-generate')?.addEventListener('click', generateTwoFactorSecret);
  document.getElementById('twofa-enable-form')?.addEventListener('submit', enableTwoFactor);
  document.getElementById('twofa-disable')?.addEventListener('click', disableTwoFactor);
};

const login = async (event) => {
  event.preventDefault();
  const data = Object.fromEntries(new FormData(event.target).entries());

  try {
    const result = await fetch('/admin/api/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(data)
    }).then((r) => r.json());

    if (!result.ok) throw new Error(result.error || 'Login fehlgeschlagen');
    state.user = result.user;
    state.csrf = result.csrf;
    state.security.twofaEnabled = !!result?.security?.twofa_enabled;
    await hydrate();
    render();
  } catch (error) {
    const box = document.getElementById('login-error');
    if (box) box.textContent = error.message;
  }
};

const logout = async () => {
  await api('/admin/api/auth/logout', { method: 'POST' });
  state.user = null;
  state.csrf = '';
  render();
};

const hydrate = async () => {
  const me = await api('/admin/api/me');
  state.user = me.user;
  state.csrf = me.csrf;
  state.security.twofaEnabled = !!me?.security?.twofa_enabled;

  const collections = await api('/admin/api/collections');
  state.collections = collections.collections;
  if (!state.collections.includes(state.currentCollection) && state.collections.length) {
    state.currentCollection = state.collections[0];
  }

  const plugins = await api('/admin/api/plugins');
  state.plugins = plugins.plugins;

  const pluginRegistry = await api('/admin/api/plugin-registry');
  state.pluginRegistry = pluginRegistry.registry || [];

  const themes = await api('/admin/api/themes');
  state.themes = themes.themes || [];

  const themeRegistry = await api('/admin/api/theme-registry');
  state.themeRegistry = themeRegistry.registry || [];

  await loadEntries(state.currentCollection);

  const settings = await api('/admin/api/settings');
  state.settings = settings.settings;

  await loadAudit();
};

const loadEntries = async (collection) => {
  const data = await api(`/admin/api/entries?collection=${encodeURIComponent(collection)}`);
  state.entries = data.entries;
  state.currentEntry = null;
};

const loadEntry = async (collection, id) => {
  state.currentEntryId = id;
  const data = await api(`/admin/api/entry?collection=${encodeURIComponent(collection)}&id=${encodeURIComponent(id)}`);
  state.currentEntry = data.entry;
};

const saveEntry = async (event) => {
  event.preventDefault();
  const data = new FormData(event.target);

  let frontmatter;
  try {
    frontmatter = JSON.parse(data.get('frontmatter'));
  } catch {
    alert('Frontmatter JSON ist ungueltig.');
    return;
  }

  try {
    await api('/admin/api/entry/save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        collection: state.currentCollection,
        id: data.get('id'),
        frontmatter,
        markdown: data.get('markdown')
      })
    });
  } catch (error) {
    alert(error.message);
    return;
  }

  await loadEntries(state.currentCollection);
  await loadEntry(state.currentCollection, state.currentEntryId);
  render();
};

const uploadMedia = async (event) => {
  event.preventDefault();
  const data = new FormData(event.target);
  const response = await fetch('/admin/api/media/upload', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': state.csrf },
    body: data
  });

  const result = await response.json();
  const box = document.getElementById('media-result');
  if (box) {
    if (result.ok) {
      box.textContent = `Upload erfolgreich: ${result.file}`;
    } else {
      box.textContent = result.error || 'Upload fehlgeschlagen.';
    }
  }
};

const loadSubmissions = async () => {
  const data = await api('/admin/api/forms/submissions?name=contact');
  state.submissions = data.submissions || [];
  render();
};

const togglePlugin = async (event) => {
  await api('/admin/api/plugins/toggle', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      id: event.currentTarget.dataset.id,
      active: event.currentTarget.dataset.active === '1'
    })
  });

  const plugins = await api('/admin/api/plugins');
  state.plugins = plugins.plugins;
  render();
};

const installPluginRegistry = async (event) => {
  await api('/admin/api/plugins/install', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: event.currentTarget.dataset.id, enable: true })
  });

  const plugins = await api('/admin/api/plugins');
  state.plugins = plugins.plugins;
  render();
};

const installPluginSource = async (event) => {
  event.preventDefault();
  const formData = new FormData(event.target);
  await api('/admin/api/plugins/install', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      source: formData.get('source'),
      enable: formData.get('enable') === 'on'
    })
  });

  const plugins = await api('/admin/api/plugins');
  state.plugins = plugins.plugins;
  event.target.reset();
  render();
};

const installThemeRegistry = async (event) => {
  await api('/admin/api/themes/install', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: event.currentTarget.dataset.id })
  });

  const themes = await api('/admin/api/themes');
  state.themes = themes.themes;
  render();
};

const installThemeSource = async (event) => {
  event.preventDefault();
  const formData = new FormData(event.target);
  await api('/admin/api/themes/install', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ source: formData.get('source') })
  });

  const themes = await api('/admin/api/themes');
  state.themes = themes.themes;
  event.target.reset();
  render();
};

const activateTheme = async (event) => {
  await api('/admin/api/themes/activate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: event.currentTarget.dataset.id })
  });

  const themes = await api('/admin/api/themes');
  state.themes = themes.themes;
  const settings = await api('/admin/api/settings');
  state.settings = settings.settings;
  render();
};

const saveSettings = async (event) => {
  event.preventDefault();
  const data = new FormData(event.target);

  const payload = {
    settings: {
      name: data.get('name') || '',
      base_url: data.get('base_url') || '',
      updater: {
        ...(state.settings.updater || {}),
        channel: data.get('updater_channel') || 'stable',
        manifest_url: data.get('updater_manifest_url') || ''
      },
      appearance: {
        ...(state.settings.appearance || {}),
        theme: data.get('theme') || 'default'
      },
      smtp: state.settings.smtp || {},
      security: state.settings.security || {}
    }
  };

  await api('/admin/api/settings/save', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });

  const box = document.getElementById('settings-status');
  if (box) box.textContent = 'Settings gespeichert.';

  const themes = await api('/admin/api/themes');
  state.themes = themes.themes;
};

const createBackup = async () => {
  const result = await api('/admin/api/backup/create', { method: 'POST' });
  const box = document.getElementById('settings-status');
  if (box) box.textContent = result.ok ? `Backup erstellt: ${result.file}` : (result.error || 'Backup fehlgeschlagen.');
};

const clearCache = async () => {
  await api('/admin/api/cache/clear', { method: 'POST' });
  const box = document.getElementById('settings-status');
  if (box) box.textContent = 'Cache geleert.';
};

const loadAudit = async () => {
  const result = await api('/admin/api/security/audit?limit=100');
  state.security.auditEntries = result.entries || [];
};

const generateTwoFactorSecret = async () => {
  const result = await api('/admin/api/security/2fa/setup', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({})
  });

  state.security.twofaSecret = result.secret || '';
  state.security.twofaUri = result.otpauth || '';
  render();
};

const enableTwoFactor = async (event) => {
  event.preventDefault();
  const data = new FormData(event.target);

  await api('/admin/api/security/2fa/setup', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      secret: data.get('secret'),
      code: data.get('code')
    })
  });

  state.security.twofaEnabled = true;
  state.security.twofaSecret = '';
  state.security.twofaUri = '';
  render();
};

const disableTwoFactor = async () => {
  await api('/admin/api/security/2fa/disable', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({})
  });

  state.security.twofaEnabled = false;
  state.security.twofaSecret = '';
  state.security.twofaUri = '';
  render();
};

const escapeHtml = (value) => String(value)
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;');

const init = async () => {
  try {
    await hydrate();
  } catch {
    state.user = null;
  }
  render();
};

init();
