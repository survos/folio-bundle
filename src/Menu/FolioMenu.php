<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Menu;

use Survos\FolioBundle\Service\FolioService;
use Survos\MenuBundle\Attribute\AsMenuListener;
use Survos\MenuBundle\Event\MenuEvent;
use Survos\MenuBundle\MenuSlot;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Symfony\Component\Routing\RouterInterface;

class FolioMenu extends AbstractAdminMenuSubscriber
{
    public function __construct(
        private readonly FolioService $folioService,
        private readonly string $routePrefix,
        private readonly ?string $folioServer = null,
        ?RouterInterface $router = null,
    ) {
        parent::__construct($router);
    }

    protected function getLabel(): string
    {
        return 'Folio';
    }

    protected function getGroupIcon(): ?string
    {
        return 'mdi:database-outline';
    }

    protected function getResourceClasses(): array
    {
        return [];
    }

    protected function getBrowseRoute(): ?string
    {
        return null;
    }

    #[AsMenuListener(MenuSlot::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $submenu = $this->addSubmenu($menu, $this->getLabel(), $this->getGroupIcon());

        if (null !== $this->folioServer && '' !== $this->folioServer) {
            $base = rtrim($this->folioServer, '/');
            $this->add($submenu, label: 'Folios', uri: $base.'/folios', icon: 'mdi:database-search');
            $this->add($submenu, label: 'Bootstrap Schema', uri: $base.$this->routePrefix.'/_bootstrap/_bootstrap/schema', icon: 'mdi:table-cog');

            return;
        }

        $this->add($submenu, 'survos_folio_schema', ['folioCode' => '_bootstrap/_bootstrap'], 'Bootstrap Schema', icon: 'mdi:table-cog');
    }
}
