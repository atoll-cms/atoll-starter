# atoll-cms

atoll-cms ist ein modernes Flat-File-CMS auf PHP 8.2+, das Astro-Prinzipien (Islands, Partial Hydration, file-based routing) mit WordPress-artiger Bedienbarkeit kombiniert.

## Verbindliches Konzept

- **Source of Truth:** [docs/ATOLL_CONCEPT_ORIGINAL.md](docs/ATOLL_CONCEPT_ORIGINAL.md)

## Architektur: Core vs Site

Der austauschbare Unterbau liegt in `core/`.
Projekt-/kundenspezifische Inhalte bleiben ausserhalb davon.

`core/` (updatable):
- Runtime (`core/src`)
- Admin SPA (`core/admin`)
- Built-in Fallback Theme (`core/themes/default`)
- Island-Bundles (`core/islands`)

Offizielle Themes (separate Repos):
- `atoll-theme-skeleton`
- `atoll-theme-business`
- `atoll-theme-editorial`
- `atoll-theme-portfolio`

Site-Ebene (stabil bei Core-Updates):
- `content/`
- `plugins/`
- `themes/`
- `templates/` (höchste Override-Ebene)
- `config.yaml`
- `assets/uploads` und eigene statische Dateien

## Override-Konzept (Hugo-ähnlich)

Template-Auflösung:
1. `templates/` (site-level hard override)
2. `themes/<active-theme>/templates/`
3. `core/themes/<active-theme>/templates/`
4. `core/themes/default/templates/` (Fallback)

Theme-Asset-Auflösung (`theme_asset()`):
1. `themes/<active-theme>/assets/...`
2. `core/themes/<active-theme>/assets/...`
3. `core/themes/default/assets/...`

## Quickstart

1. Abhaengigkeiten installieren:

```bash
composer install
```

2. Development Server starten:

```bash
php bin/atoll dev 8080
```

Alternativen:
- `php bin/atoll dev 8080` startet Watch-Mode fuer Frontend-Bundles (z. B. `core/admin-src`, aktive Theme-/Plugin-`islands-src`) und den PHP-Server.
- `php bin/atoll serve 8080` baut Frontend-Bundles einmalig und startet dann den PHP-Server.
- `php bin/atoll dev:local 8080 --activate=business` nutzt das lokale Core-Sibling-Repo (`../atoll-core`) und startet danach den Dev-Server.
- In `dev:local` werden Registry-Installationen von Themes/Plugins fuer diesen laufenden Prozess bevorzugt als lokale Symlinks auf `../atoll-theme-<id>` bzw. `../atoll-plugin-<id>` angelegt (Live-Aenderungen ohne Reinstall). `dev` bleibt Registry-only.
- `composer dev-local -- --setup-only` setzt nur den lokalen Core-Pfad (ohne Serverstart).
- In `environment: dev` ist HTML-Cache standardmaessig deaktiviert, damit Theme-/Template-Aenderungen sofort sichtbar sind. Optional wieder aktivierbar mit `cache.dev_enabled: true`.

