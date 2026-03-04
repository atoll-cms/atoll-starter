# AstroPHP — Konzept: Ein modernes PHP-CMS mit Astro-Architekturprinzipien

## Vision

Ein PHP-natives CMS, das Astros Architekturprinzipien (Islands, Partial Hydration, Content Collections, File-based Routing) mit WordPresses Zugänglichkeit (Admin-Panel, Plugin-System, Shared-Hosting-Kompatibilität) vereint — ohne die technischen Schulden beider Systeme.

**Zielgruppe:** Freelancer und kleine Agenturen, die Kundenprojekte schnell, performant und wartbar umsetzen wollen — auf EU-Infrastruktur, ohne Node/Build-Pipeline, mit einem Admin-Panel das Kunden selbst bedienen können.

---

## Marktpositionierung

### Was existiert bereits

| System | Stärke | Schwäche |
|--------|--------|----------|
| **WordPress** | Ökosystem, Zugänglichkeit, Plugins | Performance, Sicherheit, technische Schulden, DB-Abhängigkeit |
| **Kirby** (€99/Site) | Saubere Architektur, Vue-Admin, Flat-File | Kein Island-Konzept, teuer bei vielen Sites, kleines Plugin-Ökosystem |
| **Statamic** (Laravel) | Mächtig, gut designt, Laravel-Basis | Komplex, Laravel-Overhead, $259/Site Pro |
| **Grav** | Open Source, Flat-File, Twig-Templates | Keine REST-API, veraltete Themes, Performance bei >1000 Seiten |
| **Astro** | Beste Performance, Island-Architektur | Kein Admin-Panel, Node-Abhängigkeit, Build-Pipeline nötig |

### Unsere Nische

**"Kirby-Eleganz + Astro-Performance + WordPress-Zugänglichkeit"** — zu einem fairen Preis, Open Source (Core), auf jedem PHP 8.2+ Hoster lauffähig.

---

## Architektur

### Kernprinzipien

1. **Zero JavaScript by Default** — Seiten werden als reines HTML ausgeliefert, kein JS-Bundle
2. **Island Architecture** — Interaktive Komponenten werden gezielt hydriert (Vue, Svelte, React, Vanilla)
3. **Flat-File First** — Content als Markdown/YAML, optional SQLite für strukturierte Daten
4. **PHP-nativ** — Kein Node, kein Build-Step, läuft auf jedem Shared Hosting mit PHP 8.2+
5. **Admin-Panel included** — Vue 3 SPA für Content-Bearbeitung, Media, Settings
6. **Plugin-System** — Hook-basiert, sowohl PHP-Plugins als auch Frontend-Islands

### Verzeichnisstruktur

```
mysite/
├── content/                    # Content Collections (Flat-File)
│   ├── pages/
│   │   ├── index.md
│   │   ├── about.md
│   │   └── contact.md
│   ├── blog/
│   │   ├── _collection.yaml   # Schema-Definition für diese Collection
│   │   ├── 2025-01-hello.md
│   │   └── 2025-02-world.md
│   └── data/
│       └── navigation.yaml
├── templates/                  # Twig-Templates (Astro-Layout-Prinzip)
│   ├── layouts/
│   │   ├── base.twig           # <html>, <head>, Islands-Loader
│   │   └── blog.twig
│   ├── components/
│   │   ├── header.twig
│   │   └── footer.twig
│   └── pages/
│       ├── index.twig          # File-based Routing: / 
│       ├── about.twig          # File-based Routing: /about
│       └── blog/
│           └── [slug].twig     # Dynamic Route: /blog/:slug
├── islands/                    # Interactive Components (JS)
│   ├── ContactForm.vue         # Hydriert nur wenn sichtbar
│   ├── ImageGallery.svelte
│   └── SearchWidget.js         # Vanilla JS Island
├── assets/
│   ├── css/
│   │   └── main.css
│   └── images/
├── plugins/                    # Plugin-Verzeichnis
│   ├── seo/
│   │   └── plugin.php
│   └── contact-form/
│       ├── plugin.php
│       └── islands/
│           └── ContactForm.vue
├── admin/                      # Admin-Panel (Vue 3 SPA, vorgebaut)
├── cache/                      # Generiertes HTML-Cache
├── config.yaml                 # Site-Konfiguration
└── index.php                   # Single Entry Point
```

### Request-Lifecycle

