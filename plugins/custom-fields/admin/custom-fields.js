(() => {
  const state = {
    csrf: '',
    collection: 'pages',
    collections: [],
    meta: { schema: {} },
    rows: [],
  };

  const el = {
    notice: document.getElementById('notice'),
    collection: document.getElementById('collection'),
    reload: document.getElementById('reload'),
    save: document.getElementById('save'),
    slugFrom: document.getElementById('slug_from'),
    sort: document.getElementById('sort'),
    perPage: document.getElementById('per_page'),
    addField: document.getElementById('add-field'),
    fieldsBody: document.getElementById('fields-body'),
  };

  const typeOptions = [
    'string', 'text', 'date', 'boolean', 'image', 'number', 'integer',
    'list', 'relation', 'json', 'repeater', 'flexible'
  ];

  function showNotice(message, type = 'ok') {
    if (!message) {
      el.notice.innerHTML = '';
      return;
    }
    el.notice.innerHTML = `<div class="notice ${type === 'err' ? 'err' : 'ok'}">${escapeHtml(message)}</div>`;
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  async function api(url, options = {}) {
    const headers = Object.assign({}, options.headers || {});
    if (options.method && options.method !== 'GET') {
      headers['X-CSRF-Token'] = state.csrf;
    }

    const response = await fetch(url, {
      credentials: 'same-origin',
      ...options,
      headers,
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(data.error || `Request failed (${response.status})`);
    }

    return data;
  }

  function readRowsFromDom() {
    const rows = [];
    for (const tr of el.fieldsBody.querySelectorAll('tr[data-index]')) {
      const row = {
        name: tr.querySelector('[data-field="name"]').value.trim(),
        type: tr.querySelector('[data-field="type"]').value,
        required: tr.querySelector('[data-field="required"]').checked,
        defaultValue: tr.querySelector('[data-field="default"]').value,
        help: tr.querySelector('[data-field="help"]').value.trim(),
        of: tr.querySelector('[data-field="of"]').value.trim(),
        collection: tr.querySelector('[data-field="collection"]').value.trim(),
        min: tr.querySelector('[data-field="min"]').value.trim(),
        max: tr.querySelector('[data-field="max"]').value.trim(),
        minItems: tr.querySelector('[data-field="min_items"]').value.trim(),
        maxItems: tr.querySelector('[data-field="max_items"]').value.trim(),
      };
      rows.push(row);
    }
    state.rows = rows;
  }

  function parseDefaultValue(type, raw) {
    const value = String(raw || '').trim();
    if (value === '') return undefined;

    if (type === 'boolean') {
      const normalized = value.toLowerCase();
      if (['true', '1', 'yes', 'on'].includes(normalized)) return true;
      if (['false', '0', 'no', 'off'].includes(normalized)) return false;
      throw new Error('Boolean default muss true/false sein');
    }

    if (type === 'number') {
      if (!Number.isFinite(Number(value))) throw new Error('Number default ist ungueltig');
      return Number(value);
    }

    if (type === 'integer') {
      if (!/^-?\d+$/.test(value)) throw new Error('Integer default ist ungueltig');
      return parseInt(value, 10);
    }

    if (['list', 'relation'].includes(type)) {
      return value.split(',').map((v) => v.trim()).filter(Boolean);
    }

    if (['json', 'repeater', 'flexible'].includes(type)) {
      try {
        const parsed = JSON.parse(value);
        if (type !== 'json' && !Array.isArray(parsed)) {
          throw new Error('Muss ein JSON-Array sein');
        }
        return parsed;
      } catch (err) {
        throw new Error(`JSON default ungueltig (${err.message || 'parse error'})`);
      }
    }

    return value;
  }

  function serializeSchema() {
    const schema = {};

    for (const row of state.rows) {
      if (!row.name) continue;
      if (!/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(row.name)) {
        throw new Error(`Ungueltiger Feldname: ${row.name}`);
      }

      const rules = {
        type: row.type || 'string',
      };

      if (row.required) rules.required = true;
      if (row.help) rules.help = row.help;
      if (row.of) rules.of = row.of;
      if (row.collection) rules.collection = row.collection;
      if (row.min !== '' && Number.isFinite(Number(row.min))) rules.min = Number(row.min);
      if (row.max !== '' && Number.isFinite(Number(row.max))) rules.max = Number(row.max);
      if (row.minItems !== '' && Number.isFinite(Number(row.minItems))) rules.min_items = Number(row.minItems);
      if (row.maxItems !== '' && Number.isFinite(Number(row.maxItems))) rules.max_items = Number(row.maxItems);

      const parsedDefault = parseDefaultValue(rules.type, row.defaultValue);
      if (parsedDefault !== undefined) rules.default = parsedDefault;

      schema[row.name] = rules;
    }

    return schema;
  }

  function mapSchemaToRows(schema) {
    const rows = [];
    const source = schema && typeof schema === 'object' ? schema : {};

    for (const [name, rules] of Object.entries(source)) {
      if (!rules || typeof rules !== 'object') continue;
      rows.push({
        name,
        type: String(rules.type || 'string'),
        required: !!rules.required,
        defaultValue: rules.default === undefined ? '' : (typeof rules.default === 'string' ? rules.default : JSON.stringify(rules.default)),
        help: String(rules.help || ''),
        of: String(rules.of || ''),
        collection: String(rules.collection || ''),
        min: rules.min === undefined ? '' : String(rules.min),
        max: rules.max === undefined ? '' : String(rules.max),
        minItems: rules.min_items === undefined ? '' : String(rules.min_items),
        maxItems: rules.max_items === undefined ? '' : String(rules.max_items),
      });
    }

    return rows;
  }

  function renderRows() {
    if (state.rows.length === 0) {
      el.fieldsBody.innerHTML = '<tr><td class="empty" colspan="5">Noch keine Felder definiert.</td></tr>';
      return;
    }

    el.fieldsBody.innerHTML = state.rows.map((row, index) => {
      const options = typeOptions
        .map((type) => `<option value="${type}" ${row.type === type ? 'selected' : ''}>${type}</option>`)
        .join('');

      return `
        <tr data-index="${index}">
          <td><input class="mono" data-field="name" value="${escapeHtml(row.name)}" placeholder="field_name"></td>
          <td><select data-field="type">${options}</select></td>
          <td>
            <div class="stack">
              <label class="inline"><input type="checkbox" data-field="required" ${row.required ? 'checked' : ''}> required</label>
              <input data-field="help" value="${escapeHtml(row.help)}" placeholder="help text">
              <div class="inline">
                <input data-field="of" value="${escapeHtml(row.of)}" placeholder="of (list)">
                <input data-field="collection" value="${escapeHtml(row.collection)}" placeholder="collection (relation)">
              </div>
              <div class="inline">
                <input data-field="min" value="${escapeHtml(row.min)}" placeholder="min">
                <input data-field="max" value="${escapeHtml(row.max)}" placeholder="max">
              </div>
              <div class="inline">
                <input data-field="min_items" value="${escapeHtml(row.minItems)}" placeholder="min_items">
                <input data-field="max_items" value="${escapeHtml(row.maxItems)}" placeholder="max_items">
              </div>
            </div>
          </td>
          <td><textarea data-field="default" rows="5" class="mono" placeholder="default">${escapeHtml(row.defaultValue)}</textarea></td>
          <td><button type="button" data-action="remove">Entfernen</button></td>
        </tr>
      `;
    }).join('');
  }

  async function loadCollectionMeta() {
    const collection = state.collection;
    const data = await api(`/admin/api/collection/meta?collection=${encodeURIComponent(collection)}`);
    state.meta = data.meta || { schema: {} };

    el.slugFrom.value = String(state.meta.slug_from || 'filename');
    el.sort.value = String(state.meta.sort || 'date desc');
    el.perPage.value = state.meta.per_page ? String(state.meta.per_page) : '';

    state.rows = mapSchemaToRows(state.meta.schema || {});
    renderRows();
  }

  async function loadCollections() {
    const data = await api('/admin/api/collections');
    const collections = Array.isArray(data.collections) ? data.collections : [];
    state.collections = collections;

    el.collection.innerHTML = collections
      .map((col) => `<option value="${escapeHtml(col)}">${escapeHtml(col)}</option>`)
      .join('');

    if (!collections.includes(state.collection)) {
      state.collection = collections[0] || 'pages';
    }

    el.collection.value = state.collection;
  }

  async function saveMeta() {
    readRowsFromDom();

    const nextMeta = {
      ...state.meta,
      slug_from: String(el.slugFrom.value || 'filename').trim() || 'filename',
      sort: String(el.sort.value || '').trim() || 'date desc',
    };

    const perPageRaw = String(el.perPage.value || '').trim();
    if (perPageRaw !== '' && Number.isFinite(Number(perPageRaw))) {
      nextMeta.per_page = Math.max(1, parseInt(perPageRaw, 10));
    } else {
      delete nextMeta.per_page;
    }

    nextMeta.schema = serializeSchema();

    await api('/admin/api/collection/meta/save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        collection: state.collection,
        meta: nextMeta,
      }),
    });

    state.meta = nextMeta;
    showNotice(`Schema fuer Collection "${state.collection}" gespeichert.`, 'ok');
  }

  async function init() {
    try {
      const me = await api('/admin/api/me');
      state.csrf = String(me.csrf || '');
      await loadCollections();
      await loadCollectionMeta();
      showNotice('Custom Fields geladen.', 'ok');
    } catch (err) {
      showNotice(err.message || 'Initialisierung fehlgeschlagen.', 'err');
    }
  }

  el.collection.addEventListener('change', async (event) => {
    state.collection = String(event.currentTarget.value || 'pages');
    try {
      await loadCollectionMeta();
      showNotice('', 'ok');
    } catch (err) {
      showNotice(err.message || 'Collection konnte nicht geladen werden.', 'err');
    }
  });

  el.reload.addEventListener('click', async () => {
    try {
      await loadCollectionMeta();
      showNotice('Neu geladen.', 'ok');
    } catch (err) {
      showNotice(err.message || 'Neu laden fehlgeschlagen.', 'err');
    }
  });

  el.addField.addEventListener('click', () => {
    readRowsFromDom();
    state.rows.push({
      name: '', type: 'string', required: false, defaultValue: '', help: '', of: '', collection: '',
      min: '', max: '', minItems: '', maxItems: '',
    });
    renderRows();
  });

  el.fieldsBody.addEventListener('click', (event) => {
    const action = event.target?.dataset?.action;
    if (action !== 'remove') return;

    const tr = event.target.closest('tr[data-index]');
    if (!tr) return;

    readRowsFromDom();
    const index = Number(tr.dataset.index);
    if (Number.isFinite(index) && index >= 0) {
      state.rows.splice(index, 1);
      renderRows();
    }
  });

  el.save.addEventListener('click', async () => {
    try {
      await saveMeta();
    } catch (err) {
      showNotice(err.message || 'Speichern fehlgeschlagen.', 'err');
    }
  });

  init();
})();