3. Frontend/Admin:
- [http://localhost:8080](http://localhost:8080)
- [http://localhost:8080/admin](http://localhost:8080/admin)
- Default Login: `admin` / `admin123`

## Remote-Update-Kanal

Updater-Konfiguration in `config.yaml`:

```yaml
updater:
  channel: stable
  manifest_url: https://raw.githubusercontent.com/atoll-cms/atoll-updates/main/channels/stable.json
  public_key: config/updater-public.pem
  require_signature: true
  timeout_seconds: 15
```

- Manifest-Format: [docs/updater/manifest.example.json](docs/updater/manifest.example.json)
- Public-Key-Beispiel: [config/updater-public.pem.example](config/updater-public.pem.example)

Remote prüfen/aktualisieren:

```bash
php bin/atoll core:check
php bin/atoll core:update:remote
```

Signaturprüfung:
- RSA/SHA-256 über `signature_payload`
- zusätzlich SHA-256-Prüfung des ZIP-Artefakts
- Update bricht bei Verifikationsfehlern ab

## Rollback-Strategie

Automatisch:
- Bei fehlgeschlagenem Core-Swap oder Migrationsfehler wird der Core sofort zurückgerollt.

Manuell:

```bash
php bin/atoll core:rollback
php bin/atoll core:rollback --from-backup=/path/core-backup.zip
php bin/atoll core:rollback --from-dir=/path/to/old-core --keep-current
```

Hinweis:
- Rollback betrifft den Core-Code.
- Content-/Daten-Rollback ist getrennt zu betrachten (Backups von `content/`).

## Semantic Migrations

- Migrationen liegen in `core/migrations/*.php`.
- Sie laufen automatisch nach erfolgreichem Core-Swap.
- Ausführungszustand wird in `content/data/core-migrations.yaml` gespeichert.
- Historie von Updates/Rollbacks liegt in `content/data/core-updates.yaml`.

Manuell ausführen:

```bash
php bin/atoll core:migrate --from=0.1.0 --to=0.2.0
```

## Release-Tools (Maintainer)

Core-Release bauen:

```bash
php core/tools/build-release.php
```

Release signieren:

```bash
php core/tools/sign-release.php \
  --private-key=/path/release-private.pem \
  --version=0.2.0 \
  --sha256=<artifact_sha256>
```

## Migration-Tools

WordPress WXR import:

```bash
php core/tools/migrate-wordpress.php --wxr=/path/export.xml --output=content/blog --type=post
```

Kirby content import:

```bash
php core/tools/migrate-kirby.php --source=/path/kirby/content --output=content
```

## Performance-Benchmarks

Benchmark-Targets und Regeln liegen unter:

- `benchmarks/targets.yaml`
- `benchmarks/README.md`

JSON-Benchmark laufen lassen:

```bash
php bin/atoll benchmark:run --config=benchmarks/targets.yaml --rounds=3
```

Markdown-Report erzeugen:

```bash
php bin/atoll benchmark:report --out=benchmarks/results/latest.md
```

## Plugins und Themes

Plugin installieren:

```bash
php bin/atoll plugin:install /pfad/zum/plugin --enable
php bin/atoll plugin:install:registry i18n --enable
php bin/atoll plugin:install:registry booking-pro --enable --license=YOUR_KEY
php bin/atoll plugin:list
```

Theme installieren:

```bash
php bin/atoll theme:install /pfad/zum/theme
php bin/atoll theme:install:registry business
php bin/atoll theme:install:registry studio-pro --license=YOUR_KEY
php bin/atoll theme:install:registry editorial
php bin/atoll theme:install:registry portfolio
php bin/atoll theme:list
php bin/atoll theme:activate business
```

Marketplace-Lizenzen werden unter `content/data/licenses.yaml` gespeichert.

Preset-Content passend zum Theme anwenden:

```bash
php bin/atoll preset:list
php bin/atoll preset:apply business
# optional mit Ueberschreiben
php bin/atoll preset:apply editorial --force
```

## Eigene Plugins

Ein Plugin ist ein Ordner unter `plugins/<id>/` mit `plugin.php`:

```php
<?php
return [
  'name' => 'My Plugin',
  'version' => '1.0.0',
  'hooks' => [
    'head:meta' => static fn () => '<meta name="x-plugin" content="my-plugin">',
  ],
  'routes' => [
    '/my-plugin/health' => static fn () => ['ok' => true],
  ],
  'islands' => [
    'MyIsland' => 'islands/MyIsland.js',
  ],
];
```

Aktivierung über:
- Admin-Panel (`Plugins`)
- oder `content/data/plugins.yaml`

## Eigene Themes

Ein Theme besteht in atoll aus:
- `assets/main.css` (Look & Feel)
- optionalen `templates/`-Overrides (Layouts/Pages/Components)

Theme-Struktur:

```text
themes/my-theme/
├── templates/
│   ├── layouts/
│   ├── components/
│   └── pages/
└── assets/
    └── main.css
```

Aktives Theme in `config.yaml`:

```yaml
appearance:
  theme: my-theme
```

## CLI (voll)

```bash
php bin/atoll help
php bin/atoll cache:clear
php bin/atoll core:status
php bin/atoll core:check
php bin/atoll core:update /path/to/release
php bin/atoll core:update:remote
php bin/atoll core:rollback
php bin/atoll core:migrate
php bin/atoll plugin:list
php bin/atoll plugin:install /path/to/plugin --enable
php bin/atoll plugin:install:registry i18n --enable
php bin/atoll plugin:install:registry booking-pro --enable --license=YOUR_KEY
php bin/atoll theme:list
php bin/atoll theme:activate business
php bin/atoll theme:install /path/to/theme
php bin/atoll theme:install:registry business
php bin/atoll theme:install:registry studio-pro --license=YOUR_KEY
php bin/atoll preset:list
php bin/atoll preset:apply business
php bin/atoll benchmark:run --rounds=3
php bin/atoll benchmark:report --out=benchmarks/results/latest.md
```

## Installer

Wenn `config.yaml` noch nicht existiert, leitet atoll automatisch auf `/install` um.
Dort werden Site-Basisdaten und ein sicherer Admin-Account angelegt.

## Security

- Session-Haertung (HttpOnly, SameSite, Session-Rotation)
- Admin-IP-Allowlist (`security.admin_ip_allowlist`)
- Passwort-Policy (`security.password.*`)
- TOTP-2FA im Admin (pro User)
- Audit-Log unter `content/data/security-audit.jsonl`
