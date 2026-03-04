const parseProps = (value) => {
  if (!value) return {};
  try {
    return JSON.parse(value);
  } catch {
    return {};
  }
};

const hydrate = async (el) => {
  if (el.dataset.hydrated === '1') return;
  const modulePath = el.dataset.module;
  if (!modulePath) return;

  try {
    const mod = await import(modulePath);
    const mount = mod.mount || mod.default;
    if (typeof mount === 'function') {
      await mount(el, parseProps(el.dataset.props || '{}'));
    }
    el.dataset.hydrated = '1';
  } catch (err) {
    console.error('[atoll] island hydration failed', err);
  }
};

const setupIsland = (el) => {
  const client = el.dataset.client || 'load';

  if (client === 'none') return;

  if (client === 'load') {
    hydrate(el);
    return;
  }

  if (client === 'idle') {
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(() => hydrate(el));
    } else {
      setTimeout(() => hydrate(el), 200);
    }
    return;
  }

  if (client === 'visible') {
    if (!('IntersectionObserver' in window)) {
      hydrate(el);
      return;
    }

    const observer = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) {
          hydrate(el);
          observer.disconnect();
          break;
        }
      }
    });

    observer.observe(el);
    return;
  }

  if (client === 'media') {
    const media = el.dataset.media || '(min-width: 1024px)';
    const mq = window.matchMedia(media);
    const run = () => {
      if (mq.matches) hydrate(el);
    };
    run();
    mq.addEventListener?.('change', run);
    return;
  }

  hydrate(el);
};

const bootstrap = () => {
  const islands = document.querySelectorAll('.atoll-island[data-island]');
  islands.forEach(setupIsland);
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
  bootstrap();
}