```
HTTP Request
    │
    ▼
index.php (Router)
    │
    ├─ Cache Hit? → Serve static HTML (< 5ms)
    │
    ├─ Route Resolution (File-based)
    │   ├─ /              → pages/index.twig + content/pages/index.md
    │   ├─ /blog/hello    → blog/[slug].twig + content/blog/2025-01-hello.md
    │   └─ /admin/*       → Admin SPA
    │
    ├─ Content Loading
    │   ├─ Parse Markdown (Frontmatter → YAML, Body → HTML)
    │   ├─ Load Collection Data
    │   └─ Run Plugin Hooks (beforeRender)
    │
    ├─ Template Rendering (Twig)
    │   ├─ Layout → Page → Components
    │   ├─ Island Placeholder: <astro-island component="ContactForm" client="visible" />
    │   └─ Static HTML Output
    │
    ├─ Island Processing
    │   ├─ Ersetze Placeholders durch <div data-island="ContactForm" data-hydrate="visible">
    │   ├─ Injiziere minimalen Island-Loader (< 2KB)
    │   └─ Referenziere vorgebaute JS-Bundles
    │
    ├─ Post-Processing
    │   ├─ Plugin Hooks (afterRender)
    │   ├─ HTML Minification
    │   └─ Cache Write
    │
    ▼
HTML Response (Zero JS oder minimal Island JS)
```

### Island-System im Detail

Im Template:
```twig
{# Statischer Content — kein JS #}
<h1>{{ page.title }}</h1>
<article>{{ page.content | raw }}</article>

{# Island — wird erst hydriert wenn sichtbar (IntersectionObserver) #}
{% island 'ContactForm' client='visible' props={ subject: 'Anfrage' } %}

{# Island — wird sofort hydriert #}
{% island 'SearchWidget' client='load' %}

{# Island — wird erst bei Interaktion hydriert (hover, click, focus) #}
{% island 'ImageGallery' client='idle' props={ images: page.gallery } %}

{# Island — wird nur auf dem Server gerendert, kein JS #}
{% island 'TableOfContents' client='none' props={ headings: page.headings } %}
```

Der Island-Loader (< 2KB vanilla JS) implementiert die Hydration-Strategien:
- `client:load` — Sofort beim DOMContentLoaded
- `client:visible` — IntersectionObserver, lädt wenn im Viewport
- `client:idle` — requestIdleCallback
- `client:media` — Media Query Match (z.B. nur Desktop)
- `client:none` — Nur Server-Rendering, kein JS

Islands werden als vorgebaute JS-Bundles ausgeliefert. Entwickler bauen sie lokal mit Vite (`npm run build:islands`) und committen die Bundles. Für Endnutzer sind sie fertige Pakete — kein Node auf dem Server nötig.

### Content Collections

`content/blog/_collection.yaml`:
```yaml
name: Blog
slug_from: filename          # 2025-01-hello.md → /blog/hello
sort: date desc
per_page: 10

schema:
  title:
    type: string
    required: true
  date:
    type: date
    required: true
  author:
    type: string
    default: "Admin"
  tags:
    type: list
    of: string
  featured_image:
    type: image
  excerpt:
    type: text
    max_length: 300
  draft:
    type: boolean
    default: false
```

Frontmatter in `2025-01-hello.md`:
```yaml
---
title: Hallo Welt
date: 2025-01-15
author: Torben
tags: [php, cms, launch]
featured_image: /assets/images/hello.jpg
excerpt: Unser erstes CMS-Posting.
---

Hier steht der **Markdown**-Content.
```

### Caching-Strategie

Das CMS generiert bei erstem Zugriff statisches HTML und cached es. Änderungen im Admin invalidieren gezielt nur betroffene Seiten. Ergebnis: Astro-ähnliche Performance (statisches HTML), aber ohne Build-Pipeline.

```
Schreib-Vorgang (Admin)
    │
    ├─ Content speichern (Markdown/YAML)
    ├─ Abhängigkeiten ermitteln (welche Seiten nutzen diesen Content?)
    ├─ Gezieltes Cache-Invalidieren
    └─ Optional: Eager Re-Rendering der wichtigsten Seiten
```

---

## Plugin-System

### Architektur

