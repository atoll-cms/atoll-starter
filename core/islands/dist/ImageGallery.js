export function mount(el, props = {}) {
  const images = Array.isArray(props.images) ? props.images : [];

  const wrapper = document.createElement('div');
  wrapper.className = 'gallery';

  images.forEach((src, index) => {
    const img = document.createElement('img');
    img.loading = 'lazy';
    img.src = src;
    img.alt = `Gallery image ${index + 1}`;
    wrapper.appendChild(img);
  });

  if (!images.length) {
    wrapper.textContent = 'Keine Bilder vorhanden.';
  }

  el.appendChild(wrapper);
}
