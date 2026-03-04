# atoll-starter

Template repository for new atoll-cms projects.

Use this repository as **GitHub Template** to start a new site quickly.

## Included

- runnable project scaffold (`index.php`, `config.yaml`, `content/`, `plugins/`, `themes/`)
- pinned core snapshot in `core/`
- updater/rollback CLI via `bin/atoll`

## Related repositories

- Core runtime: `atoll-cms/atoll-core`
- Documentation: `atoll-cms/atoll-docs`
- Website: `atoll-cms/atoll-website`
- Update manifests: `atoll-cms/atoll-updates`

## Quickstart

```bash
composer install
php bin/atoll serve 8080
```

Open:
- frontend: http://localhost:8080
- admin: http://localhost:8080/admin

Default login:
- `admin` / `admin123`

## Update flow

```bash
php bin/atoll core:status
php bin/atoll core:check
php bin/atoll core:update:remote
php bin/atoll core:rollback
```
