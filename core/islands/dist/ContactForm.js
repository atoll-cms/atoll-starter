export function mount(el, props = {}) {
  const captcha = normalizeCaptcha(props.captcha);
  const timingField = typeof props.timingField === 'string' && props.timingField.trim() !== ''
    ? props.timingField.trim()
    : '_atoll_ts';

  const form = document.createElement('form');
  form.className = 'contact-form';
  form.method = 'post';
  form.action = props.endpoint || '/forms/contact';

  form.innerHTML = `
    <input type="hidden" name="_csrf" value="${props.csrf || ''}">
    <input type="hidden" name="${escapeAttr(timingField)}" value="${Math.floor(Date.now() / 1000)}">
    <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
    <label>Name <input name="name" required></label>
    <label>E-Mail <input name="email" type="email" required></label>
    <label>Nachricht <textarea name="message" rows="5" required></textarea></label>
    ${captcha.enabled ? `<input type="hidden" name="${escapeAttr(captcha.tokenField)}" value="">` : ''}
    ${captcha.enabled ? '<div class="captcha-widget" aria-live="polite"></div>' : ''}
    <button type="submit">Senden</button>
    <p class="status" role="status"></p>
  `;

  const status = form.querySelector('.status');
  const tokenInput = captcha.enabled ? form.querySelector(`input[name="${cssEscape(captcha.tokenField)}"]`) : null;
  const captchaHost = captcha.enabled ? form.querySelector('.captcha-widget') : null;
  let captchaWidgetId = null;

  const setStatus = (text, type = '') => {
    status.textContent = text || '';
    status.dataset.type = type;
  };

  if (captcha.enabled && tokenInput && captchaHost) {
    setupCaptchaWidget(captcha, captchaHost, tokenInput, setStatus).then((widgetId) => {
      captchaWidgetId = widgetId;
    }).catch(() => {
      setStatus('Captcha konnte nicht geladen werden.', 'error');
    });
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (captcha.enabled && tokenInput && !tokenInput.value) {
      setStatus('Bitte CAPTCHA bestaetigen.', 'error');
      return;
    }

    const data = new FormData(form);
    const payload = Object.fromEntries(data.entries());

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const result = await response.json();
      if (result.ok) {
        setStatus(result.message || 'Danke, Nachricht gesendet.', 'success');
        form.reset();
        const tsField = form.querySelector(`input[name="${cssEscape(timingField)}"]`);
        if (tsField) {
          tsField.value = String(Math.floor(Date.now() / 1000));
        }
        if (captcha.enabled) {
          resetCaptcha(captcha, captchaWidgetId);
        }
      } else {
        setStatus(result.error || 'Senden fehlgeschlagen.', 'error');
      }
    } catch {
      setStatus('Senden fehlgeschlagen.', 'error');
    }
  });

  el.appendChild(form);
}

function normalizeCaptcha(config) {
  const value = config && typeof config === 'object' ? config : {};
  return {
    enabled: !!value.enabled && typeof value.siteKey === 'string' && value.siteKey.trim() !== '',
    provider: typeof value.provider === 'string' ? value.provider.toLowerCase().trim() : 'turnstile',
    siteKey: typeof value.siteKey === 'string' ? value.siteKey.trim() : '',
    tokenField: typeof value.tokenField === 'string' && value.tokenField.trim() !== ''
      ? value.tokenField.trim()
      : 'captcha_token'
  };
}

async function setupCaptchaWidget(captcha, container, tokenInput, setStatus) {
  if (captcha.provider === 'turnstile') {
    await loadScriptOnce('https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit');
    if (!window.turnstile || typeof window.turnstile.render !== 'function') {
      throw new Error('turnstile unavailable');
    }
    return window.turnstile.render(container, {
      sitekey: captcha.siteKey,
      callback: (token) => {
        tokenInput.value = token || '';
        setStatus('');
      },
      'expired-callback': () => {
        tokenInput.value = '';
      },
      'error-callback': () => {
        tokenInput.value = '';
        setStatus('Captcha-Fehler. Bitte erneut versuchen.', 'error');
      }
    });
  }

  if (captcha.provider === 'hcaptcha') {
    await loadScriptOnce('https://js.hcaptcha.com/1/api.js?render=explicit');
    if (!window.hcaptcha || typeof window.hcaptcha.render !== 'function') {
      throw new Error('hcaptcha unavailable');
    }
    return window.hcaptcha.render(container, {
      sitekey: captcha.siteKey,
      callback: (token) => {
        tokenInput.value = token || '';
        setStatus('');
      },
      'expired-callback': () => {
        tokenInput.value = '';
      },
      'error-callback': () => {
        tokenInput.value = '';
        setStatus('Captcha-Fehler. Bitte erneut versuchen.', 'error');
      }
    });
  }

  if (captcha.provider === 'recaptcha') {
    await loadScriptOnce('https://www.google.com/recaptcha/api.js?render=explicit');
    if (!window.grecaptcha || typeof window.grecaptcha.render !== 'function') {
      throw new Error('recaptcha unavailable');
    }
    return window.grecaptcha.render(container, {
      sitekey: captcha.siteKey,
      callback: (token) => {
        tokenInput.value = token || '';
        setStatus('');
      },
      'expired-callback': () => {
        tokenInput.value = '';
      },
      'error-callback': () => {
        tokenInput.value = '';
        setStatus('Captcha-Fehler. Bitte erneut versuchen.', 'error');
      }
    });
  }

  throw new Error('unsupported captcha provider');
}

function resetCaptcha(captcha, widgetId) {
  if (widgetId === null || widgetId === undefined) {
    return;
  }

  if (captcha.provider === 'turnstile' && window.turnstile && typeof window.turnstile.reset === 'function') {
    window.turnstile.reset(widgetId);
  }
  if (captcha.provider === 'hcaptcha' && window.hcaptcha && typeof window.hcaptcha.reset === 'function') {
    window.hcaptcha.reset(widgetId);
  }
  if (captcha.provider === 'recaptcha' && window.grecaptcha && typeof window.grecaptcha.reset === 'function') {
    window.grecaptcha.reset(widgetId);
  }
}

const loadedScripts = new Map();

function loadScriptOnce(src) {
  if (loadedScripts.has(src)) {
    return loadedScripts.get(src);
  }

  const promise = new Promise((resolve, reject) => {
    const existing = document.querySelector(`script[src="${cssEscape(src)}"]`);
    if (existing) {
      if (existing.dataset.loaded === 'true') {
        resolve();
        return;
      }
      existing.addEventListener('load', () => resolve(), { once: true });
      existing.addEventListener('error', () => reject(new Error('script load failed')), { once: true });
      return;
    }

    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.defer = true;
    script.addEventListener('load', () => {
      script.dataset.loaded = 'true';
      resolve();
    }, { once: true });
    script.addEventListener('error', () => reject(new Error('script load failed')), { once: true });
    document.head.appendChild(script);
  });

  loadedScripts.set(src, promise);
  return promise;
}

function escapeAttr(value) {
  return String(value).replace(/[&<>"']/g, (char) => {
    if (char === '&') return '&amp;';
    if (char === '<') return '&lt;';
    if (char === '>') return '&gt;';
    if (char === '"') return '&quot;';
    return '&#39;';
  });
}

function cssEscape(value) {
  if (window.CSS && typeof window.CSS.escape === 'function') {
    return window.CSS.escape(String(value));
  }
  return String(value).replace(/["\\]/g, '\\$&');
}
