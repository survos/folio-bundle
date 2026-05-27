<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Survos\FolioBundle\Attribute\FolioContext;
use Survos\FolioBundle\Entity\{Core,Doc,Folio,Link,Row};
use Survos\FolioBundle\Service\{FolioChatPromptSuggester,FolioChatService,FolioDtoTypeResolver,FolioService,FolioWordCloudService};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

#[Route('/folio')]
#[FolioContext]
final class FolioController extends AbstractController
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioDtoTypeResolver $dtoTypeResolver,
        private readonly FolioChatService $chat,
        private readonly FolioChatPromptSuggester $promptSuggester,
        private readonly FolioWordCloudService $wordCloud,
        private readonly ?ChartBuilderInterface $chartBuilder = null,
    ) {}


    #[Route('/{provider}/{dataset}', name: 'survos_folio_show')]
    public function show(string $provider, string $dataset): Response
    {
        $ctx = $this->folios->context("$provider/$dataset");
        $cores = $ctx->em->getRepository(Core::class)->findBy([], ['code' => 'ASC']);
        $objectCore = $this->objectCore($cores);
        $dtoChoices = $objectCore ? $this->dtoChoices($ctx->em->getConnection()->executeQuery(
            'SELECT dto_type, COUNT(*) AS cnt FROM item WHERE core_id = ? GROUP BY dto_type ORDER BY cnt DESC',
            [$objectCore->id],
        )->fetchAllAssociative()) : [];

        $schemaTables = $ctx->em->getConnection()->executeQuery(
            "SELECT name, kind, core_code, dto_type, label, row_count FROM schema_table ORDER BY kind, name"
        )->fetchAllAssociative();
        $views = $ctx->em->getConnection()->executeQuery(
            "SELECT name FROM sqlite_master WHERE type = 'view' AND name LIKE 'dto_%' ORDER BY name"
        )->fetchFirstColumn();
        $folio = $ctx->em->find(Folio::class, $ctx->folioCode);

        return $this->render('@SurvosFolioBundle/folio/show.html.twig', [
            'ctx'              => $ctx,
            'cores'            => $cores,
            'objectCore'       => $objectCore,
            'supportingCores'  => array_values(array_filter($cores, static fn (Core $core): bool => $objectCore === null || $core->id !== $objectCore->id)),
            'dtoChoices'       => $dtoChoices,
            'dtoBreakdownChart' => $this->dtoBreakdownChart($dtoChoices),
            'docs'             => $ctx->em->getRepository(Doc::class)->findBy([], ['position' => 'ASC']),
            'schemaTables'     => $schemaTables,
            'views'            => $views,
            'linkTypes'        => $folio?->linkTypes ?? [],
        ]);
    }

    #[Route('/{provider}/{dataset}/schema', name: 'survos_folio_schema')]
    public function schema(string $provider, string $dataset): Response
    {
        $ctx = $this->folios->context("$provider/$dataset");
        $conn = $ctx->em->getConnection();
        $sm = $conn->createSchemaManager();

        $tables = [];
        foreach ($sm->listTableNames() as $tableName) {
            try {
                $table = $sm->introspectTable($tableName);
            } catch (\Throwable) {
                continue;
            }

            $indexes = [];
            foreach ($table->getIndexes() as $idx) {
                if ($idx->isPrimary()) {
                    continue;
                }
                $indexes[] = [
                    'name' => $idx->getName(),
                    'columns' => implode(', ', $idx->getColumns()),
                    'unique' => $idx->isUnique() ? 'yes' : '',
                ];
            }

            $tables[] = [
                'name' => $table->getName(),
                'indexes' => $indexes,
            ];
        }

        $ddl = [];
        $nativeConn = $conn->getNativeConnection();
        \assert($nativeConn instanceof \PDO);
        foreach ($nativeConn->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $ddl[(string) $row['name']] = $this->formatDdl((string) $row['sql']);
        }

        $views = [];
        foreach ($nativeConn->query("SELECT name, sql FROM sqlite_master WHERE type = 'view' ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $views[] = ['name' => (string) $row['name'], 'sql' => (string) $row['sql']];
        }

        return $this->render('@SurvosFolioBundle/folio/schema.html.twig', [
            'ctx' => $ctx,
            'tables' => $tables,
            'ddl' => $ddl,
            'views' => $views,
        ]);
    }

    #[Route('/{provider}/{dataset}/chat', name: 'survos_folio_chat')]
    public function chat(string $provider, string $dataset, Request $request): Response
    {
        $ctx = $this->folios->context("$provider/$dataset");
        $question = trim($request->request->getString('q', $request->query->getString('q')));
        $coreCode = $request->request->getString('core', $request->query->getString('core')) ?: null;
        $dtoType = $request->request->getString('dtoType', $request->query->getString('dtoType')) ?: null;
        $limit = max(1, min(50, $request->request->getInt('limit', $request->query->getInt('limit', 24))));
        $result = null;
        $error = null;

        if ($question !== '') {
            try {
                $result = $this->chat->ask($ctx, $question, $coreCode, $dtoType, $limit);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        $cores = $ctx->em->getRepository(Core::class)->findBy([], ['code' => 'ASC']);
        $dtoChoices = [];
        foreach ($cores as $core) {
            $dtoChoices[$core->code] = $this->dtoChoices($ctx->em->getConnection()->executeQuery(
                'SELECT dto_type, COUNT(*) AS cnt FROM item WHERE core_id = ? GROUP BY dto_type ORDER BY cnt DESC',
                [$core->id],
            )->fetchAllAssociative());
        }

        return $this->render('@SurvosFolioBundle/folio/chat.html.twig', [
            'ctx' => $ctx,
            'cores' => $cores,
            'dtoChoices' => $dtoChoices,
            'question' => $question,
            'selectedCore' => $coreCode,
            'selectedDtoType' => $dtoType,
            'limit' => $limit,
            'promptSuggestions' => $this->promptSuggester->suggest($ctx, $dtoChoices),
            'wordCloud' => $this->wordCloud->cloud($ctx, 32),
            'result' => $result,
            'error' => $error,
        ]);
    }

    #[Route('/{provider}/{dataset}/row/{localId}', name: 'survos_folio_row_shortcut')]
    public function rowShortcut(string $provider, string $dataset, string $localId): Response
    {
        $folioCode = "$provider/$dataset";
        $ctx = $this->folios->context($folioCode);
        $rows = $ctx->em->getRepository(Row::class)
            ->createQueryBuilder('r')
            ->join('r.core', 'c')
            ->where('c.id LIKE :folioPrefix')
            ->andWhere('r.localId = :localId')
            ->setParameter('folioPrefix', "$folioCode:%")
            ->setParameter('localId', $localId)
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();

        $row = $rows[0] ?? throw $this->createNotFoundException($localId);
        \assert($row instanceof Row);

        return $this->redirectToRoute('survos_folio_row_show', [
            'provider' => $provider,
            'dataset' => $dataset,
            'coreCode' => $row->getCoreCode(),
            'dtoType' => $row->dtoType ?: 'row',
            'localId' => $row->localId,
        ]);
    }

    #[Route('/{provider}/{dataset}/{coreCode}/{dtoType}/{localId}', name: 'survos_folio_row_show', options: ['expose' => true])]
    public function rowShow(string $provider, string $dataset, string $coreCode, string $dtoType, string $localId): Response
    {
        $folioCode = "$provider/$dataset";
        $ctx = $this->folios->context($folioCode);
        $core = $ctx->em->find(Core::class, Core::id($folioCode, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId))
            ?? throw $this->createNotFoundException($localId);

        if ($row->dtoType && $dtoType !== $row->dtoType) {
            return $this->redirectToRoute('survos_folio_row_show', [
                'provider' => $provider,
                'dataset' => $dataset,
                'coreCode' => $coreCode,
                'dtoType' => $row->dtoType,
                'localId' => $localId,
            ]);
        }

        $schemaTable = $this->schemaTable($ctx->em->getConnection(), $coreCode, $row->dtoType);
        $dtoClass = is_array($schemaTable) && is_string($schemaTable['dto_class'] ?? null) ? $schemaTable['dto_class'] : $this->dtoTypeResolver->classForType($row->dtoType);
        $columns = $schemaTable ? $this->schemaColumns($ctx->em->getConnection(), (string) $schemaTable['id']) : ($dtoClass ? $this->dtoColumns($dtoClass) : []);
        $links = $this->rowLinks($ctx->em, $folioCode, $coreCode, $localId);
        $terms = [];
        $extras = $row->extras ?? [];
        if (isset($extras['genreSpecific'])) {
            $terms['genre'] = $extras['genreSpecific'];
            unset($extras['genreSpecific']);
        }
        if ($links !== []) {
            unset($extras['creator']);
        }

        return $this->render('@SurvosFolioBundle/folio/row.html.twig', [
            'ctx' => $ctx,
            'core' => $core,
            'row' => $row,
            'dtoClass' => $dtoClass,
            'columns' => $columns,
            'schemaTable' => $schemaTable,
            'links' => $links,
            'terms' => $terms,
            'extras' => $extras,
        ]);
    }


    /**
     * @return list<array{direction:string, label:?string, code:string, core:string, localId:string, row:?Row}>
     */
    private function rowLinks(\Doctrine\ORM\EntityManagerInterface $em, string $folioCode, string $coreCode, string $localId): array
    {
        $linkRows = $em->getRepository(Link::class)->createQueryBuilder('r')
            ->join('r.type', 't')
            ->join('t.folio', 'f')
            ->where('f.code = :folioCode')
            ->andWhere('(r.leftCore = :coreCode AND r.leftId = :localId) OR (r.rightCore = :coreCode AND r.rightId = :localId)')
            ->setParameter('folioCode', $folioCode)
            ->setParameter('coreCode', $coreCode)
            ->setParameter('localId', $localId)
            ->orderBy('t.code', 'ASC')
            ->getQuery()
            ->getResult();

        $links = [];
        foreach ($linkRows as $link) {
            \assert($link instanceof Link);
            $outgoing = $link->leftCore === $coreCode && $link->leftId === $localId;
            $targetCore = $outgoing ? $link->rightCore : $link->leftCore;
            $targetId = $outgoing ? $link->rightId : $link->leftId;
            $target = $em->find(Row::class, Row::id(Core::id($folioCode, $targetCore), $targetId));
            $links[] = [
                'direction' => $outgoing ? 'out' : 'in',
                'label' => $outgoing ? $link->type->label : $link->type->reverseLabel,
                'code' => $outgoing ? $link->type->code : ($link->type->reverseCode ?? $link->type->code),
                'core' => $targetCore,
                'localId' => $targetId,
                'row' => $target instanceof Row ? $target : null,
            ];
        }

        return $links;
    }

    #[Route('/{provider}/{dataset}/{coreCode}', name: 'survos_folio_core')]
    #[Route('/{provider}/{dataset}/{coreCode}/{dtoType}', name: 'survos_folio_core_dto', options: ['expose' => true])]
    public function core(string $provider, string $dataset, string $coreCode, Request $request, ?string $dtoType = null): Response
    {
        $folioCode = "$provider/$dataset";
        $ctx       = $this->folios->context($folioCode);
        $core      = $ctx->em->find(Core::class, Core::id($folioCode, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);

        $dtoChoices = $this->dtoChoices($ctx->em->getConnection()->executeQuery(
            'SELECT dto_type, COUNT(*) AS cnt FROM item WHERE core_id = ? GROUP BY dto_type ORDER BY cnt DESC',
            [$core->id],
        )->fetchAllAssociative());

        $selectedDto = $dtoType ?? '';
        $schemaTable = $selectedDto !== '' ? $this->schemaTable($ctx->em->getConnection(), $coreCode, $selectedDto) : null;
        $selectedDtoClass = is_array($schemaTable) && is_string($schemaTable['dto_class'] ?? null) ? $schemaTable['dto_class'] : $this->dtoTypeResolver->classForType($selectedDto);
        $populated   = array_flip($core->fieldSummary ?? []);
        $columns     = $schemaTable
            ? $this->schemaColumns($ctx->em->getConnection(), (string) $schemaTable['id'])
            : ($selectedDtoClass
                ? array_values(array_filter(
                    $this->dtoColumns($selectedDtoClass),
                    fn (string $col) => isset($populated[$col]) || $col === 'id',
                ))
                : []);

        return $this->render('@SurvosFolioBundle/folio/core.html.twig', [
            'ctx'         => $ctx,
            'core'        => $core,
            'dtoStats'    => $dtoChoices,
            'dtoChoices'   => $dtoChoices,
            'selectedDto' => $selectedDto,
            'rowClass'     => Row::class,
            'columns'     => $columns,
            'schemaTable' => $schemaTable,
        ]);
    }

    /**
     * @param Core[] $cores
     */
    private function objectCore(array $cores): ?Core
    {
        foreach ($cores as $core) {
            if ($core->code === 'obj') {
                return $core;
            }
        }

        return $cores[0] ?? null;
    }

    /**
     * @param array<int,array<string,mixed>> $stats
     * @return list<array{type: string|null, label: string, count: int}>
     */
    private function dtoChoices(array $stats): array
    {
        $choices = [];
        foreach ($stats as $stat) {
            $type = is_string($stat['dto_type'] ?? null) && $stat['dto_type'] !== '' ? $stat['dto_type'] : null;
            $choices[] = [
                'type' => $type,
                'label' => $this->dtoTypeResolver->labelForType($type),
                'count' => (int) $stat['cnt'],
            ];
        }

        return $choices;
    }

    /**
     * @param list<array{type: string|null, label: string, count: int}> $dtoChoices
     */
    private function dtoBreakdownChart(array $dtoChoices): ?Chart
    {
        if ($this->chartBuilder === null || $dtoChoices === []) {
            return null;
        }

        $chart = $this->chartBuilder->createChart(count($dtoChoices) <= 6 ? Chart::TYPE_DOUGHNUT : Chart::TYPE_BAR);
        $chart->setData([
            'labels' => array_map(static fn (array $choice): string => $choice['label'], $dtoChoices),
            'datasets' => [[
                'label' => 'Rows',
                'backgroundColor' => array_slice([
                    '#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2',
                    '#4b5563', '#be123c', '#65a30d', '#9333ea', '#0f766e', '#ea580c',
                ], 0, count($dtoChoices)),
                'data' => array_map(static fn (array $choice): int => $choice['count'], $dtoChoices),
            ]],
        ]);
        $chart->setOptions([
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => ['position' => 'bottom'],
            ],
            'scales' => count($dtoChoices) > 6 ? [
                'y' => ['beginAtZero' => true],
            ] : [],
        ]);

        return $chart;
    }


    private function schemaTable(\Doctrine\DBAL\Connection $connection, string $coreCode, ?string $dtoType): ?array
    {
        if ($dtoType === null || $dtoType === '') {
            return null;
        }

        $row = $connection->executeQuery(
            "SELECT id, name, dto_class FROM schema_table WHERE kind = 'dto' AND core_code = ? AND dto_type = ? LIMIT 1",
            [$coreCode, $dtoType],
        )->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /** @return list<string> */
    private function schemaColumns(\Doctrine\DBAL\Connection $connection, string $tableId): array
    {
        return array_values(array_filter(
            $connection->executeQuery(
                'SELECT name FROM schema_property WHERE table_id = ? AND visible = 1 ORDER BY position, name',
                [$tableId],
            )->fetchFirstColumn(),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
    }

    private function dtoColumns(string $dtoClass): array
    {
        if (!class_exists($dtoClass)) {
            return [];
        }
        $props = [];
        foreach ((new \ReflectionClass($dtoClass))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if (!$p->isStatic()) {
                $props[] = $p->getName();
            }
        }
        return $props;
    }

    private function formatDdl(string $sql): string
    {
        $open = strpos($sql, '(');
        if ($open === false) {
            return $sql;
        }

        $head = substr($sql, 0, $open + 1);
        $body = substr($sql, $open + 1, -1);
        $parts = [];
        $depth = 0;
        $current = '';

        for ($i = 0, $len = strlen($body); $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $head . "\n    " . implode(",\n    ", $parts) . "\n)";
    }
}