```php
// plugins/seo/plugin.php
return [
    'name' => 'SEO',
    'version' => '1.0.0',
    'hooks' => [
        'head:meta' => function ($page, $site) {
            return '<meta name="description" content="' . $page->excerpt() . '">';
        },
        'content:save' => function ($page) {
            // Sitemap neu generieren
        },
        'admin:menu' => function () {
            return ['label' => 'SEO', 'icon' => 'search', 'route' => '/admin/seo'];
        },
    ],
    'routes' => [
        '/sitemap.xml' => 'SitemapController@index',
    ],
    'islands' => [
        'SeoPreview' => 'islands/SeoPreview.vue',
    ],
    'admin_pages' => [
        'seo' => 'admin/seo.vue',
    ],
];
```

### Hook-System

| Hook | Beschreibung |
|------|-------------|
| `head:meta` | Meta-Tags in `<head>` injizieren |
| `head:scripts` | Scripts in `<head>` injizieren |
| `body:start` | Direkt nach `<body>` |
| `body:end` | Vor `</body>` |
| `content:before_parse` | Markdown vor dem Parsen modifizieren |
| `content:after_parse` | HTML nach dem Parsen modifizieren |
| `content:save` | Nach dem Speichern von Content |
| `content:delete` | Nach dem Löschen von Content |
| `page:before_render` | Vor dem Template-Rendering |
| `page:after_render` | Nach dem Template-Rendering |
| `admin:menu` | Admin-Menüpunkte hinzufügen |
| `admin:dashboard` | Dashboard-Widgets hinzufügen |
| `media:upload` | Nach dem Hochladen von Medien |
| `cache:clear` | Beim Cache-Invalidieren |
| `route:register` | Eigene Routen registrieren |
| `auth:login` | Nach dem Login |

---

## WordPress-Plugins → Eingebaute Features vs. Optionale Plugins

### Tier 1: Muss im Core sein (kein Plugin nötig)

Diese Funktionen werden von >90% aller Sites gebraucht. Als Plugin anzubieten ist sinnlos — sie gehören in den Kern.

| WordPress-Plugin | Aktive Installs | AstroPHP-Lösung |
|-----------------|-----------------|-----------------|
| **Yoast SEO / Rank Math** | 10M+ / 3M+ | **Eingebaut.** Meta-Tags, OpenGraph, JSON-LD, Sitemap.xml, Robots.txt — alles deklarativ in `_collection.yaml` und per Frontmatter. SEO-Score-Anzeige im Admin-Editor. Kein aufgeblähtes Plugin nötig, weil die Grundstruktur (sauberes HTML, schnelle Ladezeit, semantisches Markup) von Anfang an stimmt. |
| **WP Super Cache / W3 Total Cache** | 4M+ | **Eingebaut.** Das gesamte Caching-System *ist* der Core. Jede Seite wird als statisches HTML gecacht. Page-Speed ist kein Nachgedanke, sondern das Fundament. Kein Plugin nötig, weil es kein Problem gibt das gelöst werden muss. |
| **Wordfence / Solid Security** | 5M+ | **Eingebaut.** Rate Limiting, CSRF-Schutz, CSP-Headers, Admin-Login-Schutz (2FA, IP-Whitelist), automatische Security-Headers. Kein PHP-Code wird dynamisch ausgeführt (kein `eval`, keine DB-Injections möglich bei Flat-File). Die Angriffsfläche ist *strukturell* kleiner. |
| **Really Simple SSL** | 5M+ | **Eingebaut.** HTTPS-Redirect, HSTS-Header, Mixed-Content-Check — drei Zeilen in `config.yaml`. |
| **Akismet / CleanTalk (Anti-Spam)** | 5M+ | **Eingebaut.** Honeypot-Felder, Rate Limiting, optionaler CAPTCHA-Support. Kontaktformulare sind ein Core-Feature (siehe unten). |
| **Contact Form 7 / WPForms** | 10M+ | **Eingebaut.** Formulare sind YAML-deklarativ definiert, mit Validierung, CSRF, Honeypot, und Mail-Versand. Kein Drag-and-Drop-Builder nötig — die YAML-Syntax ist einfacher als jeder GUI-Builder. Admin-UI zeigt eingegangene Submissions. |
| **UpdraftPlus (Backup)** | 3M+ | **Eingebaut.** Ein-Klick-Backup im Admin (ZIP des `content/`-Ordners). Bei Flat-File *ist* Backup trivial — es ist ein Ordner-Kopieren. Git-Integration optional. Automatische tägliche Backups in konfigurierbares Ziel (lokal, S3, SFTP). |
| **Redirection** | 2M+ | **Eingebaut.** `redirects.yaml` mit 301/302-Regeln, Wildcard-Support, automatische Redirect-Erstellung wenn Slugs sich ändern. |
| **WP Mail SMTP** | 4M+ | **Eingebaut.** SMTP-Konfiguration in `config.yaml`. Unterstützt SMTP, Sendmail, und API-basierte Dienste (Postmark, Mailgun, SES). |
| **Image Optimization (Smush, ShortPixel)** | 2M+ | **Eingebaut.** Automatische WebP/AVIF-Konvertierung beim Upload, responsive `srcset`-Generierung, Lazy Loading nativ. PHP `imagick` oder `gd` — keine externe Abhängigkeit. |

