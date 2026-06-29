<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Doctrine\DBAL\Connection;
use Survos\FolioBundle\Attribute\FolioContext;
use Survos\FolioBundle\Entity\{Core,Doc,Folio,Link,Row,Term,TermSet};
use Survos\FolioBundle\Service\{FolioChatPromptSuggester,FolioChatService,FolioDtoTypeResolver,FolioService,FolioWordCloudService};
use Survos\DataContracts\Vocabulary\RelationBinding;
use Survos\DataContracts\Vocabulary\TermSetBinding;
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[Route('')]
#[FolioContext]
final class FolioController extends AbstractController
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioDtoTypeResolver $dtoTypeResolver,
        private readonly FolioChatService $chat,
        private readonly FolioChatPromptSuggester $promptSuggester,
        private readonly FolioWordCloudService $wordCloud,
        private readonly Environment $twig,
        private readonly ?ImgproxyUrlBuilder $imgproxy = null,
    ) {}


    #[Route('/{provider}/{dataset}', name: 'survos_folio_show')]
    public function show(string $provider, string $dataset): Response
    {
        $ctx = $this->folios->context("$provider/$dataset");
        $conn = $ctx->em->getConnection();
        $cores = $ctx->em->getRepository(Core::class)->findBy([], ['rowCount' => 'DESC', 'code' => 'ASC']);

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

    #[Route('/{provider}/{dataset}/download', name: 'survos_folio_download')]
    public function download(string $provider, string $dataset): BinaryFileResponse
    {
        $folioCode = "$provider/$dataset";
        $archivePath = $this->folios->archivePath($folioCode);
        if (!is_file($archivePath)) {
            throw $this->createNotFoundException(sprintf('Folio archive not found: %s', $folioCode));
        }

        $response = new BinaryFileResponse($archivePath);
        $response->headers->set('Content-Type', 'application/gzip');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('%s_%s.folio.gz', $provider, $dataset),
        );

        return $response;
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
        $conversationId = trim($request->request->getString('conversation', $request->query->getString('conversation')));
        if ($conversationId === '') {
            $conversationId = bin2hex(random_bytes(16));
        }
        $result = null;
        $error = null;

        if ($question !== '') {
            try {
                $result = $this->chat->ask($ctx, $question, $coreCode, $dtoType, $limit, $conversationId);
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
            'conversationId' => $conversationId,
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


    /**
     * "Results of an AI task" — the claim_run (prompt/model/tokens/response) plus the claims it
     * produced, served from the folio's local `claim_run` table (ingested from the shared store by
     * claims:fetch → folio build). Linked from each claim's runId on the detail page.
     */
    #[Route('/{provider}/{dataset}/run/{runId}', name: 'survos_folio_run', options: ['expose' => true])]
    public function run(string $provider, string $dataset, string $runId): Response
    {
        $folioCode = "$provider/$dataset";
        $conn = $this->folios->context($folioCode)->em->getConnection();

        try {
            $run = $conn->fetchAssociative('SELECT * FROM claim_run WHERE id = :id', ['id' => $runId]);
        } catch (\Throwable) {
            $run = false; // no claim_run table in this folio (rebuild after claims:fetch to populate)
        }
        if (!$run) {
            throw $this->createNotFoundException(sprintf('No claim run %s in %s', $runId, $folioCode));
        }

        $claims = $conn->fetchAllAssociative(
            'SELECT predicate, value, source, confidence, item_id FROM claim WHERE run_id = :id ORDER BY predicate',
            ['id' => $runId],
        );

        return $this->render('@SurvosFolioBundle/folio/run.html.twig', [
            'provider' => $provider,
            'dataset' => $dataset,
            'folioCode' => $folioCode,
            'run' => $run,
            'claims' => $claims,
        ]);
    }

    #[Route('/{provider}/{dataset}/{coreCode}/{dtoType}/{localId}/chat', name: 'survos_folio_item_chat', options: ['expose' => true])]
    public function itemChat(Request $request, string $provider, string $dataset, string $coreCode, string $dtoType, string $localId): Response
    {
        $folioCode = "$provider/$dataset";
        $ctx = $this->folios->context($folioCode);
        $core = $ctx->em->find(Core::class, Core::id($folioCode, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId))
            ?? throw $this->createNotFoundException($localId);

        if ($row->dtoType && $dtoType !== $row->dtoType) {
            return $this->redirectToRoute('survos_folio_item_chat', [
                'provider' => $provider,
                'dataset' => $dataset,
                'coreCode' => $coreCode,
                'dtoType' => $row->dtoType,
                'localId' => $localId,
            ]);
        }

        $pages = array_values($row->pages->toArray());
        $question = trim($request->request->getString('q', $request->query->getString('q')));
        $conversationId = trim($request->request->getString('conversation', $request->query->getString('conversation')));
        if ($conversationId === '') {
            $conversationId = bin2hex(random_bytes(16));
        }
        $chatScope = sprintf('item:%s:%s:%s', $ctx->folioCode, $core->code, $row->localId);
        $chatHistory = $this->chatHistory($request, $chatScope, $conversationId);
        $contextSections = $this->itemScholarContext($core, $row, $pages);
        $chatContextSections = $this->contextWithChatHistory($contextSections, $chatHistory);
        $result = $question !== ''
            ? $this->chat->askScoped($ctx, $question, 'Document: ' . ($row->label ?: $row->localId), $chatContextSections, $conversationId)
            : null;
        if ($result !== null) {
            $chatHistory = $this->appendChatTurn($request, $chatScope, $conversationId, $result);
        }

        return $this->render('@SurvosFolioBundle/folio/item_chat.html.twig', [
            'ctx' => $ctx,
            'core' => $core,
            'row' => $row,
            'pages' => $pages,
            'question' => $question,
            'conversationId' => $conversationId,
            'contextSections' => $contextSections,
            'chatHistory' => $chatHistory,
            'result' => $result,
        ]);
    }

    #[Route('/{provider}/{dataset}/{coreCode}/{dtoType}/{localId}/page/{seq}/chat', name: 'survos_folio_page_chat', requirements: ['seq' => '\d+'], options: ['expose' => true])]
    public function pageChat(Request $request, string $provider, string $dataset, string $coreCode, string $dtoType, string $localId, int $seq): Response
    {
        $folioCode = "$provider/$dataset";
        $ctx = $this->folios->context($folioCode);
        $core = $ctx->em->find(Core::class, Core::id($folioCode, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId))
            ?? throw $this->createNotFoundException($localId);

        if ($row->dtoType && $dtoType !== $row->dtoType) {
            return $this->redirectToRoute('survos_folio_page_chat', [
                'provider' => $provider,
                'dataset' => $dataset,
                'coreCode' => $coreCode,
                'dtoType' => $row->dtoType,
                'localId' => $localId,
                'seq' => $seq,
            ]);
        }

        $pages = array_values($row->pages->toArray());
        $page = null;
        foreach ($pages as $candidate) {
            if ($candidate->seq === $seq) {
                $page = $candidate;
                break;
            }
        }

        if ($page === null) {
            throw $this->createNotFoundException(sprintf('Page %d was not found for %s.', $seq, $localId));
        }

        $question = trim($request->request->getString('q', $request->query->getString('q')));
        $conversationId = trim($request->request->getString('conversation', $request->query->getString('conversation')));
        if ($conversationId === '') {
            $conversationId = bin2hex(random_bytes(16));
        }
        $chatScope = sprintf('page:%s:%s:%s:%d', $ctx->folioCode, $core->code, $row->localId, $page->seq);
        $chatHistory = $this->chatHistory($request, $chatScope, $conversationId);
        $contextSections = $this->pageScholarContext($core, $row, $page);
        $chatContextSections = $this->contextWithChatHistory($contextSections, $chatHistory);
        $result = $question !== ''
            ? $this->chat->askScoped($ctx, $question, sprintf('Page %d of document %s', $page->seq, $row->label ?: $row->localId), $chatContextSections, $conversationId)
            : null;
        if ($result !== null) {
            $chatHistory = $this->appendChatTurn($request, $chatScope, $conversationId, $result);
        }

        return $this->render('@SurvosFolioBundle/folio/page_chat.html.twig', [
            'ctx' => $ctx,
            'core' => $core,
            'row' => $row,
            'page' => $page,
            'pages' => $pages,
            'question' => $question,
            'conversationId' => $conversationId,
            'contextSections' => $contextSections,
            'chatHistory' => $chatHistory,
            'result' => $result,
        ]);
    }

    #[Route('/{provider}/{dataset}/{coreCode}/{dtoType}/{localId}/chat/stream', name: 'survos_folio_item_chat_stream', methods: ['POST'], options: ['expose' => true])]
    public function itemChatStream(Request $request, string $provider, string $dataset, string $coreCode, string $dtoType, string $localId): StreamedResponse
    {
        $folioCode = "$provider/$dataset";
        $ctx = $this->folios->context($folioCode);
        $core = $ctx->em->find(Core::class, Core::id($folioCode, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId))
            ?? throw $this->createNotFoundException($localId);

        if ($row->dtoType && $dtoType !== $row->dtoType) {
            throw $this->createNotFoundException(sprintf('DTO type "%s" does not match "%s".', $dtoType, $row->dtoType));
        }

        $pages = array_values($row->pages->toArray());
        $question = trim($request->request->getString('q'));
        $conversationId = trim($request->request->getString('conversation'));
        if ($conversationId === '') {
            $conversationId = bin2hex(random_bytes(16));
        }

        $chatScope = sprintf('item:%s:%s:%s', $ctx->folioCode, $core->code, $row->localId);
        $chatHistory = $this->chatHistory($request, $chatScope, $conversationId);
        $contextSections = $this->contextWithChatHistory($this->itemScholarContext($core, $row, $pages), $chatHistory);

        return $this->streamScholarResponse(
            $request,
            $ctx,
            $chatScope,
            $conversationId,
            $question,
            'Document: ' . ($row->label ?: $row->localId),
            $contextSections,
        );
    }

    #[Route('/{provider}/{dataset}/{coreCode}/{dtoType}/{localId}/page/{seq}/chat/stream', name: 'survos_folio_page_chat_stream', requirements: ['seq' => '\d+'], methods: ['POST'], options: ['expose' => true])]
    public function pageChatStream(Request $request, string $provider, string $dataset, string $coreCode, string $dtoType, string $localId, int $seq): StreamedResponse
    {
        $folioCode = "$provider/$dataset";
        $ctx = $this->folios->context($folioCode);
        $core = $ctx->em->find(Core::class, Core::id($folioCode, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId))
            ?? throw $this->createNotFoundException($localId);

        if ($row->dtoType && $dtoType !== $row->dtoType) {
            throw $this->createNotFoundException(sprintf('DTO type "%s" does not match "%s".', $dtoType, $row->dtoType));
        }

        $pages = array_values($row->pages->toArray());
        $page = null;
        foreach ($pages as $candidate) {
            if ($candidate->seq === $seq) {
                $page = $candidate;
                break;
            }
        }

        if ($page === null) {
            throw $this->createNotFoundException(sprintf('Page %d was not found for %s.', $seq, $localId));
        }

        $question = trim($request->request->getString('q'));
        $conversationId = trim($request->request->getString('conversation'));
        if ($conversationId === '') {
            $conversationId = bin2hex(random_bytes(16));
        }

        $chatScope = sprintf('page:%s:%s:%s:%d', $ctx->folioCode, $core->code, $row->localId, $page->seq);
        $chatHistory = $this->chatHistory($request, $chatScope, $conversationId);
        $contextSections = $this->contextWithChatHistory($this->pageScholarContext($core, $row, $page), $chatHistory);

        return $this->streamScholarResponse(
            $request,
            $ctx,
            $chatScope,
            $conversationId,
            $question,
            sprintf('Page %d of document %s', $page->seq, $row->label ?: $row->localId),
            $contextSections,
        );
    }

    /**
     * @param array<string, mixed> $contextSections
     */
    private function streamScholarResponse(Request $request, \Survos\FolioBundle\Model\FolioContext $ctx, string $chatScope, string $conversationId, string $question, string $scopeLabel, array $contextSections): StreamedResponse
    {
        return new StreamedResponse(function () use ($request, $ctx, $chatScope, $conversationId, $question, $scopeLabel, $contextSections): void {
            $this->sendSse('start', ['conversationId' => $conversationId]);
            $streamedMarkdown = '';
            $lastHtmlLength = 0;
            $lastHtmlTime = microtime(true);

            $result = $this->chat->streamScoped(
                $ctx,
                $question,
                $scopeLabel,
                $contextSections,
                function (string $text) use (&$streamedMarkdown, &$lastHtmlLength, &$lastHtmlTime): void {
                    $streamedMarkdown .= $text;
                    $this->sendSse('delta', ['text' => $text]);

                    $now = microtime(true);
                    if (\strlen($streamedMarkdown) - $lastHtmlLength >= 240 || $now - $lastHtmlTime >= 0.4) {
                        $this->sendSse('html', ['html' => $this->renderScholarAnswerHtml($streamedMarkdown)]);
                        $lastHtmlLength = \strlen($streamedMarkdown);
                        $lastHtmlTime = $now;
                    }
                },
                $conversationId,
            );

            $this->appendChatTurn($request, $chatScope, $conversationId, $result);
            $this->sendSse('done', [
                'conversationId' => $conversationId,
                'error' => $result->error,
                'answerHtml' => $this->renderScholarAnswerHtml($result->answer),
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendSse(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";

        if (\function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    private function renderScholarAnswerHtml(string $markdown): string
    {
        try {
            return $this->twig
                ->createTemplate('{{ answer|markdown_to_html }}')
                ->render(['answer' => $markdown]);
        } catch (\Throwable) {
            return nl2br(htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }
    }

    /**
     * @return list<array{question: string, answer: string, error?: string|null}>
     */
    private function chatHistory(Request $request, string $scope, string $conversationId): array
    {
        if (!$request->hasSession()) {
            return [];
        }

        $history = $request->getSession()->get($this->chatHistoryKey($scope, $conversationId), []);

        return \is_array($history) ? array_values($history) : [];
    }

    /**
     * @return list<array{question: string, answer: string, error?: string|null}>
     */
    private function appendChatTurn(Request $request, string $scope, string $conversationId, \Survos\FolioBundle\Model\FolioChatResult $result): array
    {
        $history = $this->chatHistory($request, $scope, $conversationId);
        $history[] = $this->cleanContext([
            'question' => $result->question,
            'answer' => $result->answer,
            'error' => $result->error,
        ]);
        $history = array_slice($history, -20);

        if ($request->hasSession()) {
            $request->getSession()->set($this->chatHistoryKey($scope, $conversationId), $history);
        }

        return $history;
    }

    /**
     * @param array<string, mixed> $contextSections
     * @param list<array{question: string, answer: string, error?: string|null}> $chatHistory
     * @return array<string, mixed>
     */
    private function contextWithChatHistory(array $contextSections, array $chatHistory): array
    {
        if ($chatHistory === []) {
            return $contextSections;
        }

        return ['Conversation so far' => array_slice($chatHistory, -8)] + $contextSections;
    }

    private function chatHistoryKey(string $scope, string $conversationId): string
    {
        return 'survos_folio.chat.' . hash('sha256', $scope . ':' . $conversationId);
    }

    /**
     * @param list<object> $pages
     * @return array<string, mixed>
     */
    private function itemScholarContext(Core $core, Row $row, array $pages): array
    {
        $sections = [
            'Document identity' => $this->cleanContext([
                'localId' => $row->localId,
                'label' => $row->label,
                'core' => $core->code,
                'dtoType' => $row->dtoType,
                'citationUrl' => $row->getCitationUrl(),
                'pageCount' => count($pages),
            ]),
            'Source metadata' => $row->dtoData ?? [],
            'Source extras' => $row->extras ?? [],
        ];

        if (count($pages) === 1) {
            $sections['Single page context'] = $this->pageContextData($pages[0]);
        } elseif ($pages !== []) {
            $sections['Page inventory'] = array_map(
                fn (object $page): array => $this->cleanContext([
                    'seq' => $page->seq ?? null,
                    'type' => isset($page->type) && $page->type instanceof \BackedEnum ? $page->type->value : ($page->type ?? null),
                    'mediaId' => $page->mediaId ?? null,
                    'hasOcr' => isset($page->text) && is_string($page->text) && trim($page->text) !== '',
                    'hasDescription' => isset($page->denseSummary) && is_string($page->denseSummary) && trim($page->denseSummary) !== '',
                ]),
                array_slice($pages, 0, 100),
            );
            if (count($pages) > 100) {
                $sections['Page inventory note'] = sprintf('Showing metadata for the first 100 of %d pages.', count($pages));
            }
        }

        return $this->cleanContext($sections);
    }

    /** @return array<string, mixed> */
    private function pageScholarContext(Core $core, Row $row, object $page): array
    {
        return $this->cleanContext([
            'Document identity' => $this->cleanContext([
                'localId' => $row->localId,
                'label' => $row->label,
                'core' => $core->code,
                'dtoType' => $row->dtoType,
                'citationUrl' => $row->getCitationUrl(),
            ]),
            'Document source metadata' => $row->dtoData ?? [],
            'Document source extras' => $row->extras ?? [],
            'Page context' => $this->pageContextData($page),
        ]);
    }

    /** @return array<string, mixed> */
    private function pageContextData(object $page): array
    {
        return $this->cleanContext([
            'seq' => $page->seq ?? null,
            'pageIndex' => $page->pageIndex ?? null,
            'type' => isset($page->type) && $page->type instanceof \BackedEnum ? $page->type->value : ($page->type ?? null),
            'url' => $page->url ?? null,
            'mediaId' => $page->mediaId ?? null,
            'width' => $page->width ?? null,
            'height' => $page->height ?? null,
            'ocrText' => $page->text ?? null,
            'description' => $page->denseSummary ?? null,
            'ledger' => $page->ledger ?? null,
            'layout' => $page->layout ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function cleanContext(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
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
        $pageTableExists = $this->tableExists($ctx->em->getConnection(), 'page');
        $claims = $this->rowClaims($ctx->em->getConnection(), $row->id);
        $aiTaskRuns = $this->aiTaskRuns($claims);
        $links = $this->rowLinks($ctx->em, $folioCode, $coreCode, $localId);
        $terms = $this->rowTerms($ctx->em, $folioCode, $row->dtoData ?? [], $row->extras ?? []);
        $adjacent = $this->adjacentRows($ctx->em->getConnection(), $core->id, $localId);

        // Fields shown elsewhere (as Terms, or as relation links) are excluded from the DTO Data
        // table on the left — same single-source bindings, so the table never repeats them.
        $termFields = array_merge(
            [],
            ...array_values(TermSetBinding::fields()),
            ...array_map(static fn (array $r): array => $r['sourceFields'], array_values(RelationBinding::relations())),
        );

        // The extras blob also shouldn't repeat fields rendered as Terms or Relations — strip the same
        // termset/relation source fields (e.g. dept/cul/med, creators/collections), plus structural
        // ones shown in the header.
        $extras = $row->extras ?? [];
        foreach ([...$termFields, 'url'] as $shownElsewhere) {
            unset($extras[$shownElsewhere]);
        }

        return $this->render('@SurvosFolioBundle/folio/detail.html.twig', [
            'ctx' => $ctx,
            'core' => $core,
            'row' => $row,
            'dtoClass' => $dtoClass,
            'columns' => $columns,
            'schemaTable' => $schemaTable,
            'pageTableExists' => $pageTableExists,
            'claims' => $claims,
            'aiTaskRuns' => $aiTaskRuns,
            'links' => $links,
            'terms' => $terms,
            'termFields' => $termFields,
            'extras' => $extras,
            'adjacent' => $adjacent,
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

        $images = [];
        if ($this->tableExists($ctx->em->getConnection(), 'page')) {
            // Pages are the canonical imagery — one canvas per page, in seq order.
            foreach ($row->pages as $page) {
                $images[] = [
                    'url' => $page->url,
                    'width' => $page->width ?? 1000,
                    'height' => $page->height ?? 1000,
                    'format' => 'image/jpeg',
                ];
            }
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
        // Same field→termset binding the extractor used to write the Term rows (single source of
        // truth: #[VocabTerm(termSet:true, sourceFields:…)] on MuseumVocab), so what we resolve here
        // matches what exists. A set draws from several fields (e.g. obj ← subjects/keywords).
        $terms = [];
        foreach (TermSetBinding::fields() as $setCode => $fields) {
            $labels = [];
            foreach ($fields as $field) {
                foreach ($this->termValues($dtoData[$field] ?? $extras[$field] ?? null) as $label) {
                    $labels[] = $label;
                }
            }
            // Dedupe by value (array_unique), NOT by key — a numeric-string label like "1886" used as
            // an array key would be coerced to an int and break termCode(string).
            foreach (array_unique($labels) as $label) {
                $code = $this->termCode($label);
                $term = $em->find(Term::class, "$folioCode:$setCode:$code");
                $terms[$setCode][] = [
                    'code' => $code,
                    'label' => $label,
                    'term' => $term instanceof Term ? $term : null,
                ];
            }
        }

        return array_filter($terms);
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
     * Claims recorded against a row (AI/OCR/human assertions), e.g. produced by
     * running ai-workflow tasks via mediary and landed in the folio `claim` table.
     *
     * @return list<array<string,mixed>>
     */
    private function rowClaims(Connection $conn, string $itemId): array
    {
        if (!$this->tableExists($conn, 'claim')) {
            return [];
        }

        return $conn->executeQuery(
            'SELECT predicate, value, source, confidence, agent, claimed_at, run_id FROM claim WHERE item_id = ? ORDER BY source, predicate',
            [$itemId],
        )->fetchAllAssociative();
    }

    /**
     * @param list<array<string,mixed>> $claims
     * @return array<string,string> task/source key => ClaimRun id
     */
    private function aiTaskRuns(array $claims): array
    {
        $runs = [];
        foreach ($claims as $claim) {
            $source = $claim['source'] ?? null;
            $runId = $claim['run_id'] ?? null;
            if (!is_string($source) || $source === '' || !is_string($runId) || $runId === '') {
                continue;
            }

            $task = str_starts_with($source, 'ai:') ? substr($source, 3) : $source;
            $runs[$task] ??= $runId;
        }

        return $runs;
    }

    /**
     * Previous/next document within the same core, in build (rowid) order — keyset navigation done
     * with SQLite LAG/LEAD so both neighbours come back in a single pass (no offset, no loading the
     * list). v1 uses intrinsic order; a later version can honour the active search/filter context.
     *
     * @return array{prev: ?array{localId: string, dtoType: string}, next: ?array{localId: string, dtoType: string}}
     */
    private function adjacentRows(Connection $conn, string $coreId, string $localId): array
    {
        $row = $conn->fetchAssociative(
            'SELECT prev_id, prev_type, next_id, next_type FROM (
                SELECT local_id,
                       LAG(local_id)  OVER (ORDER BY rowid) AS prev_id,
                       LAG(dto_type)  OVER (ORDER BY rowid) AS prev_type,
                       LEAD(local_id) OVER (ORDER BY rowid) AS next_id,
                       LEAD(dto_type) OVER (ORDER BY rowid) AS next_type
                FROM item WHERE core_id = :core
            ) WHERE local_id = :current',
            ['core' => $coreId, 'current' => $localId],
        ) ?: [];

        $mk = static fn (?string $id, ?string $type): ?array => null === $id || '' === $id
            ? null
            : ['localId' => $id, 'dtoType' => $type ?: 'row'];

        return [
            'prev' => $mk($row['prev_id'] ?? null, $row['prev_type'] ?? null),
            'next' => $mk($row['next_id'] ?? null, $row['next_type'] ?? null),
        ];
    }

    private function tableExists(Connection $conn, string $table): bool
    {
        try {
            return $conn->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
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
