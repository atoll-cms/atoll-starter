const noticeEl = document.getElementById('notice');
const bodyEl = document.getElementById('members-body');
const createForm = document.getElementById('create-form');
const reloadBtn = document.getElementById('reload');

function showNotice(type, message) {
  noticeEl.innerHTML = `<div class="notice ${type}">${message}</div>`;
}

function clearNotice() {
  noticeEl.innerHTML = '';
}

async function api(url, options = {}) {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      ...(options.headers || {})
    },
    ...options
  });

  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(data.error || `Request failed (${response.status})`);
  }

  return data;
}

function rowTemplate(member) {
  const id = String(member.id || '');
  const escapedEmail = escapeHtml(member.email || '');
  const escapedName = escapeHtml(member.name || '');
  const escapedLastLogin = escapeHtml(member.last_login_at || '-');
  const role = ['member', 'editor', 'admin'].includes(member.role) ? member.role : 'member';
  const status = ['active', 'disabled'].includes(member.status) ? member.status : 'active';

  return `
    <tr data-id="${id}">
      <td class="mono">${escapedEmail}</td>
      <td><input data-field="name" type="text" value="${escapedName}"></td>
      <td>
        <select data-field="role">
          ${['member', 'editor', 'admin'].map((value) => `<option value="${value}" ${value === role ? 'selected' : ''}>${value}</option>`).join('')}
        </select>
      </td>
      <td>
        <select data-field="status">
          ${['active', 'disabled'].map((value) => `<option value="${value}" ${value === status ? 'selected' : ''}>${value}</option>`).join('')}
        </select>
      </td>
      <td class="mono">${escapedLastLogin}</td>
      <td>
        <div class="actions">
          <button type="button" data-action="save">Speichern</button>
          <button type="button" data-action="pw">Passwort setzen</button>
          <button type="button" data-action="delete">Loeschen</button>
        </div>
      </td>
    </tr>
  `;
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

async function loadMembers() {
  clearNotice();
  bodyEl.innerHTML = '<tr><td colspan="6">Lade...</td></tr>';

  try {
    const data = await api('/members/admin/list');
    const members = Array.isArray(data.members) ? data.members : [];
    if (!members.length) {
      bodyEl.innerHTML = '<tr><td colspan="6" class="muted">Noch keine Mitglieder.</td></tr>';
      return;
    }

    bodyEl.innerHTML = members.map(rowTemplate).join('');
  } catch (error) {
    bodyEl.innerHTML = '<tr><td colspan="6">Fehler beim Laden.</td></tr>';
    showNotice('err', error.message || 'Fehler beim Laden der Mitglieder.');
  }
}

createForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  clearNotice();

  const formData = new FormData(createForm);
  const payload = Object.fromEntries(formData.entries());

  try {
    await api('/members/admin/create', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    createForm.reset();
    showNotice('ok', 'Mitglied angelegt.');
    await loadMembers();
  } catch (error) {
    showNotice('err', error.message || 'Mitglied konnte nicht angelegt werden.');
  }
});

reloadBtn?.addEventListener('click', () => {
  loadMembers();
});

bodyEl?.addEventListener('click', async (event) => {
  const target = event.target;
  if (!(target instanceof HTMLButtonElement)) {
    return;
  }

  const action = target.dataset.action;
  if (!action) {
    return;
  }

  const row = target.closest('tr');
  if (!(row instanceof HTMLTableRowElement)) {
    return;
  }

  const id = row.dataset.id || '';
  if (!id) {
    return;
  }

  if (action === 'delete') {
    const confirmed = window.confirm('Mitglied wirklich loeschen?');
    if (!confirmed) {
      return;
    }

    try {
      await api('/members/admin/delete', {
        method: 'POST',
        body: JSON.stringify({ id })
      });
      showNotice('ok', 'Mitglied geloescht.');
      await loadMembers();
    } catch (error) {
      showNotice('err', error.message || 'Loeschen fehlgeschlagen.');
    }

    return;
  }

  const nameInput = row.querySelector('input[data-field="name"]');
  const roleInput = row.querySelector('select[data-field="role"]');
  const statusInput = row.querySelector('select[data-field="status"]');

  if (!(nameInput instanceof HTMLInputElement) || !(roleInput instanceof HTMLSelectElement) || !(statusInput instanceof HTMLSelectElement)) {
    return;
  }

  const payload = {
    id,
    name: nameInput.value,
    role: roleInput.value,
    status: statusInput.value
  };

  if (action === 'pw') {
    const newPassword = window.prompt('Neues Passwort eingeben (leer = abbrechen):', '');
    if (!newPassword) {
      return;
    }
    payload.password = newPassword;
  }

  try {
    await api('/members/admin/update', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    showNotice('ok', action === 'pw' ? 'Passwort aktualisiert.' : 'Mitglied gespeichert.');
    await loadMembers();
  } catch (error) {
    showNotice('err', error.message || 'Speichern fehlgeschlagen.');
  }
});

loadMembers();