### Tier 2: Offizielle Plugins (von uns gepflegt, ein Klick im Admin)

Diese Features brauchen viele, aber nicht alle Sites. Sie sind so gut integriert, dass sie sich wie Core anfühlen, aber optional bleiben.

| WordPress-Plugin | AstroPHP-Plugin | Beschreibung |
|-----------------|-----------------|-------------|
| **WooCommerce** | `shop` | Leichtgewichtiger E-Commerce: Produktseiten als Content Collection, Stripe/PayPal-Integration, Bestellverwaltung. Kein Full-Scale-ERP, aber ausreichend für 80% der kleinen Shops. |
| **Elementor / Beaver Builder** | `visual-editor` | Block-basierter visueller Editor im Admin-Panel. Kein Page Builder im WordPress-Sinne, sondern ein strukturierter Content-Block-Editor (ähnlich Notion/Kirby Blocks). Erzeugt sauberes Markdown, kein Shortcode-Chaos. |
| **WPML / Polylang** | `i18n` | Multi-Language-Support. Content-Ordner pro Sprache (`content/de/`, `content/en/`), URL-Prefixing, Language Switcher Island-Komponente, hreflang-Tags automatisch. |
| **MonsterInsights** | `analytics` | Datenschutzkonformes Analytics: Opt-in Cookie Banner, Plausible/Umami-Integration (self-hosted), oder Google Analytics mit Consent Mode. DSGVO-konform by default. |
| **Gravity Forms** | `forms-pro` | Erweiterte Formulare: Multi-Step, Conditional Logic, File Upload, Zahlungsintegration, Webhook-Support. Der Core deckt einfache Formulare ab, dieses Plugin die komplexen. |
| **MemberPress** | `members` | Mitgliederbereiche: Login/Registrierung, Rollen, Content-Zugriffskontrolle, optional Stripe-Abo. |
| **TablePress** | `tables` | Erweiterte Tabellen mit Sortierung, Filterung, CSV-Import. Als Island-Komponente implementiert — interaktiv, aber nur bei Bedarf hydriert. |
| **Bookly** | `booking` | Terminbuchung mit Kalender-UI, E-Mail-Benachrichtigungen, iCal-Export. |
| **MailPoet / FluentCRM** | `newsletter` | Newsletter-Versand mit Subscriber-Verwaltung, Double-Opt-In (DSGVO), Template-Editor, SMTP-Integration. |
| **ACF (Advanced Custom Fields)** | `custom-fields` | Erweiterte Feld-Typen für Content Collections: Repeater, Flexible Content, Beziehungen zwischen Collections. Im Admin als dynamische Formularfelder gerendert. |

### Tier 3: Community-Plugins (Ökosystem)

Hier wächst das Ökosystem organisch. Wir stellen die Plugin-API und ein Registry bereit.

| Kategorie | Beispiele |
|-----------|----------|
| **Social Media** | Auto-Posting zu Mastodon/Bluesky/LinkedIn, Social Share Buttons (als Island) |
| **Comments** | Giscus-Integration (GitHub Discussions), selbst-gehostete Kommentare |
| **Search** | Volltextsuche mit SQLite FTS5, Meilisearch-Integration, Algolia-Connector |
| **Maps** | OpenStreetMap-Islands (Leaflet), Google Maps |
| **Galleries** | Lightbox, Masonry Grid, Slider — alles als Island-Komponenten |
| **Legal/DSGVO** | Impressum-Generator, Datenschutzerklärung-Template, Cookie-Consent |
| **Deployment** | Git-Push-to-Deploy, FTP-Sync, Webhooks |
| **Themes** | Community-Themes mit Twig-Templates und CSS-Variablen |

---

## Admin-Panel

