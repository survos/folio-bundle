<?php

declare(strict_types=1);

namespace App\Controller;

use Survos\FolioBundle\Catalog\FolioCatalogClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AppController extends AbstractController
{
    #[Route('/', name: 'app_homepage')]
    public function index(FolioCatalogClient $catalog): Response
    {
        return $this->render('app/index.html.twig', ['folios' => $catalog->all()]);
    }
}
