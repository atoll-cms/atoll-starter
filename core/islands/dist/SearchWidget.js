export function mount(el, props = {}) {
  const input = document.createElement('input');
  input.type = 'search';
  input.placeholder = props.placeholder || 'Suche...';
  input.className = 'island-input';

  const hint = document.createElement('small');
  hint.textContent = 'Demo-Island: integriere hier spaeter FTS/Meilisearch.';

  el.appendChild(input);
  el.appendChild(hint);
}
