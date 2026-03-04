export function mount(el, props = {}) {
  const box = document.createElement('div');
  box.innerHTML = `
    <strong>SEO Preview</strong>
    <p>${props.title || 'Untitled'} - ${props.site || 'Site'}</p>
    <small>${props.description || ''}</small>
  `;
  el.appendChild(box);
}
