<script>
  import { user, csrf, security } from '../lib/stores.js';

  let username = $state('');
  let password = $state('');
  let otp = $state('');
  let error = $state('');
  let loading = $state(false);

  async function handleLogin(event) {
    event.preventDefault();
    error = '';
    loading = true;

    try {
      const response = await fetch('/admin/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ username, password, otp: otp || undefined })
      });

      const result = await response.json();

      if (!result.ok) throw new Error(result.error || 'Login fehlgeschlagen');

      user.set(result.user);
      csrf.set(result.csrf);
      security.update((s) => ({ ...s, twofaEnabled: !!result?.security?.twofa_enabled }));
    } catch (err) {
      error = err.message;
    } finally {
      loading = false;
    }
  }
</script>

<div class="login-page">
  <div class="login-ambient"></div>
  <div class="login-card">
    <div class="login-header">
      <div class="login-logo">
        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"/>
          <circle cx="12" cy="12" r="4"/>
          <line x1="4.93" y1="4.93" x2="9.17" y2="9.17"/>
          <line x1="14.83" y1="14.83" x2="19.07" y2="19.07"/>
          <line x1="14.83" y1="9.17" x2="19.07" y2="4.93"/>
          <line x1="4.93" y1="19.07" x2="9.17" y2="14.83"/>
        </svg>
      </div>
      <h1>atoll admin</h1>
      <p class="login-subtitle">Melde dich an, um fortzufahren</p>
    </div>

    <form onsubmit={handleLogin}>
      <div class="field">
        <label for="username">Benutzername</label>
        <input id="username" type="text" bind:value={username} required autocomplete="username" placeholder="admin">
      </div>

      <div class="field">
        <label for="password">Passwort</label>
        <input id="password" type="password" bind:value={password} required autocomplete="current-password" placeholder="Passwort eingeben">
      </div>

      <div class="field">
        <label for="otp">2FA Code <span class="optional">(optional)</span></label>
        <input id="otp" type="text" bind:value={otp} inputmode="numeric" pattern="[0-9]{'{'}6{'}'}" placeholder="123456" autocomplete="one-time-code">
      </div>

      {#if error}
        <div class="error-msg" role="alert">{error}</div>
      {/if}

      <button type="submit" class="login-btn" disabled={loading}>
        {#if loading}
          <span class="spinner"></span>
        {:else}
          Einloggen
        {/if}
      </button>
    </form>
  </div>
</div>

<style>
  .login-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
  }

  .login-ambient {
    position: absolute;
    inset: 0;
    background:
      radial-gradient(ellipse 60% 50% at 20% 20%, rgba(245, 158, 11, 0.08), transparent),
      radial-gradient(ellipse 40% 40% at 80% 80%, rgba(245, 158, 11, 0.05), transparent);
    pointer-events: none;
  }

  .login-card {
    position: relative;
    width: 100%;
    max-width: 400px;
    margin: 1rem;
    padding: 2.5rem 2rem;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 20px;
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.3);
  }

  .login-header {
    text-align: center;
    margin-bottom: 2rem;
  }

  .login-logo {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--brand), #fbbf24);
    color: #1a1a1a;
    margin-bottom: 1rem;
  }

  .login-header h1 {
    font-size: 1.4rem;
    font-weight: 700;
    margin: 0 0 0.25rem;
    letter-spacing: -0.03em;
  }

  .login-subtitle {
    color: var(--muted);
    font-size: 0.9rem;
    margin: 0;
  }

  .field {
    margin-bottom: 1.25rem;
  }

  .field label {
    display: block;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--muted);
    margin-bottom: 0.4rem;
  }

  .optional {
    font-weight: 400;
    text-transform: none;
    letter-spacing: 0;
  }

  .field input {
    width: 100%;
    padding: 0.7rem 0.9rem;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 10px;
    color: var(--text);
    font: inherit;
    font-size: 0.95rem;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  .field input:focus {
    outline: none;
    border-color: var(--brand);
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
  }

  .error-msg {
    padding: 0.6rem 0.8rem;
    margin-bottom: 1rem;
    border-radius: 8px;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.25);
    color: #fca5a5;
    font-size: 0.85rem;
  }

  .login-btn {
    width: 100%;
    padding: 0.75rem;
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--brand), #d97706);
    color: #1a1a1a;
    font: inherit;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    transition: opacity 0.15s, transform 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
  }

  .login-btn:hover:not(:disabled) {
    opacity: 0.9;
    transform: translateY(-1px);
  }

  .login-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .spinner {
    width: 20px;
    height: 20px;
    border: 2px solid transparent;
    border-top-color: currentColor;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
  }

  @keyframes spin {
    to { transform: rotate(360deg); }
  }
</style>
