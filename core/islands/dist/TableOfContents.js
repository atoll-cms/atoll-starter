export function mount(el, props = {}) {
  const list = document.createElement('ul');
  const headings = Array.isArray(props.headings) ? props.headings : [];

  headings.forEach((heading) => {
    const li = document.createElement('li');
    li.textContent = typeof heading === 'string' ? heading : String(heading?.title || '');
    list.appendChild(li);
  });

  if (!headings.length) {
    list.innerHTML = '<li>Keine Gliederung vorhanden.</li>';
  }

  el.appendChild(list);
}
