const noticeEl = document.getElementById('notice');
const slotDateInput = document.getElementById('slot-date');
const slotServiceSelect = document.getElementById('slot-service');
const loadSlotsBtn = document.getElementById('load-slots');
const slotsEl = document.getElementById('slots');
const icalLinkEl = document.getElementById('ical-link');
const bookingsBody = document.getElementById('bookings-body');
const reloadBookingsBtn = document.getElementById('reload-bookings');
const filterStatus = document.getElementById('filter-status');
const filterFrom = document.getElementById('filter-from');
const filterTo = document.getElementById('filter-to');

let services = [];

function showNotice(type, message) {
  noticeEl.innerHTML = `<div class="notice ${type}">${escapeHtml(message)}</div>`;
}

function clearNotice() {
  noticeEl.innerHTML = '';
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
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

function statusBadge(status) {
  const normalized = ['pending', 'confirmed', 'cancelled', 'completed'].includes(status) ? status : 'pending';
  return `<span class="badge ${normalized}">${normalized}</span>`;
}

function bookingRow(booking) {
  const id = escapeHtml(booking.id || '');
  const when = escapeHtml(`${booking.date || ''} ${booking.start_time || ''}-${booking.end_time || ''}`);
  const service = escapeHtml(booking.service_label || booking.service || '');
  const customer = escapeHtml(`${booking.customer?.name || ''} <${booking.customer?.email || ''}>`);
  const status = String(booking.status || 'pending');

  return `
    <tr data-id="${id}">
      <td class="mono">${id}</td>
      <td class="mono">${when}</td>
      <td>${service}</td>
      <td>${customer}</td>
      <td>${statusBadge(status)}</td>
      <td>
        <div class="actions">
          ${['pending', 'confirmed', 'completed', 'cancelled'].map((candidate) =>
            `<button type="button" data-action="status" data-status="${candidate}">${candidate}</button>`
          ).join('')}
        </div>
      </td>
    </tr>
  `;
}

async function loadServices() {
  const data = await api('/booking-pro/services');
  services = Array.isArray(data.services) ? data.services : [];

  if (!services.length) {
    slotServiceSelect.innerHTML = '<option value="">No services</option>';
    return;
  }

  slotServiceSelect.innerHTML = services
    .map((service) => `<option value="${escapeHtml(service.id || '')}">${escapeHtml(service.label || service.id || '')}</option>`)
    .join('');
}

async function loadSlots() {
  clearNotice();

  const date = slotDateInput.value;
  const service = slotServiceSelect.value;
  if (!date || !service) {
    slotsEl.innerHTML = '<div class="slot empty">Pick date and service first.</div>';
    return;
  }

  slotsEl.innerHTML = '<div class="slot empty">Loading slots...</div>';

  try {
    const data = await api(`/booking-pro/slots?date=${encodeURIComponent(date)}&service=${encodeURIComponent(service)}`);
    const slots = Array.isArray(data.slots) ? data.slots : [];

    if (!slots.length) {
      slotsEl.innerHTML = '<div class="slot empty">No free slots for this date.</div>';
      return;
    }

    slotsEl.innerHTML = slots
      .map((slot) => `<div class="slot">${escapeHtml(slot.start || '')} - ${escapeHtml(slot.end || '')}</div>`)
      .join('');
  } catch (error) {
    slotsEl.innerHTML = '<div class="slot empty">Slot loading failed.</div>';
    showNotice('err', error.message || 'Could not load slots.');
  }
}

function bookingsUrl() {
  const params = new URLSearchParams();
  if (filterStatus.value) params.set('status', filterStatus.value);
  if (filterFrom.value) params.set('from', filterFrom.value);
  if (filterTo.value) params.set('to', filterTo.value);

  const query = params.toString();
  return `/booking-pro/admin/bookings${query ? `?${query}` : ''}`;
}

async function loadBookings() {
  bookingsBody.innerHTML = '<tr><td colspan="6">Loading bookings...</td></tr>';

  try {
    const data = await api(bookingsUrl());
    const rows = Array.isArray(data.bookings) ? data.bookings : [];

    if (!rows.length) {
      bookingsBody.innerHTML = '<tr><td colspan="6">No bookings found.</td></tr>';
      return;
    }

    bookingsBody.innerHTML = rows.map(bookingRow).join('');
  } catch (error) {
    bookingsBody.innerHTML = '<tr><td colspan="6">Booking load failed.</td></tr>';
    showNotice('err', error.message || 'Could not load bookings.');
  }
}

async function updateStatus(id, status) {
  await api('/booking-pro/admin/bookings/update', {
    method: 'POST',
    body: JSON.stringify({ id, status })
  });
}

bookingsBody?.addEventListener('click', async (event) => {
  const target = event.target;
  if (!(target instanceof HTMLButtonElement)) return;
  if (target.dataset.action !== 'status') return;

  const row = target.closest('tr');
  if (!(row instanceof HTMLTableRowElement)) return;

  const id = row.dataset.id || '';
  const status = target.dataset.status || '';
  if (!id || !status) return;

  try {
    await updateStatus(id, status);
    showNotice('ok', `Booking ${id} updated to ${status}.`);
    await loadBookings();
  } catch (error) {
    showNotice('err', error.message || 'Could not update booking status.');
  }
});

loadSlotsBtn?.addEventListener('click', () => loadSlots());
reloadBookingsBtn?.addEventListener('click', () => loadBookings());
filterStatus?.addEventListener('change', () => loadBookings());
filterFrom?.addEventListener('change', () => loadBookings());
filterTo?.addEventListener('change', () => loadBookings());

async function init() {
  const today = new Date();
  const isoDate = today.toISOString().slice(0, 10);
  slotDateInput.value = isoDate;
  filterFrom.value = isoDate;
  icalLinkEl.href = '/booking-pro/ical';

  try {
    await loadServices();
    await loadSlots();
    await loadBookings();
  } catch (error) {
    showNotice('err', error.message || 'Booking-Pro initialization failed.');
  }
}

init();
