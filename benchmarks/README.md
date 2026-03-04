# Benchmarks

Reproduzierbare HTTP-Benchmarks fuer atoll, WordPress, Kirby und Grav.

## Voraussetzungen

- Alle Testsysteme laufen lokal auf festen Ports:
  - atoll: `http://localhost:8080/`
  - WordPress: `http://localhost:8081/`
  - Kirby: `http://localhost:8082/`
  - Grav: `http://localhost:8083/`
- Gleiche Host-Maschine, gleiche Netzbedingungen, gleiche Seitenart (z. B. Home + Artikelseite).

Die Targets stehen in `benchmarks/targets.yaml`.

Vor dem Run Erreichbarkeit pruefen:

```bash
curl -s -o /dev/null -w 'atoll %{http_code}\n' http://localhost:8080/
curl -s -o /dev/null -w 'wp %{http_code}\n' http://localhost:8081/
curl -s -o /dev/null -w 'kirby %{http_code}\n' http://localhost:8082/
curl -s -o /dev/null -w 'grav %{http_code}\n' http://localhost:8083/
```

## Run

```bash
php bin/atoll benchmark:run --config=benchmarks/targets.yaml --rounds=3
```

Optional nur ein Ziel:

```bash
php bin/atoll benchmark:run --only=atoll-home --rounds=5
```

Optional mit strenger Fehlerschwelle (Standard: `5` Prozent):

```bash
php bin/atoll benchmark:run --max-error-rate=1
```

JSON-Output liegt unter `benchmarks/results/<timestamp>.json`.

## Markdown-Report

```bash
php bin/atoll benchmark:report --out=benchmarks/results/latest.md
```

Oder mit explizitem Input:

```bash
php bin/atoll benchmark:report \
  --input=benchmarks/results/20260304-120000.json \
  --out=benchmarks/results/20260304-120000.md
```

## Fairness-Regeln

- Vor jedem Run die Caches in allen Systemen warm laufen lassen.
- Keine aktiven Entwicklungs-Watcher parallel laufen lassen.
- Mehrere Runden ausfuehren (`--rounds=3` oder mehr).
- Ergebnisse mit denselben Requests/Concurrency-Werten vergleichen.
- atoll-Rate-Limit fuer Benchmarks ausreichend hoch setzen (sonst viele `429`):
  - `security.rate_limit.requests` in `config.yaml` temporaer deutlich erhoehen (z. B. `50000`) oder Benchmark-Last reduzieren.
