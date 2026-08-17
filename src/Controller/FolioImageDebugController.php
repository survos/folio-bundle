<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Survos\DataContracts\Util\ImageUrl;
use Survos\DataContracts\Util\ImageUrlVerdict;
use Survos\FolioBundle\Entity\Claim;
use Survos\FolioBundle\Entity\Row;
use Survos\FolioBundle\Service\FolioService;
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Single-item image diagnostics: what was harvested, what we'd ask imgproxy for, and what the
 * origin actually returns right now.
 *
 * Exists because an image failure used to surface only as a broken thumbnail in a gallery, with
 * no way to tell a wrong-field harvest bug from a dead origin from an imgproxy signing problem
 * (survos-sites/musdig#40). Deliberately provider-agnostic — it reads Row/Page/Claim, so it works
 * for any folio, not just the Smithsonian records that prompted it.
 */
#[Route('/admin/debug/image')]
final class FolioImageDebugController extends AbstractController
{
    public function __construct(
        private readonly FolioService $folios,
        // Both optional for the same reason FolioController takes them optionally: imgproxy-bundle
        // and http-client are not hard requirements of folio-bundle.
        private readonly ?ImgproxyUrlBuilder $imgproxy = null,
        private readonly ?HttpClientInterface $http = null,
    ) {
    }

    /**
     * `{rowId}` is the full composite Row id — "<provider>/<dataset>:<coreCode>:<localId>" — so it
     * contains slashes and colons and needs the catch-all requirement.
     */
    // priority: FolioController is mounted at '/{folioCode}' with a pattern permissive enough to
    // match 'admin/debug', so without an explicit priority it claims these paths first and fails
    // looking for a folio file called admin/debug.folio.
    #[Route('/{rowId}', name: 'survos_folio_image_debug', requirements: ['rowId' => '.+'], methods: ['GET'], priority: 100)]
    public function show(string $rowId): Response
    {
        $this->denyUnlessDebugger();
        [$row, $folioCode] = $this->loadRow($rowId);

        $raw = $row->getRawThumbnailSource();
        $verdict = $row->getImageVerdict();

        return $this->render('@SurvosFolioBundle/folio/image_debug.html.twig', [
            'row' => $row,
            'folioCode' => $folioCode,
            'rawSource' => $raw,
            'verdict' => $verdict,
            // Only sign a URL we'd actually request; signing a known-bad one would imply the
            // gallery still emits it, which after the Row::getThumbnailSource() gate it does not.
            'imgproxyUrl' => $verdict->isRenderable() && $raw !== null && $this->imgproxy !== null
                ? $this->imgproxy->resizePreset($raw, 'thumb')
                : null,
            'folioPath' => $this->folioPath($folioCode),
            'builtAt' => $this->builtAt($folioCode),
            'claims' => $this->claims($row),
            'pages' => $row->pages->toArray(),
            'sourceUrl' => $row->getCitationUrl(),
        ]);
    }

    /**
     * Live re-check, kept as its own endpoint so the page can be refreshed against the origin
     * after a fix without rebuilding anything — a cached verdict is exactly what made this class
     * of bug hard to confirm.
     */
    #[Route('/{rowId}/probe', name: 'survos_folio_image_debug_probe', requirements: ['rowId' => '.+'], methods: ['POST'], priority: 101)]
    public function probe(string $rowId): JsonResponse
    {
        $this->denyUnlessDebugger();
        [$row] = $this->loadRow($rowId);

        $url = $row->getRawThumbnailSource();
        if ($url === null) {
            return $this->json(['ok' => false, 'error' => 'This row has no image URL at all.']);
        }
        if ($this->http === null) {
            return $this->json(['ok' => false, 'error' => 'No HTTP client available in this app.']);
        }

        try {
            // GET, not HEAD: several of the origins involved (siarchives.si.edu among them)
            // answer HEAD differently from GET, and it's the GET behaviour imgproxy will see.
            // buffer:false means we can read the headers and drop the body unread.
            $response = $this->http->request('GET', $url, [
                'timeout' => 15,
                'max_redirects' => 5,
                'buffer' => false,
            ]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? null;
            $length = isset($headers['content-length'][0]) ? (int) $headers['content-length'][0] : null;
            $response->cancel();

            return $this->json([
                'ok' => $status >= 200 && $status < 300 && ImageUrl::isImageContentType($contentType),
                'status' => $status,
                'contentType' => $contentType,
                'contentLength' => $length,
                'isImageContentType' => ImageUrl::isImageContentType($contentType),
                'url' => $url,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage(), 'url' => $url]);
        }
    }

    /**
     * Admin-only, with an app.debug escape hatch — same guard PhotoGrid.html.twig uses for its
     * debug affordances, so apps without a ROLE_ADMIN still get this in dev.
     */
    private function denyUnlessDebugger(): void
    {
        if ($this->isGranted('ROLE_ADMIN') || $this->getParameter('kernel.debug')) {
            return;
        }

        throw $this->createAccessDeniedException();
    }

    /**
     * @return array{0: Row, 1: string}
     */
    private function loadRow(string $rowId): array
    {
        // "<provider>/<dataset>:<coreCode>:<localId>" — limit 3 so a localId containing a colon
        // survives intact.
        $parts = explode(':', $rowId, 3);
        if (count($parts) < 3) {
            throw $this->createNotFoundException(sprintf('Malformed row id "%s".', $rowId));
        }
        $folioCode = $parts[0];

        try {
            $ctx = $this->folios->context($folioCode);
        } catch (\Throwable $e) {
            throw $this->createNotFoundException(sprintf('No folio "%s": %s', $folioCode, $e->getMessage()));
        }

        $row = $ctx->em->find(Row::class, $rowId)
            ?? throw $this->createNotFoundException(sprintf('No row "%s".', $rowId));

        return [$row, $folioCode];
    }

    /** @return list<Claim> */
    private function claims(Row $row): array
    {
        return $row->claims->toArray();
    }

    private function folioPath(string $folioCode): ?string
    {
        try {
            return $this->folios->path($folioCode);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * No harvest/build timestamp is persisted on Folio/Core, so the best available signal is the
     * .folio file's mtime. Caveat worth knowing before trusting it: merely *opening* the folio
     * read-write (as any page load does) touches the file, so this drifts forward and is a weak
     * upper bound on "last built", not a build timestamp. Labelled "file mtime" in the template
     * rather than "built" so it isn't read as more than it is.
     */
    private function builtAt(string $folioCode): ?\DateTimeImmutable
    {
        $path = $this->folioPath($folioCode);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $mtime = filemtime($path);

        return $mtime === false ? null : new \DateTimeImmutable('@' . $mtime);
    }
}
