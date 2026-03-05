<script>
  import { toasts } from '../lib/stores.js';

  function dismiss(id) {
    toasts.update((t) => t.filter((x) => x.id !== id));
  }
</script>

{#if $toasts.length > 0}
  <div class="toast-container">
    {#each $toasts as toast (toast.id)}
      <div class="toast toast--{toast.type}" role="alert">
        <div class="toast-icon">
          {#if toast.type === 'success'}
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          {:else if toast.type === 'error'}
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
          {:else}
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
          {/if}
        </div>
        <span class="toast-msg">{toast.message}</span>
        <button class="toast-close" onclick={() => dismiss(toast.id)} aria-label="Schliessen">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
    {/each}
  </div>
{/if}

<style>
  .toast-container {
    position: fixed;
    top: 1rem;
    right: 1rem;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-width: 400px;
  }

  .toast {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-radius: 12px;
    border: 1px solid var(--line);
    background: var(--surface);
    color: var(--text);
    font-size: 0.9rem;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
    animation: slideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  }

  .toast--success { border-color: #22c55e40; }
  .toast--success .toast-icon { color: #22c55e; }
  .toast--error { border-color: #ef444440; }
  .toast--error .toast-icon { color: #ef4444; }
  .toast--info .toast-icon { color: var(--brand); }

  .toast-icon {
    flex-shrink: 0;
    display: flex;
  }

  .toast-msg {
    flex: 1;
  }

  .toast-close {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    background: transparent;
    border: none;
    border-radius: 6px;
    color: var(--muted);
    cursor: pointer;
    padding: 0;
    transition: background 0.15s, color 0.15s;
  }

  .toast-close:hover {
    background: var(--surface-2);
    color: var(--text);
  }

  @keyframes slideIn {
    from {
      opacity: 0;
      transform: translateX(20px);
    }
    to {
      opacity: 1;
      transform: translateX(0);
    }
  }
</style>
