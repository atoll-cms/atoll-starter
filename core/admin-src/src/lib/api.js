import { csrf } from './stores.js';

let csrfToken = window.__ATOLL_CSRF__ || '';

csrf.subscribe((v) => { csrfToken = v; });

export async function api(url, options = {}) {
  const headers = {
    ...(options.headers || {}),
    'X-CSRF-Token': csrfToken
  };

  const response = await fetch(url, {
    credentials: 'same-origin',
    ...options,
    headers
  });

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    const message = data.error || `Request failed (${response.status})`;
    const error = new Error(message);
    if (data && typeof data === 'object') {
      error.payload = data;
      if (data.fields && typeof data.fields === 'object') {
        error.fields = data.fields;
      }
    }
    throw error;
  }

  return data;
}

export async function apiUpload(url, formData) {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': csrfToken },
    body: formData
  });

  return response.json().catch(() => ({}));
}
