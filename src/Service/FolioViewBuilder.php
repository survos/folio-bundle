<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

final readonly class FolioViewBuilder
{
    /** @return array{views:int,linkViews:int} */
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

        $linkViews = $this->rebuildLinkViews($pdo);

        return ['views' => $views, 'linkViews' => $linkViews];
    }

    /**
     * One view per LinkType (e.g. `link_has_stop`), pre-joining both sides of the relationship --
     * the same "a view exists so consumers never hand-write the join" convention as the dto_*
     * views above, applied to relationships instead of single rows. Without this, "all stops for
     * story X, in order" needs a 4-table join with json_extract() on both the row and the link's
     * own extras; with it, it's `SELECT * FROM link_has_stop WHERE left_local_id = ? ORDER BY
     * ordinal`. Column names are generic ("left_" / "right_" prefixes, matching LinkType's own
     * left/right naming) since this runs for every LinkType, not just has_stop.
     */
    private function rebuildLinkViews(\PDO $pdo): int
    {
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'view' AND name LIKE 'link_%'")->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $view) {
            if (is_string($view) && preg_match('/^link_[A-Za-z0-9_]+$/', $view)) {
                $pdo->exec('DROP VIEW IF EXISTS ' . $this->identifier($view));
            }
        }

        $linkTypes = $pdo->query('SELECT code, left_core, right_core FROM link_type')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $count = 0;
        foreach ($linkTypes as $lt) {
            $code = (string) ($lt['code'] ?? '');
            $leftCore = (string) ($lt['left_core'] ?? '');
            $rightCore = (string) ($lt['right_core'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]+$/', $code) || $leftCore === '' || $rightCore === '') {
                continue;
            }

            $columns = array_merge(
                ['l.left_id AS left_local_id', 'left_item.label AS left_label'],
                $this->corePropertyColumns($pdo, $leftCore, 'left', 'left_item'),
                ['l.right_id AS right_local_id', 'right_item.label AS right_label'],
                $this->corePropertyColumns($pdo, $rightCore, 'right', 'right_item'),
                // Ordinal is common enough across relationships (position within an ordered
                // parent) to flatten unconditionally -- null and harmless for link types that
                // don't use it. Anything else in extras is still reachable via link_extras.
                ["json_extract(l.extras, '\$.ordinal') AS ordinal", 'l.extras AS link_extras'],
            );

            $sql = sprintf(
                "CREATE VIEW %s AS\nSELECT\n    %s\nFROM link l\nJOIN core left_core ON left_core.code = l.left_core\nJOIN item left_item ON left_item.core_id = left_core.id AND left_item.local_id = l.left_id\nJOIN core right_core ON right_core.code = l.right_core\nJOIN item right_item ON right_item.core_id = right_core.id AND right_item.local_id = l.right_id\nWHERE l.left_core = %s AND l.right_core = %s",
                $this->identifier('link_' . $code),
                implode(",\n    ", $columns),
                $this->literal($leftCore),
                $this->literal($rightCore),
            );
            $pdo->exec($sql);
            $count++;
        }

        return $count;
    }

    /** @return list<string> */
    private function corePropertyColumns(\PDO $pdo, string $coreCode, string $side, string $itemAlias): array
    {
        // A core can host more than one dto_type in principle; this takes the first dto-kind
        // schema_table row for the core, same limitation the dto_* views themselves don't have to
        // face (they're keyed by the exact dto_type already). Fine for today's real cores (one
        // dto_type per core in practice); a genuinely mixed core would need a real per-row type
        // dispatch, not addressed here.
        $stmt = $pdo->prepare("SELECT id FROM schema_table WHERE kind = 'dto' AND core_code = :core LIMIT 1");
        $stmt->execute(['core' => $coreCode]);
        $tableId = $stmt->fetchColumn();
        if ($tableId === false) {
            return [];
        }

        $props = $pdo->prepare('SELECT name FROM schema_property WHERE table_id = :tableId AND visible = 1 ORDER BY position, name');
        $props->execute(['tableId' => $tableId]);

        $columns = [];
        foreach ($props->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $name) {
            if (!is_string($name) || $name === '' || $name === 'id') {
                continue;
            }
            $columns[] = sprintf(
                'json_extract(%s.dto_data, %s) AS %s',
                $itemAlias,
                $this->literal('$.' . $name),
                $this->identifier($side . '_' . $name),
            );
        }

        return $columns;
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
