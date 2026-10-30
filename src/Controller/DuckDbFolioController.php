<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Service\DuckDbFolioConnection;
use Survos\FolioBundle\Service\DuckDbFolioView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Optional folio manifestation. Existing ES/SQLite entrypoints retain their reader. */
#[Route('/{folioCode}/duckdb', requirements: ['folioCode' => '[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+'], defaults: ['_folio_reader' => 'duckdb'])]
final class DuckDbFolioController extends AbstractController
{
    public function __construct(private readonly DuckDbFolioConnection $connection, private readonly DuckDbFolioView $view)
    {
    }

    #[Route('/search', name: 'survos_folio_duckdb_search', methods: ['GET'], priority: 60)]
    public function search(string $folioCode): Response
    {
        // Check the registered manifestation before rendering the shared search component.
        $this->connection->connect(['folioCode' => $folioCode]);

        return $this->render('@SurvosFolioBundle/folio/duckdb/search.html.twig', [
            'folio' => new Folio($folioCode), 'reader' => 'duckdb',
            'hitTemplate' => '@SurvosFolioBundle/search/hits/folio_duckdb.html.twig',
        ]);
    }

    #[Route('/{coreCode}/{localId}', name: 'survos_folio_duckdb_row_show', requirements: ['localId' => '.+'], methods: ['GET'], priority: 50)]
    public function show(string $folioCode, string $coreCode, string $localId): Response
    {
        return $this->render('@SurvosFolioBundle/folio/row/duckdb.html.twig', $this->view->item($folioCode, $coreCode, $localId));
    }
}
