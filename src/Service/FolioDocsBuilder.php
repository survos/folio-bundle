<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

final readonly class FolioDocsBuilder
{
    /** @param array<string,mixed> $metadata */
    public function rebuild(string $dbFile, array $metadata = []): array
    {
        $pdo = $this->connect($dbFile);
        $folio = $pdo->query('SELECT code, label, dataset_key, row_count FROM folio LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($folio)) {
            return ['docs' => 0];
        }

        $cores = $pdo->query('SELECT code, label, row_count FROM core ORDER BY code')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $tables = $pdo->query("SELECT id, name, kind, core_code, dto_type, label, description, row_count FROM schema_table ORDER BY kind, name")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $properties = $pdo->query('SELECT table_id, name, label, description, type, stats, searchable, filterable, facet, sortable FROM schema_property ORDER BY table_id, position, name')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $views = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'view' AND name LIKE 'dto_%' ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $meta = [
            'folio' => $folio,
            'cores' => $cores,
            'dtoTables' => array_values(array_filter($tables, static fn (array $row): bool => ($row['kind'] ?? null) === 'dto')),
            'views' => array_map(static fn (array $row): string => (string) $row['name'], $views),
            'generatedAt' => gmdate(DATE_ATOM),
            'metadata' => $metadata,
        ];

        $docs = [
            ['meta', 'Folio Metadata', 'json', 'machine', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ['source' => 'folio']],
            ['overview', 'Overview', 'md', 'human', $this->overviewMarkdown($folio, $cores, $tables), ['source' => 'folio']],
            ['schema', 'Observed Schema', 'md', 'developer', $this->schemaMarkdown($tables, $properties), ['source' => 'schema_table/schema_property']],
            ['query-guide', 'Query Guide', 'md', 'developer', $this->queryGuideMarkdown($views), ['source' => 'sqlite']],
        ];

        $pdo->exec('DELETE FROM docs');
        $stmt = $pdo->prepare('INSERT INTO docs(id, folio_code, title, type, audience, body, metadata, position, generated_at) VALUES (:id, :folio, :title, :type, :audience, :body, :metadata, :position, :generatedAt)');
        foreach ($docs as $position => [$id, $title, $type, $audience, $body, $docMeta]) {
            $stmt->execute([
                'id' => $id,
                'folio' => (string) $folio['code'],
                'title' => $title,
                'type' => $type,
                'audience' => $audience,
                'body' => $body,
                'metadata' => json_encode($docMeta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'position' => $position,
                'generatedAt' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        return ['docs' => count($docs)];
    }

    private function overviewMarkdown(array $folio, array $cores, array $tables): string
    {
        $lines = [
            '# Folio ' . (string) $folio['code'],
            '',
            (string) ($folio['label'] ?: $folio['dataset_key'] ?: $folio['code']),
            '',
            sprintf('- Dataset key: `%s`', (string) ($folio['dataset_key'] ?? '')),
            sprintf('- Rows: %s', number_format((int) ($folio['row_count'] ?? 0))),
            sprintf('- Cores: %d', count($cores)),
            sprintf('- Observed DTO tables: %d', count(array_filter($tables, static fn (array $row): bool => ($row['kind'] ?? null) === 'dto'))),
            '',
            '## Cores',
            '',
            '| Core | Label | Rows |',
            '| --- | --- | ---: |',
        ];
        foreach ($cores as $core) {
            $lines[] = sprintf('| `%s` | %s | %s |', (string) $core['code'], (string) ($core['label'] ?: $core['code']), number_format((int) ($core['row_count'] ?? 0)));
        }

        return implode("\n", $lines) . "\n";
    }

    private function schemaMarkdown(array $tables, array $properties): string
    {
        $byTable = [];
        foreach ($properties as $property) {
            $byTable[(string) $property['table_id']][] = $property;
        }

        $lines = ['# Observed Schema', ''];
        foreach ($tables as $table) {
            if (($table['kind'] ?? null) !== 'dto') {
                continue;
            }
            $lines[] = sprintf('## `%s`', (string) $table['name']);
            $lines[] = '';
            $lines[] = sprintf('- Core: `%s`', (string) $table['core_code']);
            $lines[] = sprintf('- DTO type: `%s`', (string) $table['dto_type']);
            $lines[] = sprintf('- Rows: %s', number_format((int) ($table['row_count'] ?? 0)));
            if (is_string($table['description'] ?? null) && $table['description'] !== '') {
                $lines[] = '';
                $lines[] = (string) $table['description'];
            }
            $lines[] = '';
            $lines[] = '| Field | Type | Flags | Description |';
            $lines[] = '| --- | --- | --- | --- |';
            foreach ($byTable[(string) $table['id']] ?? [] as $property) {
                $flags = [];
                foreach (['searchable', 'filterable', 'facet', 'sortable'] as $flag) {
                    if ((bool) ($property[$flag] ?? false)) {
                        $flags[] = $flag;
                    }
                }
                $lines[] = sprintf('| `%s` | `%s` | %s | %s |', (string) $property['name'], (string) ($property['type'] ?? ''), $flags ? implode(', ', $flags) : '', $this->markdownCell((string) ($property['description'] ?? '')));
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function queryGuideMarkdown(array $views): string
    {
        $lines = [
            '# Query Guide',
            '',
            'This folio stores canonical rows in `item` and observed field metadata in `schema_table` / `schema_property`.',
            '',
            '```sql',
            "select * from schema_table where kind = 'dto';",
            'select * from schema_property where table_id = ? order by position;',
            'select local_id, label, dto_type, dto_data, extras from item limit 20;',
            '```',
            '',
            'Convenience views are generated from observed DTO fields:',
            '',
        ];
        foreach ($views as $view) {
            $name = (string) ($view['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $lines[] = '```sql';
            $lines[] = sprintf('select * from %s limit 20;', $name);
            $lines[] = '```';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function connect(string $dbFile): \PDO
    {
        if (!is_file($dbFile)) {
            throw new \RuntimeException(sprintf('Folio database not found: %s', $dbFile));
        }

        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function markdownCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], trim($value));
    }
}
