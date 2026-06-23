<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Survos\FolioBundle\Entity\Core;
use Survos\FolioBundle\Entity\Row;
use Survos\FolioBundle\Service\FolioService;
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Run a mediary AI task (OCR, handwriting/vision, describe…) against a folio row's page image and
 * persist the result straight into the folio so it's available immediately — without waiting for a
 * `folio:build`. mediary owns fetch + caching + claims + the shared S3 sidecar; this page only
 * resolves the row → page image (+ row metadata), dispatches a sync call, writes `result.text` onto
 * the {@see \Survos\FolioBundle\Entity\Page}, and shows it. The durable claims are re-imported on the
 * next folio build.
 *
 * Keyed by folio identifiers (provider/dataset/coreCode/localId + page), NOT a bare URL — so it can
 * target the right page of a multi-page doc, include the row's existing metadata, and update the
 * right entity.
 */
#[Route('/folio')]
final class FolioAiController extends AbstractController
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(MEDIARY_AI_URL)%')]
        private readonly string $endpoint,
        // imgproxy-bundle is optional (RequiredBundle ignoreOnInvalid); null → skip url unwrapping.
        private readonly ?ImgproxyUrlBuilder $imgproxy = null,
        // dev: the apps share a proxy keyed by Host; resolve mediary that way.
        #[Autowire('%env(default::MEDIARY_HOST)%')]
        private readonly ?string $host = null,
    ) {
    }

    #[Route('/{provider}/{dataset}/ai/{coreCode}/{localId}', name: 'survos_folio_ai', options: ['expose' => true])]
    public function run(
        Request $request,
        string $provider,
        string $dataset,
        string $coreCode,
        string $localId,
    ): Response {
        $folioCode = "$provider/$dataset";
        $ctx = $this->folios->context($folioCode);
        $core = $ctx->em->find(Core::class, Core::id($folioCode, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId))
            ?? throw $this->createNotFoundException($localId);

        $pages = array_values($row->pages->toArray());
        $pageIndex = max(0, $request->query->getInt('page'));
        $page = $pages[$pageIndex] ?? ($pages[0] ?? null);

        $task = trim((string) $request->query->get('task', ''));
        // Only hit mediary on an EXPLICIT run — landing here just shows the image + task buttons;
        // nothing is computed (or charged) until the user picks a task.
        $run = $request->query->getBoolean('run');

        // mediary fetches + imgproxies the source itself; hand it the RAW page url (unwrap any
        // imgproxy-wrapped value, or it double-processes and 403s).
        $imageUrl = $page?->url;
        if ($imageUrl !== null && $this->imgproxy !== null) {
            $imageUrl = $this->imgproxy->sourceUrl($imageUrl);
        }

        $result = null;
        $error = null;
        $persisted = false;

        if ($run && $task !== '' && $imageUrl) {
            $headers = ['Accept' => 'application/json'];
            if ($this->host) {
                $headers['Host'] = $this->host;
                $headers['X-Forwarded-Proto'] = 'https';
            }

            try {
                $response = $this->httpClient->request('POST', $this->endpoint, [
                    'headers' => $headers,
                    'body' => array_filter(['url' => $imageUrl, 'task' => $task], static fn ($v) => $v !== ''),
                    'timeout' => 240,
                ]);
                $result = $response->toArray(false);

                // Persist the text onto the page so it's available immediately (claims land on rebuild).
                $text = $result['result']['text'] ?? null;
                if (is_string($text) && trim($text) !== '' && $page !== null) {
                    $page->text = $text;
                    $ctx->em->flush();
                    $persisted = true;
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('@SurvosFolioBundle/folio/ai.html.twig', [
            'ctx' => $ctx,
            'provider' => $provider,
            'dataset' => $dataset,
            'core' => $core,
            'row' => $row,
            'page' => $page,
            'pageIndex' => $pageIndex,
            'pageCount' => count($pages),
            'imageUrl' => $imageUrl,
            'task' => $task,
            'run' => $run,
            'result' => $result,
            'error' => $error,
            'persisted' => $persisted,
        ]);
    }
}