### Technologie

Vue 3 SPA, ausgeliefert als vorgebaute statische Assets (kein Node auf dem Server). Kommuniziert mit dem PHP-Backend über eine interne JSON-API.

### Screens

1. **Dashboard** — Site-Übersicht, letzte Änderungen, Quick Actions, Plugin-Widgets
2. **Content** — Collection-Browser (Blog, Pages, etc.), Inline-Bearbeitung, Markdown-Editor mit Live-Preview
3. **Media** — Upload, Bildbearbeitung (Crop, Resize), automatische Optimierung, WebP-Konvertierung
4. **Forms** — Eingegangene Submissions, Export als CSV
5. **SEO** — Seiten-Audit, Meta-Übersicht, Sitemap-Status
6. **Plugins** — Installierte Plugins, Ein-Klick-Aktivierung/Deaktivierung, Plugin-Registry-Browser
7. **Settings** — Site-Konfiguration, SMTP, Benutzer, Backup, Security
8. **Appearance** — Theme-Auswahl, CSS-Variablen anpassen, Logo/Favicon Upload

### Content-Editor

Markdown-basiert mit Block-Elementen:

```
┌─────────────────────────────────────────┐
│  Blog > "Hallo Welt"            [Draft] │
├─────────────────────────────────────────┤
│                                         │
│  Titel:    [Hallo Welt           ]      │
│  Slug:     [/blog/hallo-welt     ]      │
│  Datum:    [2025-01-15           ]      │
│  Tags:     [php] [cms] [+]             │
│  Bild:     [hello.jpg    ] [Ändern]     │
│                                         │
│  ─────────── Editor ──────────────      │
│  │ Hier steht der **Markdown**-   │     │
│  │ Content mit Live-Preview.      │     │
│  │                                │     │
│  │ {% island 'Gallery' %}         │     │
│  │                                │     │
│  └────────────────────────────────┘     │
│                                         │
│  ─────────── SEO Preview ─────────      │
│  │ Hallo Welt — Meine Site        │     │
│  │ meine-site.de/blog/hallo-welt  │     │
│  │ Unser erstes CMS-Posting...    │     │
│  └────────────────────────────────┘     │
│                                         │
│  [Entwurf speichern]  [Veröffentlichen] │
└─────────────────────────────────────────┘
```

---

## Technische Abhängigkeiten

### Server-Anforderungen

- PHP 8.2+ (mit `mbstring`, `json`, `gd` oder `imagick`)
- Kein Node.js
- Kein MySQL/PostgreSQL (Flat-File, optional SQLite)
- Apache mit `.htaccess` oder Nginx mit einfacher Config
- Shared Hosting kompatibel (Hetzner, Netcup, Strato, IONOS, All-Inkl, etc.)

### PHP-Bibliotheken (via Composer)

| Paket | Zweck |
|-------|-------|
| `twig/twig` | Template Engine |
| `league/commonmark` | Markdown Parsing |
| `symfony/yaml` | YAML Parsing |
| `symfony/routing` | URL Routing |
| `intervention/image` | Bildbearbeitung |
| `phpmailer/phpmailer` | E-Mail-Versand |
| `defuse/php-encryption` | Verschlüsselung (Backups, API-Keys) |

### Für Plugin-/Island-Entwickler (lokal)

- Node.js + Vite (nur für `npm run build:islands`)
- Vorgebaute Bundles werden committed — Endnutzer brauchen kein Node

---

## Abgrenzung & ehrliche Einschätzung

### Was AstroPHP NICHT ist

- **Kein WordPress-Killer.** Das Plugin-Ökosystem von WP ist unerreichbar. Wir zielen auf die Nische, die WP zu aufgebläht findet und Astro zu technisch.
- **Kein Page Builder.** Kein Drag-and-Drop im Elementor-Sinne. Wer das braucht, bleibt bei WordPress.
- **Kein Full-Scale-E-Commerce.** Das `shop`-Plugin ist für kleine Shops, nicht für Amazon-Klone.
- **Kein SaaS.** Self-hosted only — das ist ein Feature, kein Bug.

### Wettbewerbsvorteile

