<?php

declare(strict_types=1);

use Atoll\Http\Request;
use Atoll\Http\Response;

$parseCsv = static function (string $csv, string $delimiter = ',', int $maxRows = 500): array {
    $delimiter = trim($delimiter);
    if ($delimiter === '') {
        $delimiter = ',';
    }
    $delimiter = substr($delimiter, 0, 1);
    if ($delimiter === false || $delimiter === '') {
        $delimiter = ',';
    }

    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        return [
            'headers' => [],
            'rows' => [],
            'row_count' => 0,
        ];
    }

    fwrite($stream, $csv);
    rewind($stream);

    $headers = [];
    $rows = [];
    $lineNumber = 0;
    $maxRows = max(1, min(2000, $maxRows));

    while (($record = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
        $lineNumber++;
        if ($record === [null] || $record === []) {
            continue;
        }

        if ($headers === []) {
            foreach ($record as $index => $value) {
                $label = trim((string) $value);
                if ($label === '') {
                    $label = 'col_' . ($index + 1);
                }
                $headers[] = $label;
            }
            continue;
        }

        if (count($record) > count($headers)) {
            for ($i = count($headers); $i < count($record); $i++) {
                $headers[] = 'col_' . ($i + 1);
            }
        }

        $row = [];
        foreach ($headers as $index => $header) {
            $row[$header] = trim((string) ($record[$index] ?? ''));
        }
        $rows[] = $row;

        if (count($rows) >= $maxRows) {
            break;
        }
    }

    fclose($stream);

    return [
        'headers' => $headers,
        'rows' => $rows,
        'row_count' => count($rows),
        'truncated' => count($rows) >= $maxRows,
        'parsed_lines' => $lineNumber,
    ];
};

return [
    'name' => 'tables',
    'description' => 'Interactive tables with sorting, filtering, pagination and CSV parsing helper.',
    'version' => '0.1.0',
    'hooks' => [
        'admin:dashboard' => static fn (): array => [
            'id' => 'tables-plugin',
            'title' => 'Tables Plugin',
            'value' => 'active',
            'text' => 'Sortierbare und filterbare Tabellen als Island plus CSV-Helper-Route.',
        ],
    ],
    'routes' => [
        '/tables/health' => static fn (): array => [
            'ok' => true,
            'plugin' => 'tables',
            'features' => ['island', 'sorting', 'filtering', 'pagination', 'csv_parse'],
        ],
        '/tables/parse-csv' => static function (Request $request) use ($parseCsv): Response {
            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)
                    ->withHeader('Allow', 'POST');
            }

            $payload = $request->isJson() ? $request->json() : array_merge($request->query, $request->post);
            $csv = trim((string) ($payload['csv'] ?? ''));
            if ($csv === '') {
                return Response::json(['ok' => false, 'error' => 'Missing "csv" payload'], 422);
            }

            $delimiter = (string) ($payload['delimiter'] ?? ',');
            $maxRows = (int) ($payload['max_rows'] ?? 500);

            return Response::json([
                'ok' => true,
                'result' => $parseCsv($csv, $delimiter, $maxRows),
            ]);
        },
    ],
    'islands' => [
        'TableEnhancer' => 'islands/TableEnhancer.js',
    ],
];
