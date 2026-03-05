<script>
  import { modalState } from '../lib/stores.js';
</script>

{#if $modalState.open}
  <div class="modal-overlay" onclick={$modalState.onCancel} role="presentation">
    <!-- svelte-ignore a11y_interactive_supports_focus a11y_click_events_have_key_events -->
    <div class="modal" onclick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-labelledby="modal-title" tabindex="-1">
      <h3 id="modal-title">{$modalState.title}</h3>
      <p>{$modalState.message}</p>
      <div class="modal-actions">
        <button class="btn-cancel" onclick={$modalState.onCancel}>Abbrechen</button>
        <button class="btn-confirm" onclick={$modalState.onConfirm}>Bestaetigen</button>
      </div>
    </div>
  </div>
{/if}

<style>
  .modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 900;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.15s ease;
  }

  .modal {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 1.5rem;
    max-width: 440px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
    animation: scaleIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
  }

  .modal h3 {
    margin: 0 0 0.5rem;
    font-size: 1.1rem;
    font-weight: 600;
  }

  .modal p {
    color: var(--muted);
    margin: 0 0 1.5rem;
    font-size: 0.9rem;
    line-height: 1.5;
  }

  .modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
  }

  .btn-cancel,
  .btn-confirm {
    padding: 0.5rem 1rem;
    border-radius: 8px;
    border: 1px solid var(--line);
    font: inherit;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
  }

  .btn-cancel {
    background: transparent;
    color: var(--muted);
  }

  .btn-cancel:hover {
    background: var(--surface-2);
    color: var(--text);
  }

  .btn-confirm {
    background: #ef4444;
    border-color: #ef4444;
    color: #fff;
  }

  .btn-confirm:hover {
    background: #dc2626;
  }

  @keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
  }

  @keyframes scaleIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
  }
</style>
