<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

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

    /** PDO decodes native JSON columns; exported VARCHAR columns still contain JSON text. */
    private static function normalizedData(mixed $value): array
    {
        $data = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING) : $value;
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Folio normalized data must be a JSON object or array.');
        }

        return $data;
    }

    public function item(string $folioCode, string $coreCode, string $localId): array
    {
        $db = $this->connection->connect(['folioCode' => $folioCode]);
        $statement = $db->prepare('SELECT *, CAST(dto_data AS VARCHAR) AS dto_json FROM item WHERE core_id = ? AND local_id = ?');
        $statement->execute([$folioCode.':'.$coreCode, $localId]);
        $record = $statement->fetch(\PDO::FETCH_ASSOC);
        if (false === $record) {
            throw new NotFoundHttpException('Item not found in this folio working copy.');
        }
        $pagesQuery = $db->prepare('SELECT * FROM page WHERE row_id = ? ORDER BY seq, id');
        $pagesQuery->execute([$record['id']]);
        $pageRows = $pagesQuery->fetchAll(\PDO::FETCH_ASSOC);
        $split = (bool) $db->query("SELECT count(*) FROM information_schema.tables WHERE table_name = '_renditions'")->fetchColumn();
        if ($split) {
            $issueId = 'doc' === $coreCode ? $localId : self::normalizedData($record['extras'])['issueId'];
            [$record, $pageRows] = $this->connection->restoreNewspaper($db, $folioCode, $issueId, $record, $pageRows);
        }
        $folio = new Folio($folioCode);
        $core = new Core($folio, $coreCode);
        $row = new Row($core, $record['local_id']);
        $row->label = $record['label'];
        $row->dtoType = $record['dto_type'];
        $row->dtoData = self::normalizedData($record['dto_data']);
        $row->extras = null === $record['extras'] ? null : self::normalizedData($record['extras']);
        if ($split) {
            $row = DuckDbFolioRowMapper::hydrate($folioCode, $coreCode, $record, $pageRows);
        }
        $dtoClass = $this->types->classForType($row->dtoType);
        $dto = $dtoClass::fromNormalized($row->dtoData);

        $facets = [];
        foreach (\Survos\FolioBundle\Search\FolioDuckDbSearch::declaredFacets($db) as $field) {
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
            'dtoJson' => $record['dto_json'],
            'pages' => array_map(static fn (array $page): array => ['url' => $page['url'], 'sourceUrl' => $page['source_url'],
                'width' => $page['width'], 'height' => $page['height']], $pageRows),
        ];
    }
}
