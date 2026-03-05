# tables Plugin

Interaktive Tabellen fuer atoll-cms:

- Sortierung pro Spalte (Klick auf Tabellenkopf)
- Volltext-Filter ueber alle Zeilen
- Pagination mit konfigurierbarer `pageSize`
- CSV-Parse-Hilfsroute (`POST /tables/parse-csv`)

Beispiel in Twig:

```twig
<table class="js-demo-table">...</table>
{{ island('TableEnhancer', {
  client: 'visible',
  props: {
    selector: '.js-demo-table',
    pageSize: 12
  }
}) }}
```
