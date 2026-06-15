<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Survos\FolioBundle\Attribute\FolioContext;
use Survos\FolioBundle\Entity\{Core,Doc,Folio,Link,Row,Term,TermSet};
use Survos\FolioBundle\Service\{FolioChatPromptSuggester,FolioChatService,FolioDtoTypeResolver,FolioService,FolioWordCloudService};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
    ) {}


    #[Route('/{provider}/{dataset}', name: 'survos_folio_show')]
    public function show(string $provider, string $dataset): Response
    {
        $ctx = $this->folios->context("$provider/$dataset");
        $conn = $ctx->em->getConnection();
        $cores = $ctx->em->getRepository(Core::class)->findBy([], ['code' => 'ASC']);

        // Every core is first-class — no single "object" core is the centre. Each card gets its own
        // row count and DTO-type breakdown so the dashboard links straight into any core (obj, doc,
        // image, aut/people, …) rather than privileging objects.
        $coreSummaries = [];
        foreach ($cores as $core) {
            $coreSummaries[] = [
                'core' => $core,
                'dtoChoices' => $this->dtoChoices($conn->executeQuery(
                    'SELECT dto_type, COUNT(*) AS cnt FROM item WHERE core_id = ? GROUP BY dto_type ORDER BY cnt DESC',
                    [$core->id],
                )->fetchAllAssociative()),
            ];
        }

        $schemaTables = $conn->executeQuery(
            "SELECT name, kind, core_code, dto_type, label, row_count FROM schema_table ORDER BY kind, name"
        )->fetchAllAssociative();
        $views = $conn->executeQuery(
            "SELECT name FROM sqlite_master WHERE type = 'view' AND name LIKE 'dto_%' ORDER BY name"
        )->fetchFirstColumn();
        $folio = $ctx->em->find(Folio::class, $ctx->folioCode);

        return $this->render('@SurvosFolioBundle/folio/show.html.twig', [
            'ctx'           => $ctx,
            'cores'         => $cores,
            'coreSummaries' => $coreSummaries,
            'totalRows'     => array_reduce($cores, static fn (int $sum, Core $core): int => $sum + $core->rowCount, 0),
            'docs'          => $ctx->em->getRepository(Doc::class)->findBy([], ['position' => 'ASC']),
            'schemaTables'  => $schemaTables,
            'views'         => $views,
            'linkTypes'     => $folio?->linkTypes ?? [],
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



    #[Route('/{provider}/{dataset}/term/{setCode}/{termCode}', name: 'survos_folio_term_show')]
    public function termShow(string $provider, string $dataset, string $setCode, string $termCode): Response
    {
        $folioCode = "$provider/$dataset";
        $ctx = $this->folios->context($folioCode);
        $termSet = $ctx->em->find(TermSet::class, "$folioCode:$setCode")
            ?? throw $this->createNotFoundException($setCode);
        $term = $ctx->em->find(Term::class, $termSet->id . ':' . $termCode)
            ?? throw $this->createNotFoundException($termCode);

        return $this->render('@SurvosFolioBundle/folio/term.html.twig', [
            'ctx' => $ctx,
            'termSet' => $termSet,
            'term' => $term,
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
        $terms = $this->rowTerms($ctx->em, $folioCode, $row->dtoData ?? [], $row->extras ?? []);
        $extras = $row->extras ?? [];
        // Structural fields shown elsewhere (link/header/facets) — not display "extras".
        unset($extras['genreSpecific'], $extras['tec'], $extras['mat'], $extras['coll'], $extras['url']);
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
     * IIIF Presentation v3 manifest built from the row's pages — one canvas per
     * page, in seq order. Feeds the diva.js viewer on the doc page. Higher priority
     * than {@see rowShow()} so `…/{localId}/manifest.json` isn't swallowed by the
     * generic `…/{dtoType}/{localId}` row route.
     */
    #[Route('/{provider}/{dataset}/{coreCode}/{localId}/manifest.json', name: 'survos_folio_iiif_manifest', priority: 10)]
    public function iiifManifest(string $provider, string $dataset, string $coreCode, string $localId): Response
    {
        $folioCode = "$provider/$dataset";
        $ctx = $this->folios->context($folioCode);
        $core = $ctx->em->find(Core::class, Core::id($folioCode, $coreCode)) ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId)) ?? throw $this->createNotFoundException($localId);

        $manifestUrl = $this->generateUrl(
            'survos_folio_iiif_manifest',
            compact('provider', 'dataset', 'coreCode', 'localId'),
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        // Pages are the canonical imagery — one canvas per page, in seq order.
        $images = [];
        foreach ($row->pages as $page) {
            $images[] = [
                'url' => $page->url,
                'width' => $page->width ?? 1000,
                'height' => $page->height ?? 1000,
                'format' => 'image/jpeg',
            ];
        }
        if ($images === []) {
            // No pages: fall back to the row's own image.
            $data = $row->dtoData ?? [];
            $url = $data['largeImageUrl'] ?? $data['thumbnailUrl'] ?? null;
            if (is_string($url) && $url !== '') {
                $images = [['url' => $url, 'width' => (int) ($data['width'] ?? 1000), 'height' => (int) ($data['height'] ?? 1000), 'format' => 'image/jpeg']];
            }
        }

        $canvases = [];
        $seq = 0;
        foreach ($images as $img) {
            ++$seq;
            $canvasId = $manifestUrl.'/canvas/'.$seq;
            $canvases[] = [
                'id' => $canvasId,
                'type' => 'Canvas',
                'label' => ['none' => ['p. '.$seq]],
                'height' => $img['height'],
                'width' => $img['width'],
                'items' => [[
                    'id' => $canvasId.'/page',
                    'type' => 'AnnotationPage',
                    'items' => [[
                        'id' => $canvasId.'/annotation',
                        'type' => 'Annotation',
                        'motivation' => 'painting',
                        'target' => $canvasId,
                        'body' => [
                            'id' => $img['url'],
                            'type' => 'Image',
                            'format' => $img['format'],
                            'height' => $img['height'],
                            'width' => $img['width'],
                        ],
                    ]],
                ]],
            ];
        }

        return $this->json([
            '@context' => 'http://iiif.io/api/presentation/3/context.json',
            'id' => $manifestUrl,
            'type' => 'Manifest',
            'label' => ['none' => [$row->label ?: $localId]],
            'items' => $canvases,
        ]);
    }

    /**
     * Image-core rows linked to a doc, in sequence order, with a paint URL.
     *
     * @return list<array{url:string,width:int,height:int,format:string,localId:string,sequence:int}>
     */
    private function linkedImageRows(\Doctrine\ORM\EntityManagerInterface $em, string $folioCode, string $coreCode, string $localId): array
    {
        $rows = [];
        foreach ($this->rowLinks($em, $folioCode, $coreCode, $localId) as $link) {
            $target = $link['row'];
            if ($link['core'] !== 'image' || !$target instanceof Row) {
                continue;
            }
            $data = $target->dtoData ?? [];
            $url = $data['largeImageUrl'] ?? $data['url'] ?? $data['thumbnailUrl'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }
            $isPdf = stripos((string) ($data['objectType'] ?? ''), 'pdf') !== false;
            $rows[] = [
                'url' => $url,
                'width' => (int) ($data['width'] ?? 1000),
                'height' => (int) ($data['height'] ?? 1000),
                'format' => $isPdf ? 'application/pdf' : 'image/jpeg',
                'localId' => (string) $link['localId'],
                'sequence' => (int) ($data['sequence'] ?? 0),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        return $rows;
    }

    /**
     * @param array<string,mixed> $dtoData
     * @param array<string,mixed> $extras
     * @return array<string,list<array{code:string,label:string,term:?Term}>>
     */
    private function rowTerms(\Doctrine\ORM\EntityManagerInterface $em, string $folioCode, array $dtoData, array $extras): array
    {
        $sources = [
            'genre' => $dtoData['genreSpecific'] ?? $extras['genreSpecific'] ?? null,
            'technique' => $dtoData['tec'] ?? $extras['tec'] ?? null,
            'material' => $dtoData['mat'] ?? $extras['mat'] ?? null,
            'collection' => $dtoData['coll'] ?? $extras['coll'] ?? null,
        ];

        $terms = [];
        foreach ($sources as $setCode => $values) {
            foreach ($this->termValues($values) as $label) {
                $code = $this->termCode($label);
                $term = $em->find(Term::class, "$folioCode:$setCode:$code");
                $terms[$setCode][] = [
                    'code' => $code,
                    'label' => $label,
                    'term' => $term instanceof Term ? $term : null,
                ];
            }
        }

        return $terms;
    }

    /** @return list<string> */
    private function termValues(mixed $value): array
    {
        if (is_array($value)) {
            $values = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    foreach (['name', 'label', 'value', 'type'] as $key) {
                        if (isset($item[$key]) && is_scalar($item[$key])) {
                            $values[] = trim((string) $item[$key]);
                            break;
                        }
                    }
                    continue;
                }
                if (is_scalar($item)) {
                    $values[] = trim((string) $item);
                }
            }

            return array_values(array_unique(array_filter($values, 'strlen')));
        }

        return is_scalar($value) && trim((string) $value) !== '' ? [trim((string) $value)] : [];
    }

    private function termCode(string $label): string
    {
        $code = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label);
        $code = preg_replace('/[^a-z0-9]+/', '-', $code) ?? '';
        $code = trim($code, '-');

        return $code !== '' ? $code : hash('xxh128', $label);
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