1. **Performance by Architecture** — Statisches HTML-Caching + Island-Hydration = Lighthouse 100 out of the box
2. **EU/DSGVO by Default** — Kein Cookie-Banner nötig wenn man keine externen Services nutzt. Analytics via Plausible/Umami.
3. **Shared Hosting kompatibel** — Kein Docker, kein Node, kein CI/CD. FTP-Upload und fertig.
4. **Sicherheit by Design** — Flat-File = keine SQL-Injection. Kein dynamisches PHP-Eval. Minimale Angriffsfläche.
5. **Developer Experience** — Twig-Templates, Markdown-Content, YAML-Config. Alles versionierbar, alles lesbar.

### Risiken

- **Ökosystem-Bootstrap:** Das größte Risiko. Ohne Plugins/Themes fehlt der Netzwerkeffekt. Mitigation: Genug Funktionalität im Core, sodass 80% der Sites keine Plugins brauchen.
- **Flat-File-Skalierung:** Ab ~10.000 Seiten wird Flat-File langsam. Mitigation: SQLite als optionaler Index, intelligentes Caching.
- **Island-DX:** Vorgebaute JS-Bundles committen ist ungewöhnlich. Mitigation: CLI-Tool (`astrophp islands build`), oder Islands nur für Plugin-Entwickler, Endnutzer bekommen fertige Komponenten.

---

## Roadmap (grob)

### Phase 1 — Foundation (8 Wochen)

- [ ] Router (File-based, Dynamic Routes)
- [ ] Twig-Template-Engine Integration
- [ ] Content Collections (Markdown + YAML Schema)
- [ ] Caching-System (Static HTML Cache + Invalidierung)
- [ ] Basic Admin-Panel (Login, Content-Liste, Markdown-Editor)
- [ ] CLI Tool (`astrophp new`, `astrophp serve`, `astrophp cache:clear`)

### Phase 2 — Core Features (6 Wochen)

- [ ] Island-System (Loader, Hydration-Strategien)
- [ ] SEO Core (Meta, OG, Sitemap, Robots)
- [ ] Kontaktformulare (YAML-definiert, CSRF, Honeypot, Mail)
- [ ] Media Manager (Upload, Resize, WebP, srcset)
- [ ] Backup-System (Ein-Klick-ZIP)
- [ ] Security (Rate Limiting, 2FA, CSP-Headers)
- [ ] SMTP-Konfiguration
- [ ] Redirect-System

### Phase 3 — Plugin-System & Ökosystem (6 Wochen)

- [ ] Hook-System
- [ ] Plugin-API + Loader
- [ ] Plugin-Registry (ähnlich Packagist/npm)
- [ ] Offizielle Plugins: i18n, analytics, forms-pro, shop
- [ ] Theme-System (CSS-Variablen, Layout-Overrides)
- [ ] Dokumentation (docs.astrophp.dev)

### Phase 4 — Polish & Launch (4 Wochen)

- [ ] Installer (Web-basiert, wie WordPress' 5-Minuten-Install)
- [ ] Migration-Tools (WordPress-Import, Kirby-Import)
- [ ] Starter-Themes (Blog, Portfolio, Business, Docs)
- [ ] Performance-Benchmarks vs. WordPress/Kirby/Grav
- [ ] Landing Page + Docs + Demo-Site
- [ ] Open Source Release (MIT-Lizenz für Core)

---

## Monetarisierung (optional)

| Modell | Beschreibung |
|--------|-------------|
| **Open Source Core (MIT)** | CMS-Kern kostenlos, Community-Plugins, Themes |
| **Pro Plugins** | Shop, Booking, Members, Newsletter — 9-19 €/Monat oder Einmalzahlung |
| **Managed Hosting** | Ein-Klick-Deploy auf EU-Servern (Hetzner), 5-15 €/Monat — ähnlich wie WordPress.com |
| **Support & Agentur-Lizenzen** | Priority Support, White-Label-Admin, Multi-Site-Management |
| **Marketplace-Provision** | 15-20% auf Community-Plugin-Verkäufe (wie Shopify, WP-Themes) |

---

## Nächste Schritte

1. **Name & Branding finalisieren** — "AstroPHP" ist Arbeitstitel, könnte Trademark-Probleme geben
2. **Proof of Concept** — Router + Twig + Markdown + Cache + Island-Loader als Minimal-Demo (2-3 Tage)
3. **Admin-Panel Prototype** — Vue 3 SPA mit Content-Editor und Media-Upload
4. **Eigene Site damit bauen** — Dogfooding als erstes reales Projekt
5. **Feedback von 3-5 Freelancern** — Validierung ob die DX und das Admin-Panel für Kundenprojekte taugen