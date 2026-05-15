<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Menu;

use Survos\DataBundle\Repository\DatasetInfoRepository;
use Survos\FolioBundle\Service\FolioService;
use Survos\TablerBundle\Event\MenuEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\RouterInterface;

class FolioMenu
{
    public function __construct(
        private readonly FolioService $folioService,
        private readonly DatasetInfoRepository $datasetInfoRepository,
        private readonly RouterInterface $router,
    ) {}

    #[AsEventListener(event: MenuEvent::NAVBAR_MENU)]
    public function onNavbarMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        // Find all datasets that have a folio file on disk.
        $datasets = array_filter(
            $this->datasetInfoRepository->findBy([], ['datasetKey' => 'ASC']),
            fn ($d) => is_file($this->folioService->path($d->datasetKey)),
        );

        if (empty($datasets)) {
            return;
        }

        $submenu = $menu->addChild('Folios', [
            'uri' => $this->router->generate('app_homepage'),
        ]);

        // Group by provider; show individual dataset links when ≤ 20 total.
        $byProvider = [];
        foreach ($datasets as $d) {
            $provider = explode('/', $d->datasetKey)[0];
            $byProvider[$provider][] = $d;
        }

        if (count($datasets) <= 20) {
            foreach ($byProvider as $provider => $items) {
                $providerMenu = $submenu->addChild($provider);
                foreach ($items as $d) {
                    $parts = explode('/', $d->datasetKey, 2);
                    $providerMenu->addChild($d->label ?? $d->datasetKey, [
                        'route' => 'survos_folio_show',
                        'routeParameters' => ['provider' => $parts[0], 'dataset' => $parts[1] ?? $parts[0]],
                    ]);
                }
            }
        } else {
            // Too many to list — just show provider counts.
            foreach ($byProvider as $provider => $items) {
                $submenu->addChild(sprintf('%s (%d)', $provider, count($items)), [
                    'uri' => $this->router->generate('app_homepage') . '#' . $provider,
                ]);
            }
        }
    }
}
