<?php

declare(strict_types=1);

namespace Survos\FolioBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use Survos\FolioBundle\Entity\Core;
use Survos\FolioBundle\Entity\Row;
use Survos\FolioBundle\Service\DuckDbFolioConnection;
use Survos\FolioBundle\Service\DuckDbFolioRowMapper;
use Survos\ImageContracts\ImageUrlBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/** Rows of a folio for the browser grid and for one item, read from its dfolio. */
final class FolioRowProvider implements ProviderInterface
{
    public function __construct(
        private readonly DuckDbFolioConnection $connection,
        private readonly RequestStack $requestStack,
        private readonly ?ImageUrlBuilderInterface $imageUrls = null,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable|object|null
    {
        $request = $this->requestStack->getCurrentRequest() ?? throw new \LogicException('Folio rows require a request.');
        $folioCode = $request->attributes->get('folioCode');
        if (!is_string($folioCode)) {
            throw new \LogicException('Missing resolved folioCode.');
        }
        $coreId = Core::id($folioCode, (string) $request->query->get('core', 'obj'));
        $db = $this->connection->connect(['folioCode' => $folioCode]);
        $select = 'SELECT '.DuckDbFolioRowMapper::columns().' FROM item WHERE ';

        if (isset($uriVariables['localId']) && is_string($uriVariables['localId'])) {
            $statement = $db->prepare($select.'id = ?');
            $statement->execute([Row::id($coreId, $uriVariables['localId'])]);
            $record = $statement->fetch(\PDO::FETCH_ASSOC);
            if (false === $record) {
                return null;
            }
            $row = DuckDbFolioRowMapper::fromRecord($record);
            $this->resolveThumbnails([$row]);

            return $row;
        }

        $filters = $context['filters'] ?? [];
        $where = ['core_id = ?'];
        $params = [$coreId];
        $dtoType = $filters['dtoType'] ?? $filters['dto'] ?? null;
        if (is_string($dtoType) && '' !== $dtoType) {
            $where[] = 'dto_type = ?';
            $params[] = $dtoType;
        }
        // >=, not =: the timeline ruler seeks to a year and continues in the same year-ascending order.
        if (isset($filters['yearMin']) && is_numeric($filters['yearMin'])) {
            $where[] = 'sort_key >= ?';
            $params[] = (int) $filters['yearMin'];
        }
        // One value or several (recordKind[]=...). DocumentDto declares recordKind, so it is in dto_data.
        $recordKinds = array_values(array_filter(array_map(
            static fn (mixed $kind): string => is_string($kind) ? trim($kind) : '',
            (array) ($filters['recordKind'] ?? []),
        ), static fn (string $kind): bool => '' !== $kind));
        if ([] !== $recordKinds) {
            $where[] = 'CAST(dto_data."recordKind" AS VARCHAR) IN ('.implode(', ', array_fill(0, count($recordKinds), '?')).')';
            array_push($params, ...$recordKinds);
        }
        // A plain substring match on the label and the document text; the search page has the real search.
        if (isset($filters['q']) && is_string($filters['q']) && '' !== trim($filters['q'])) {
            $where[] = '(contains(lower(label), lower(?)) OR contains(lower(CAST(CAST(dto_data AS JSON) AS VARCHAR)), lower(?)))';
            array_push($params, trim($filters['q']), trim($filters['q']));
        }
        $whereSql = implode(' AND ', $where);

        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = max(1, (int) $request->query->get('itemsPerPage', 50));
        $count = $db->prepare('SELECT count(*) FROM item WHERE '.$whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        // Year order with undated rows last: sort_key is the row's year, a real column.
        $statement = $db->prepare($select.$whereSql.' ORDER BY sort_key ASC NULLS LAST, local_id ASC LIMIT '.$itemsPerPage.' OFFSET '.(($page - 1) * $itemsPerPage));
        $statement->execute($params);
        $results = array_map(DuckDbFolioRowMapper::fromRecord(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
        $this->resolveThumbnails($results);

        return new TraversablePaginator(new \ArrayIterator($results), $page, $itemsPerPage, $total);
    }

    /**
     * @param Row[] $rows
     */
    private function resolveThumbnails(array $rows): void
    {
        if (null === $this->imageUrls) {
            return;
        }

        foreach ($rows as $row) {
            $source = $row->getDisplaySource();
            if ($source) {
                $row->setResolvedThumbnailUrl($this->imageUrls->preset($source, 'thumb'));
            }
        }
    }
}
