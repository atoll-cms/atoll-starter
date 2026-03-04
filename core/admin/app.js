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
  submissions: [],
  settings: {}
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
  if (!response.ok) throw new Error(data.error || 'Request failed');
  return data;
};

const loginView = () => h`
  <main class="content">
    <section class="card" style="max-width:440px;margin:5rem auto;">
      <h1>atoll-cms Login</h1>
      <p class="muted">Standard: admin / admin123</p>
      <form id="login-form">
        <label>Benutzername <input name="username" required value="admin"></label>
        <label>Passwort <input name="password" type="password" required value="admin123"></label>
        <button class="primary" type="submit">Einloggen</button>
      </form>
      <p id="login-error" class="muted"></p>
    </section>
  </main>
`;

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
        ${menuButton('settings', 'Settings')}
      </nav>
      <hr>
      <p class="muted">Angemeldet als ${state.user}</p>
      <button id="logout-btn">Logout</button>
    </aside>
    <main class="content">${viewContent()}</main>
  </div>
`;

const menuButton = (view, label) => `<button class="${state.view === view ? 'active' : ''}" data-view="${view}">${label}</button>`;

const viewContent = () => {
  if (state.view === 'dashboard') {
    return h`
      <section class="card">
        <h2>Dashboard</h2>
        <p>Willkommen im atoll-cms Admin-Panel.</p>
        <div class="grid-2">
          <div class="card"><strong>${state.collections.length}</strong><br>Collections</div>
          <div class="card"><strong>${state.plugins.length}</strong><br>Plugins</div>
        </div>
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
                  <tr data-entry-id="${entry.id}" class="entry-row">
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
        <p class="muted" id="media-result">Automatische WebP/srcset-Generierung aktiv, falls Image-Library vorhanden ist.</p>
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
            ${state.submissions.map((row) => `<tr><td>${row.timestamp || ''}</td><td><pre>${JSON.stringify(row.payload || {}, null, 2)}</pre></td></tr>`).join('')}
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
          <thead><tr><th>Name</th><th>Version</th><th>Status</th><th></th></tr></thead>
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
      </section>
    `;
  }

  if (state.view === 'settings') {
    return h`
      <section class="card">
        <h2>Settings</h2>
        <form id="settings-form">
          <label>Site Name <input name="name" value="${state.settings?.name || ''}"></label>
          <label>Base URL <input name="base_url" value="${state.settings?.base_url || ''}"></label>
          <label>Theme
            <select name="theme">
              <option value="default" ${state.settings?.appearance?.theme === 'default' ? 'selected' : ''}>default</option>
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
        <textarea name="frontmatter" rows="10">${JSON.stringify(frontmatter, null, 2)}</textarea>
      </label>
      <label>Markdown
        <textarea name="markdown" rows="16">${state.currentEntry.markdown || ''}</textarea>
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
      if (state.view === 'content') {
        await loadEntries(state.currentCollection);
      }
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
  document.getElementById('backup-btn')?.addEventListener('click', createBackup);
  document.getElementById('clear-cache-btn')?.addEventListener('click', clearCache);
  document.getElementById('settings-form')?.addEventListener('submit', saveSettings);
};

const login = async (event) => {
  event.preventDefault();
  const data = new FormData(event.target);
  try {
    const result = await fetch('/admin/api/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(Object.fromEntries(data.entries()))
    }).then((r) => r.json());

    if (!result.ok) throw new Error(result.error || 'Login fehlgeschlagen');
    state.user = result.user;
    state.csrf = result.csrf;
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
  render();
};

const hydrate = async () => {
  const me = await api('/admin/api/me');
  state.user = me.user;
  state.csrf = me.csrf;

  const collections = await api('/admin/api/collections');
  state.collections = collections.collections;

  const plugins = await api('/admin/api/plugins');
  state.plugins = plugins.plugins;

  await loadEntries(state.currentCollection);

  const settings = await api('/admin/api/settings');
  state.settings = settings.settings;
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
    box.textContent = result.ok ? `Gespeichert: ${result.file}` : result.error;
  }
};

const loadSubmissions = async () => {
  const data = await api('/admin/api/forms/submissions?name=contact');
  state.submissions = data.submissions;
  render();
};

const togglePlugin = async (event) => {
  const btn = event.currentTarget;
  await api('/admin/api/plugins/toggle', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      id: btn.dataset.id,
      active: btn.dataset.active === '1'
    })
  });
  const plugins = await api('/admin/api/plugins');
  state.plugins = plugins.plugins;
  render();
};

const createBackup = async () => {
  const result = await api('/admin/api/backup/create', { method: 'POST' });
  const status = document.getElementById('settings-status');
  if (status) status.textContent = result.ok ? `Backup erstellt: ${result.file}` : result.error;
};

const clearCache = async () => {
  await api('/admin/api/cache/clear', { method: 'POST' });
  const status = document.getElementById('settings-status');
  if (status) status.textContent = 'Cache geleert.';
};

const saveSettings = async (event) => {
  event.preventDefault();
  const data = new FormData(event.target);
  await api('/admin/api/settings/save', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      settings: {
        name: data.get('name'),
        base_url: data.get('base_url'),
        appearance: {
          theme: data.get('theme') || 'default'
        }
      }
    })
  });

  state.settings = {
    ...state.settings,
    name: data.get('name'),
    base_url: data.get('base_url'),
    appearance: { theme: data.get('theme') || 'default' }
  };

  const status = document.getElementById('settings-status');
  if (status) status.textContent = 'Einstellungen gespeichert.';
};

(async () => {
  try {
    await hydrate();
  } catch {
    state.user = null;
  }
  render();
})();
