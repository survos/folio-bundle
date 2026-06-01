<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Attribute\{ClassMeta,PropertyMeta,VocabTerm};
use Survos\DataContracts\Vocabulary\{ItemField,MuseumVocab};
use Survos\FieldBundle\Attribute\Field;
use Survos\JsonlBundle\Service\JsonlProfilerInterface;

final readonly class FolioSchemaSnapshotter
{
    public function __construct(
        private FolioDtoTypeResolver $dtoTypeResolver,
        private JsonlProfilerInterface $profiler,
    ) {
    }

    /**
     * @return array{tables:int,properties:int}
     */
    public function snapshot(string $dbFile): array
    {
        $pdo = $this->connect($dbFile);
        $pdo->exec('DELETE FROM schema_property');
        $pdo->exec('DELETE FROM schema_table');

        $folioCode = (string) $pdo->query('SELECT code FROM folio LIMIT 1')->fetchColumn();
        if ($folioCode === '') {
            return ['tables' => 0, 'properties' => 0];
        }

        $tables = 0;
        $properties = 0;

        foreach ($pdo->query('SELECT id, code, label, row_count, field_summary FROM core ORDER BY code')->fetchAll(\PDO::FETCH_ASSOC) as $core) {
            $coreCode = (string) $core['code'];
            $coreTableId = $folioCode . ':row_' . $coreCode;
            $this->insertTable($pdo, $coreTableId, $folioCode, $coreCode, 'row_' . $coreCode, 'core', null, null, $core['label'] !== null ? (string) $core['label'] : null, null, (int) $core['row_count']);
            $tables++;

            foreach ($this->fieldSummary($core['field_summary'] ?? null) as $i => $field) {
                $properties += $this->insertProperty($pdo, $coreTableId, $field, $i, true, false, false, false);
            }

            $dtoRows = $pdo->prepare('SELECT dto_type, COUNT(*) AS row_count FROM item WHERE core_id = :coreId GROUP BY dto_type ORDER BY dto_type');
            $dtoRows->execute(['coreId' => $core['id']]);
            foreach ($dtoRows->fetchAll(\PDO::FETCH_ASSOC) as $dtoRow) {
                $dtoType = is_string($dtoRow['dto_type'] ?? null) && $dtoRow['dto_type'] !== '' ? (string) $dtoRow['dto_type'] : 'row';
                $dtoClass = $this->dtoTypeResolver->classForType($dtoType);
                $tableName = $this->viewName($coreCode, $dtoType);
                $tableId = $folioCode . ':' . $tableName;
                $classMeta = $this->classMetadata($dtoClass);

                $this->insertTable(
                    $pdo,
                    $tableId,
                    $folioCode,
                    $coreCode,
                    $tableName,
                    'dto',
                    $dtoType,
                    $dtoClass,
                    $classMeta['label'] ?? $this->dtoTypeResolver->labelForType($dtoType),
                    $classMeta['description'] ?? null,
                    (int) $dtoRow['row_count'],
                );
                $tables++;

                $rows = $this->dtoDataRows($pdo, (string) $core['id'], $dtoType);
                $fieldStats = $this->profiler->profile($rows);
                $metadata = $this->dtoPropertyMetadata($dtoType);
                $position = 0;
                foreach ($fieldStats as $name => $stats) {
                    if (!is_string($name) || $name === '' || !$this->observed($stats)) {
                        continue;
                    }
                    $property = $metadata[$name] ?? [];
                    $properties += $this->insertProperty(
                        $pdo,
                        $tableId,
                        $name,
                        $position++,
                        (bool) ($property['searchable'] ?? in_array($name, ['id', 'label', 'title', 'description', 'summary', ItemField::SEARCH_SUMMARY], true)),
                        (bool) ($property['filterable'] ?? false),
                        (bool) ($property['facet'] ?? false),
                        (bool) ($property['sortable'] ?? false),
                        (bool) ($property['visible'] ?? true),
                        (string) ($property['type'] ?? $stats['storageHint'] ?? $this->statsType($stats)),
                        $property['label'] ?? null,
                        $property['description'] ?? null,
                        $property['declaringClass'] ?? null,
                        is_array($stats) ? $stats : null,
                    );
                }
            }
        }

        // Term sets are facets by definition: any property named after a #[VocabTerm(termSet: true)]
        // MuseumVocab code (cul, med, pla, dept, …) is exposed as a filterable facet, even though it is
        // observed data rather than a typed DTO property. (Runtime, not the compile-time MeiliIndexPass —
        // that only sees DTO-declared fields on Doctrine-entity indexes, never a folio's observed fields.)
        $termSetCodes = $this->termSetCodes();
        if ($termSetCodes !== []) {
            $placeholders = implode(',', array_fill(0, count($termSetCodes), '?'));
            $pdo->prepare("UPDATE schema_property SET facet = 1, filterable = 1 WHERE name IN ($placeholders)")
                ->execute($termSetCodes);
        }

        return ['tables' => $tables, 'properties' => $properties];
    }

    /** @return list<string> MuseumVocab codes flagged #[VocabTerm(termSet: true)] — always facets. */
    private function termSetCodes(): array
    {
        $codes = [];
        foreach ((new \ReflectionClass(MuseumVocab::class))->getReflectionConstants() as $const) {
            foreach ($const->getAttributes(VocabTerm::class) as $attribute) {
                if ($attribute->newInstance()->termSet) {
                    $codes[] = (string) $const->getValue();
                }
            }
        }

        return $codes;
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

    private function insertTable(\PDO $pdo, string $id, string $folioCode, string $coreCode, string $name, string $kind, ?string $dtoType, ?string $dtoClass, ?string $label, ?string $description, int $rowCount): void
    {
        $stmt = $pdo->prepare('INSERT INTO schema_table(id, folio_code, core_code, name, kind, dto_type, dto_class, label, description, row_count) VALUES (:id, :folio, :core, :name, :kind, :dto, :class, :label, :description, :rows)');
        $stmt->execute([
            'id' => $id,
            'folio' => $folioCode,
            'core' => $coreCode,
            'name' => $name,
            'kind' => $kind,
            'dto' => $dtoType,
            'class' => $dtoClass,
            'label' => $label,
            'description' => $description,
            'rows' => $rowCount,
        ]);
    }

    private function insertProperty(\PDO $pdo, string $tableId, string $name, int $position, bool $searchable, bool $filterable, bool $facet, bool $sortable, bool $visible = true, ?string $type = null, ?string $label = null, ?string $description = null, ?string $declaringClass = null, ?array $stats = null): int
    {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO schema_property(id, table_id, name, label, description, type, declaring_class, stats, position, visible, searchable, filterable, facet, sortable) VALUES (:id, :tableId, :name, :label, :description, :type, :declaringClass, :stats, :position, :visible, :searchable, :filterable, :facet, :sortable)');
        $stmt->execute([
            'id' => $tableId . ':' . $name,
            'tableId' => $tableId,
            'name' => $name,
            'label' => $label ?? $this->labelize($name),
            'description' => $description,
            'type' => $type,
            'declaringClass' => $declaringClass,
            'stats' => $stats === null ? null : json_encode($stats, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'position' => $position,
            'visible' => $visible ? 1 : 0,
            'searchable' => $searchable ? 1 : 0,
            'filterable' => $filterable ? 1 : 0,
            'facet' => $facet ? 1 : 0,
            'sortable' => $sortable ? 1 : 0,
        ]);

        return $stmt->rowCount() > 0 ? 1 : 0;
    }

    /** @return list<string> */
    private function fieldSummary(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        try {
            $fields = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return array_values(array_filter($fields, 'is_string'));
    }

    /** @return iterable<array<string,mixed>> */
    private function dtoDataRows(\PDO $pdo, string $coreId, string $dtoType): iterable
    {
        $stmt = $pdo->prepare('SELECT dto_data FROM item WHERE core_id = :coreId AND dto_type = :dtoType ORDER BY rowid');
        $stmt->execute(['coreId' => $coreId, 'dtoType' => $dtoType]);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $decoded = $this->decodeJson($row['dto_data'] ?? null);
            if (is_array($decoded)) {
                yield $decoded;
            }
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function dtoPropertyMetadata(string $dtoType): array
    {
        $class = $this->dtoTypeResolver->classForType($dtoType);
        if (!$class || !class_exists($class)) {
            return [];
        }

        $properties = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $fieldAttribute = $property->getAttributes(Field::class)[0] ?? null;
            $field = $fieldAttribute ? $fieldAttribute->newInstance() : null;
            $propertyMetaAttribute = $property->getAttributes(PropertyMeta::class)[0] ?? null;
            $propertyMeta = $propertyMetaAttribute ? $propertyMetaAttribute->newInstance() : null;
            $name = $property->getName();
            $properties[$name] = [
                'name' => $name,
                'label' => $propertyMeta instanceof PropertyMeta ? $propertyMeta->label : null,
                'description' => $propertyMeta instanceof PropertyMeta && $propertyMeta->description !== null ? $propertyMeta->description : $this->docComment($property->getDocComment() ?: null),
                'type' => $this->typeName($property->getType()),
                'declaringClass' => $property->getDeclaringClass()->getName(),
                'visible' => $field instanceof Field ? $field->visible : true,
                'searchable' => ($field instanceof Field && $field->searchable) || ($propertyMeta instanceof PropertyMeta && $propertyMeta->searchable),
                'filterable' => ($field instanceof Field && $field->filterable) || ($propertyMeta instanceof PropertyMeta && $propertyMeta->facet),
                'facet' => ($field instanceof Field && $field->facet) || ($propertyMeta instanceof PropertyMeta && $propertyMeta->facet),
                'sortable' => ($field instanceof Field && $field->sortable) || ($propertyMeta instanceof PropertyMeta && $propertyMeta->sortable),
            ];
        }

        return $properties;
    }

    /** @return array{label?:string,description?:string} */
    private function classMetadata(?string $class): array
    {
        if ($class === null || !class_exists($class)) {
            return [];
        }

        $reflection = new \ReflectionClass($class);
        $classMetaAttribute = $reflection->getAttributes(ClassMeta::class)[0] ?? null;
        $classMeta = $classMetaAttribute ? $classMetaAttribute->newInstance() : null;

        return array_filter([
            'label' => $classMeta instanceof ClassMeta ? $classMeta->label : null,
            'description' => $classMeta instanceof ClassMeta && $classMeta->description !== null ? $classMeta->description : $this->docComment($reflection->getDocComment() ?: null),
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    private function observed(mixed $stats): bool
    {
        return is_array($stats) && (int) ($stats['total'] ?? 0) > (int) ($stats['nulls'] ?? 0);
    }

    private function statsType(array $stats): ?string
    {
        $types = $stats['types'] ?? [];
        return is_array($types) && $types !== [] ? implode('|', array_map('strval', $types)) : null;
    }

    private function decodeJson(mixed $json): mixed
    {
        if (!is_string($json) || trim($json) === '') {
            return null;
        }

        try {
            return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function viewName(string $coreCode, string $dtoType): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_]+/', '_', strtolower($dtoType)) ?: 'row';
        $core = preg_replace('/[^A-Za-z0-9_]+/', '_', strtolower($coreCode)) ?: 'core';

        return $coreCode === 'obj' ? 'dto_' . $slug : 'dto_' . $core . '_' . $slug;
    }

    private function typeName(?\ReflectionType $type): ?string
    {
        if ($type instanceof \ReflectionNamedType) {
            return ($type->allowsNull() && $type->getName() !== 'null' ? '?' : '') . $type->getName();
        }

        if ($type instanceof \ReflectionUnionType) {
            return implode('|', array_map(fn (\ReflectionType $inner): string => $this->typeName($inner) ?? 'mixed', $type->getTypes()));
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return implode('&', array_map(fn (\ReflectionType $inner): string => $this->typeName($inner) ?? 'mixed', $type->getTypes()));
        }

        return null;
    }

    private function docComment(?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }

        $lines = preg_split('/\R/', $comment) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/^\s*\/?\*+\s?|\s*\*\/\s*$/', '', $line));
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }
            $clean[] = $line;
        }

        return $clean === [] ? null : implode("\n", $clean);
    }

    private function labelize(string $name): string
    {
        return ucwords((string) preg_replace('/[_-]+/', ' ', $name));
    }
}
