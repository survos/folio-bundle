<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Menu;

use Survos\FolioBundle\Service\FolioService;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\RouterInterface;

class FolioMenu extends AbstractAdminMenuSubscriber
{
    public function __construct(
        private readonly FolioService $folioService,
        ?RouterInterface $router = null,
    ) {
        parent::__construct($router);
    }

    protected function getLabel(): string { return 'Folio'; }
    protected function getGroupIcon(): ?string { return 'mdi:database-outline'; }
    protected function getResourceClasses(): array { return []; }
    protected function getBrowseRoute(): ?string { return null; }

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $submenu = $this->addSubmenu($menu, $this->getLabel(), $this->getGroupIcon());

        $this->add($submenu, 'survos_folio_collection', label: 'Folios', icon: 'mdi:database-search');
        $this->add($submenu, 'survos_folio_schema', ['provider' => '_bootstrap', 'dataset' => '_bootstrap'], 'Bootstrap Schema', icon: 'mdi:table-cog');
    }
}
