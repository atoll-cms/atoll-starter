# Benchmarks

Reproduzierbare HTTP-Benchmarks fuer atoll, WordPress, Kirby und Grav.

## Voraussetzungen

- Alle Testsysteme laufen lokal auf festen Ports:
  - atoll: `http://127.0.0.1:8080/`
  - WordPress: `http://127.0.0.1:8081/`
  - Kirby: `http://127.0.0.1:8082/`
  - Grav: `http://127.0.0.1:8083/`
- Gleiche Host-Maschine, gleiche Netzbedingungen, gleiche Seitenart (z. B. Home + Artikelseite).

Die Targets stehen in `benchmarks/targets.yaml`.

## Run

```bash
php bin/atoll benchmark:run --config=benchmarks/targets.yaml --rounds=3
```

Optional nur ein Ziel:

```bash
php bin/atoll benchmark:run --only=atoll-home --rounds=5
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
