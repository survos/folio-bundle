<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Doctrine\ORM\QueryBuilder;
use Survos\DatasetBundle\Entity\Artifact;
use Survos\DatasetBundle\Repository\ArtifactRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Pagination\PaginatorInterface;

final class FolioCollectionController extends AbstractController
{
    /**
     * One server-rendered page of folios. This used to select every folio artifact and hand the
     * lot to <twig:simple_datatables>, whose perPage is a CLIENT-side setting -- so "25 per page"
     * described the display and nothing else, while the server built every row on every hit.
     * Live on museado.org that was 1,564 rows and 15.8 MB of HTML per request (Cloudflare gzip
     * hid it at 359 KB on the wire, which is why it looked fine), and, far worse, 1,564 reads of
     * Artifact::$liveSize -- a clearstatcache() + filesize() pair each, against the /platform
     * volume mount. An unauthenticated page costing thousands of stat syscalls is the exact
     * shape of the scraper incident in survos-sites/zm#22.
     */
    private const PER_PAGE = 50;

    #[Route('', name: 'survos_folio_collection')]
    public function __invoke(Request $request, ArtifactRepository $artifacts, PaginatorInterface $paginator): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $page = max(1, $request->query->getInt('page', 1));

        $total = (int) $this->baseQuery($artifacts, $query)
            ->select('COUNT(artifact.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);

        $folioQuery = $this->baseQuery($artifacts, $query)
            ->orderBy('dataset.aggregator', 'ASC')
            ->addOrderBy('dataset.label', 'ASC');

        $pagination = $paginator->query($folioQuery)
            ->total($total)
            ->perPage(self::PER_PAGE)
            ->sliding(5)
            ->route('survos_folio_collection', ['q' => $query ?: null])
            ->paginate($page);

        return $this->render('@SurvosFolioBundle/folio/collection.html.twig', [
            'folios' => $pagination->getItems(),
            'pagination' => $pagination,
            'total' => $total,
            'perPage' => self::PER_PAGE,
            'query' => $query,
        ]);
    }

    /**
     * Built fresh for the count and for the page rather than cloned: a clone that has already had
     * select()/orderBy() applied needs resetDQLPart() juggling to become a COUNT, which is easy to
     * get subtly wrong against a join.
     */
    private function baseQuery(ArtifactRepository $artifacts, string $query): QueryBuilder
    {
        $qb = $artifacts->createQueryBuilder('artifact')
            ->join('artifact.dataset', 'dataset')
            ->where('artifact.type = :type')
            ->setParameter('type', Artifact::TYPE_FOLIO);

        if ($query !== '') {
            $qb->andWhere('dataset.label LIKE :q OR dataset.datasetKey LIKE :q OR dataset.aggregator LIKE :q')
                ->setParameter('q', '%' . $query . '%');
        }

        return $qb;
    }
}
