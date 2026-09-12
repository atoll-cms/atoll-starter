import { writable } from 'svelte/store';

export const csrf = writable(window.__ATOLL_CSRF__ || '');
export const user = writable(null);
export const currentView = writable('dashboard');
export const sidebarCollapsed = writable(false);

export const collections = writable([]);
export const entries = writable([]);
export const currentCollection = writable('pages');
export const currentEntry = writable(null);
export const currentEntryId = writable('index');

export const plugins = writable([]);
export const pluginRegistry = writable([]);
export const themes = writable([]);
export const themeRegistry = writable([]);
export const adminMenu = writable([]);
export const dashboardWidgets = writable([]);
export const submissions = writable([]);
export const settings = writable({});

export const security = writable({
  twofaEnabled: false,
  role: 'owner',
  permissions: [],
  twofaSecret: '',
  twofaUri: '',
  auditEntries: []
});

export const toasts = writable([]);

let toastId = 0;
export function addToast(message, type = 'info', duration = 4000) {
  const id = ++toastId;
  toasts.update((t) => [...t, { id, message, type }]);
  if (duration > 0) {
    setTimeout(() => {
      toasts.update((t) => t.filter((x) => x.id !== id));
    }, duration);
  }
}

export const loading = writable(false);
export const modalState = writable({ open: false, title: '', message: '', onConfirm: null });

export function confirm(title, message) {
  return new Promise((resolve) => {
    modalState.set({
      open: true,
      title,
      message,
      onConfirm: () => {
        modalState.set({ open: false, title: '', message: '', onConfirm: null });
        resolve(true);
      },
      onCancel: () => {
        modalState.set({ open: false, title: '', message: '', onConfirm: null });
        resolve(false);
      }
    });
  });
}
