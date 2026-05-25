<?php

namespace App\Controller;

use Survos\DatasetBundle\Entity\Artifact;
use Survos\DatasetBundle\Repository\ArtifactRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AppController extends AbstractController
{
    #[Route('/', name: 'app_homepage')]
    public function index(ArtifactRepository $artifactRepository): Response
    {
        return $this->render('app/index.html.twig', [
            'artifacts' => $artifactRepository->findBy(['type' => Artifact::TYPE_FOLIO], ['uri' => 'ASC']),
        ]);
    }
}
