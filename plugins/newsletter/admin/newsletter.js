const noticeEl = document.getElementById('notice');
const campaignForm = document.getElementById('campaign-form');
const subscribersBody = document.getElementById('subscribers-body');
const logEl = document.getElementById('log');
const reloadSubsBtn = document.getElementById('reload-subs');
const reloadLogBtn = document.getElementById('reload-log');

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

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

async function loadSubscribers() {
  subscribersBody.innerHTML = '<tr><td colspan="5">Lade...</td></tr>';

  try {
    const data = await api('/newsletter/admin/subscribers');
    const rows = Array.isArray(data.subscribers) ? data.subscribers : [];

    if (!rows.length) {
      subscribersBody.innerHTML = '<tr><td colspan="5">Keine Subscriber vorhanden.</td></tr>';
      return;
    }

    subscribersBody.innerHTML = rows.map((row) => `
      <tr>
        <td class="mono">${escapeHtml(row.email || '')}</td>
        <td>${escapeHtml(row.name || '')}</td>
        <td>${escapeHtml(row.status || '')}</td>
        <td>${escapeHtml(Array.isArray(row.tags) ? row.tags.join(', ') : '')}</td>
        <td class="mono">${escapeHtml(row.created_at || '')}</td>
      </tr>
    `).join('');
  } catch (error) {
    subscribersBody.innerHTML = '<tr><td colspan="5">Laden fehlgeschlagen.</td></tr>';
    showNotice('err', error.message || 'Subscriber konnten nicht geladen werden.');
  }
}

async function loadLog() {
  logEl.textContent = 'Lade...';

  try {
    const data = await api('/newsletter/admin/campaign/log');
    const rows = Array.isArray(data.entries) ? data.entries : [];
    if (!rows.length) {
      logEl.textContent = 'Noch keine Kampagnen ausgefuehrt.';
      return;
    }

    logEl.textContent = rows
      .map((entry) => `${entry.created_at || '-'} | ${entry.subject || '-'} | segment=${entry.segment || 'all'} | dry=${entry.dry_run ? 'yes' : 'no'} | target=${entry.target_count || 0} | sent=${entry.sent || 0} | failed=${entry.failed || 0}`)
      .join('\n');
  } catch (error) {
    logEl.textContent = 'Log konnte nicht geladen werden.';
    showNotice('err', error.message || 'Campaign-Log konnte nicht geladen werden.');
  }
}

campaignForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  clearNotice();

  const formData = new FormData(campaignForm);
  const payload = Object.fromEntries(formData.entries());
  payload.dry_run = String(payload.dry_run || '1') === '1';

  try {
    const data = await api('/newsletter/admin/campaign/send', {
      method: 'POST',
      body: JSON.stringify(payload)
    });

    const campaign = data.campaign || {};
    showNotice(
      'ok',
      `Kampagne verarbeitet: target=${campaign.target_count || 0}, sent=${campaign.sent || 0}, failed=${campaign.failed || 0}, dry=${campaign.dry_run ? 'yes' : 'no'}`
    );

    await loadLog();
    await loadSubscribers();
  } catch (error) {
    showNotice('err', error.message || 'Kampagne fehlgeschlagen.');
  }
});

reloadSubsBtn?.addEventListener('click', () => loadSubscribers());
reloadLogBtn?.addEventListener('click', () => loadLog());

loadSubscribers();
loadLog();
