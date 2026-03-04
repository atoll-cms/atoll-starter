export function mount(el, props = {}) {
  const form = document.createElement('form');
  form.className = 'contact-form';
  form.method = 'post';
  form.action = props.endpoint || '/forms/contact';

  form.innerHTML = `
    <input type="hidden" name="_csrf" value="${props.csrf || ''}">
    <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
    <label>Name <input name="name" required></label>
    <label>E-Mail <input name="email" type="email" required></label>
    <label>Nachricht <textarea name="message" rows="5" required></textarea></label>
    <button type="submit">Senden</button>
    <p class="status" role="status"></p>
  `;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const data = new FormData(form);
    const payload = Object.fromEntries(data.entries());

    const response = await fetch(form.action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const result = await response.json();
    const status = form.querySelector('.status');
    if (result.ok) {
      status.textContent = result.message || 'Danke, Nachricht gesendet.';
      form.reset();
    } else {
      status.textContent = result.error || 'Senden fehlgeschlagen.';
    }
  });

  el.appendChild(form);
}
