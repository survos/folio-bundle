<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Survos\DatasetBundle\Entity\Artifact;
use Survos\DatasetBundle\Repository\ArtifactRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/folios')]
final class FolioCollectionController extends AbstractController
{
    #[Route('', name: 'survos_folio_collection')]
    public function __invoke(ArtifactRepository $artifacts): Response
    {
        $folios = $artifacts->createQueryBuilder('artifact')
            ->join('artifact.dataset', 'dataset')
            ->where('artifact.type = :type')
            ->setParameter('type', Artifact::TYPE_FOLIO)
            ->orderBy('dataset.aggregator', 'ASC')
            ->addOrderBy('dataset.label', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('@SurvosFolioBundle/folio/collection.html.twig', [
            'folios' => $folios,
        ]);
    }
}
