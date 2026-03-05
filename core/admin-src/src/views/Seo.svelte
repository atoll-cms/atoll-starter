<script>
</script>

<div class="seo-view">
  <div class="page-header">
    <h1>SEO</h1>
  </div>

  <div class="seo-grid">
    <a href="/sitemap.xml" target="_blank" rel="noopener" class="seo-card">
      <div class="seo-icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
      </div>
      <h3>Sitemap</h3>
      <p>/sitemap.xml</p>
      <span class="link-arrow">Oeffnen &rarr;</span>
    </a>

    <a href="/robots.txt" target="_blank" rel="noopener" class="seo-card">
      <div class="seo-icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      </div>
      <h3>Robots</h3>
      <p>/robots.txt</p>
      <span class="link-arrow">Oeffnen &rarr;</span>
    </a>
  </div>
</div>

<style>
  .seo-view {
    max-width: 600px;
  }

  .page-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 1.5rem;
    letter-spacing: -0.02em;
  }

  .seo-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
  }

  .seo-card {
    display: flex;
    flex-direction: column;
    padding: 1.5rem;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    text-decoration: none;
    color: var(--text);
    transition: all 0.2s;
  }

  .seo-card:hover {
    border-color: var(--brand);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
  }

  .seo-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: rgba(59, 130, 246, 0.1);
    color: #3b82f6;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
  }

  .seo-card h3 {
    font-size: 1rem;
    font-weight: 600;
    margin: 0 0 0.25rem;
  }

  .seo-card p {
    color: var(--muted);
    font-size: 0.85rem;
    font-family: 'JetBrains Mono', monospace;
    margin: 0;
  }

  .link-arrow {
    margin-top: auto;
    padding-top: 1rem;
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--brand);
  }
</style>
