<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

final readonly class FolioViewBuilder
{
    /** @return array{views:int} */
    public function rebuild(string $dbFile): array
    {
        $pdo = $this->connect($dbFile);
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'view' AND name LIKE 'dto_%'")->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $view) {
            if (is_string($view) && preg_match('/^dto_[A-Za-z0-9_]+$/', $view)) {
                $pdo->exec('DROP VIEW IF EXISTS ' . $this->identifier($view));
            }
        }

        $tables = $pdo->query("SELECT id, name, core_code, dto_type FROM schema_table WHERE kind = 'dto' ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $views = 0;
        foreach ($tables as $table) {
            $name = (string) ($table['name'] ?? '');
            $dtoType = (string) ($table['dto_type'] ?? '');
            $coreCode = (string) ($table['core_code'] ?? '');
            if (!preg_match('/^dto_[A-Za-z0-9_]+$/', $name) || $dtoType === '' || $coreCode === '') {
                continue;
            }

            $props = $pdo->prepare('SELECT name, description FROM schema_property WHERE table_id = :tableId AND visible = 1 ORDER BY position, name');
            $props->execute(['tableId' => $table['id']]);
            $columns = [
                "local_id",
                "label",
                "dto_type",
                "substr(core_id, instr(core_id, ':') + 1) AS core_code",
            ];
            foreach ($props->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $prop) {
                $property = (string) ($prop['name'] ?? '');
                $desc = (string) ($prop['description'] ?? '');
                if ($property === '' || $property === 'id') {
                    continue;
                }
                $col = sprintf(
                    "json_extract(dto_data, %s) AS %s",
                    $this->literal('$.' . $property),
                    $this->identifier($property),
                );
                if ($desc !== '') {
                    $col = '--' . str_replace("\n", ' ', $desc) . "\n    " . $col;
                }
                $columns[] = $col;
            }

            $sql = sprintf(
                "CREATE VIEW %s AS\nSELECT\n    %s\nFROM item\nWHERE dto_type = %s\n  AND substr(core_id, instr(core_id, ':') + 1) = %s",
                $this->identifier($name),
                implode(",\n    ", $columns),
                $this->literal($dtoType),
                $this->literal($coreCode),
            );
            $pdo->exec($sql);
            $views++;
        }

        return ['views' => $views];
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

    private function identifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
