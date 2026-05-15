<?php

namespace App\Controller;

use Survos\DataBundle\Repository\DatasetInfoRepository;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AppController extends AbstractController
{
    #[Route('/', name: 'app_homepage')]
    public function index(DatasetInfoRepository $datasetInfoRepository, FolioService $folioService): Response
    {
        $datasets = $datasetInfoRepository->findBy([], ['datasetKey' => 'ASC']);

        $folios = array_map(static function ($dataset) use ($folioService) {
            $path = $folioService->path($dataset->datasetKey);
            return [
                'dataset' => $dataset,
                'path'    => $path,
                'exists'  => is_file($path),
                'size'    => is_file($path) ? filesize($path) : null,
            ];
        }, $datasets);

        return $this->render('app/index.html.twig', [
            'folios' => $folios,
        ]);
    }
}
