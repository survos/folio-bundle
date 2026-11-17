<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Vocabulary\PeriodicalVocabulary;
use Survos\FolioBundle\Entity\Core;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Entity\Row;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Map the DuckDB representation to the existing folio presentation model, without an EntityManager. */
final readonly class DuckDbFolioView
{
    public function __construct(private DuckDbFolioConnection $connection, private FolioDtoTypeResolver $types)
    {
    }

    /** Core counts come from the same manifestation searched by the browse page. */
    public function search(string $folioCode, ?string $coreCode = null, ?string $dtoType = null): array
    {
        $db = $this->connection->connect(['folioCode' => $folioCode]);
        $folio = new Folio($folioCode);
        $cores = [];
        foreach ($db->query("SELECT split_part(core_id, ':', 2) AS code, count(*) AS total FROM item GROUP BY core_id ORDER BY code")->fetchAll(\PDO::FETCH_ASSOC) as $record) {
            $core = new Core($folio, $record['code']);
            $core->rowCount = (int) $record['total'];
            $cores[$core->code] = $core;
        }
        if (null !== $coreCode && !isset($cores[$coreCode])) {
            throw new NotFoundHttpException(sprintf('Unknown folio core "%s".', $coreCode));
        }

        return ['folio' => $folio, 'cores' => $cores, 'selectedCore' => $coreCode, 'selectedDtoType' => $dtoType, 'reader' => 'duckdb'];
    }

    /** PDO decodes native JSON columns; exported VARCHAR columns still contain JSON text. */
    private static function normalizedData(mixed $value): array
    {
        $data = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING) : $value;
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Folio normalized data must be a JSON object or array.');
        }

        return $data;
    }

    /**
     * A newspaper record's issue id. DocumentDto declares issueId, so an article has it in dto_data; a folio that
     * has it only in extras must be rebuilt. A group row is scoped by its issue: the issue is in its id.
     */
    public static function issueIdOf(array $record): string
    {
        if (1 === preg_match('~^[^:]+:'.PeriodicalVocabulary::GROUP_CORE.':(.+)$~D', (string) ($record['id'] ?? ''), $group)) {
            return PeriodicalVocabulary::issueOfGroup($group[1]);
        }
        $id = self::normalizedData($record['dto_data'] ?? '{}')['issueId'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new \UnexpectedValueException(sprintf('Record %s has no issueId in dto_data. Rebuild this folio.', $record['id'] ?? '?'));
        }

        return $id;
    }

    public function item(string $folioCode, string $coreCode, string $localId): array
    {
        $db = $this->connection->connect(['folioCode' => $folioCode]);
        // Without the key this lookup scans the id column of the whole folio. Refuse the file instead of serving it slowly.
        if (!(bool) $db->query("SELECT count(*) FROM duckdb_constraints() WHERE schema_name = 'main' AND table_name = 'item' AND constraint_type = 'PRIMARY KEY'")->fetchColumn()) {
            throw new \RuntimeException(sprintf('The dfolio of %s has no primary key on item. Export it again.', $folioCode));
        }
        $statement = $db->prepare('SELECT '.DuckDbFolioRowMapper::columns().' FROM item WHERE id = ?');
        $statement->execute([Row::id($folioCode.':'.$coreCode, $localId)]);
        $record = $statement->fetch(\PDO::FETCH_ASSOC);
        if (false === $record) {
            throw new NotFoundHttpException('Item not found in this folio working copy.');
        }
        $pageRows = DuckDbFolioRowMapper::pages($record);
        unset($record['pages']);
        $split = (bool) $db->query("SELECT count(*) FROM information_schema.tables WHERE table_name = '_renditions'")->fetchColumn();
        if ($split) {
            $issueId = 'doc' === $coreCode ? $localId : self::issueIdOf($record);
            [$record, $pageRows] = $this->connection->restoreNewspaper($db, $folioCode, $issueId, $record, $pageRows);
        }
        $folio = new Folio($folioCode);
        $core = new Core($folio, $coreCode);
        $row = DuckDbFolioRowMapper::hydrate($folioCode, $coreCode, $record, $pageRows);
        $dtoClass = $this->types->classForType($row->dtoType);
        $dto = $dtoClass::fromNormalized($row->dtoData);

        $facets = [];
        foreach (\Survos\FolioBundle\Search\FolioDuckDbSearch::declaredFields($db) as $field) {
            $value = $row->dtoData[$field['name']] ?? null;
            if (null === $value || '' === $value || [] === $value) {
                continue;
            }
            $field['values'] = is_array($value) ? $value : [$value];
            $facets[] = $field;
        }

        return [
            'facets' => $facets,
            'folio' => $folio, 'core' => $core, 'row' => $row, 'dto' => $dto, 'dtoClass' => $dtoClass,
            'ctx' => (object) ['folioCode' => $folioCode], 'reader' => 'duckdb',
            'dtoJson' => $record['dto_data'],
            'pageTableExists' => true,
            // These ancillary SQLite-reader panels are not loaded by the DuckDB detail view.
            'claims' => [], 'aiTaskRuns' => [], 'terms' => [], 'termFields' => [],
            'links' => [], 'extras' => $row->extras, 'adjacent' => null,
            'hasUxMap' => class_exists(\Symfony\UX\Map\Map::class),
            'pages' => array_map(static fn (array $page): array => ['url' => $page['url'], 'sourceUrl' => $page['source_url'],
                'width' => $page['width'], 'height' => $page['height'],
                'text' => $page['text'], 'layout' => null === $page['layout'] ? null : self::normalizedData($page['layout']),
                'htr' => $page['htr']], $pageRows),
        ];
    }
}
