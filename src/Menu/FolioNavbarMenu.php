<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Menu;

use Knp\Menu\ItemInterface;
use Survos\FolioBundle\Entity\Core;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Service\FolioFacetFieldResolver;
use Survos\FolioBundle\Service\FolioService;
use Survos\MenuBundle\Attribute\AsMenuListener;
use Survos\MenuBundle\Event\MenuEvent;
use Survos\MenuBundle\MenuSlot;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The folio-level navigation in the secondary navbar row: how a visitor moves around ONE folio
 * (Search, Gallery, Map, Timeline, Chat, Term Cloud). Keyed on the `folio` menu option, the real
 * Folio entity that folio/base.html.twig and folio/row/base.html.twig set -- not on a tenant or a
 * folio set, which are different layers and are what this menu used to be confused with.
 *
 * Overview (the dataset dashboard) and Schema are admin-only: they are provenance/debugging pages
 * and confused visitors. A host app adds its own entries (zm adds Museum) with another listener
 * on NAVBAR_MENU_END at a lower priority, or replaces this menu by setting
 * `survos_folio.folio_navbar_menu: false` and registering its own.
 *
 * Moved here from zm's App\Menu\FolioMenu (2026-10-19) so fotostory and every other app get a
 * folio menu instead of a bare page.
 */
final class FolioNavbarMenu
{
    use MenuBuilderTrait;

    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioFacetFieldResolver $facetFieldResolver,
        private readonly TranslatorInterface $translator,
        protected readonly ?RouterInterface $router = null,
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
        private readonly ?\Symfony\Component\HttpFoundation\RequestStack $requestStack = null,
    ) {
    }

    #[AsMenuListener(MenuSlot::NAVBAR_MENU_END, priority: 50)]
    public function navbarMenu(MenuEvent $event): void
    {
        $folio = $event->getOption('folio');
        if (!$folio instanceof Folio) {
            return;
        }

        $rp = $folio->getRp();
        $menu = $event->getMenu();
        if ('duckdb' === $this->requestStack?->getCurrentRequest()?->attributes->get('_folio_reader')) {
            $this->item($menu, 'Search', 'survos_folio_duckdb_search', $rp, 'tabler:search');

            return;
        }
        $admin = $this->isAdmin();

        if ($admin) {
            $this->item($menu, 'Overview', 'survos_folio_show', $rp, 'tabler:layout-dashboard');
        }
        $this->item($menu, 'Search', 'survos_folio_search', $rp, 'tabler:search');
        $this->item($menu, 'Gallery', 'survos_folio_gallery', $rp, 'tabler:photo');
        $this->item($menu, 'Map', 'survos_folio_map', $rp, 'tabler:map-2');

        $core = $this->defaultCore($folio);
        if (null !== $core) {
            // The year-ordered walk through the folio: the timeline strip + slideshow.
            $this->item($menu, 'Timeline', 'survos_folio_slideshow', $rp + ['coreCode' => $core], 'tabler:timeline');
        }

        $this->item($menu, 'Chat', 'survos_folio_chat', $rp, 'tabler:message-chatbot');
        if ($admin) {
            $this->item($menu, 'Schema', 'survos_folio_schema', $rp, 'tabler:database');
        }

        $this->addTermCloudMenu($menu, $folio, $rp);
    }

    /** @param array<string, mixed> $rp */
    private function item(ItemInterface $menu, string $label, string $route, array $rp, string $icon): void
    {
        // Same guard as MenuBuilderTrait::add(): an app that disabled or renamed a route just
        // doesn't get the entry, rather than a generation error on every folio page.
        if (null !== $this->router && null === $this->router->getRouteCollection()->get($route)) {
            return;
        }
        $menu->addChild($label, ['route' => $route, 'routeParameters' => $rp])->setExtra('icon', $icon);
    }

    /** The "obj" core when there is one, else the largest -- the same fallback survos_folio_gallery uses. */
    private function defaultCore(Folio $folio): ?string
    {
        $cores = $this->folios->context($folio->code)->em->getRepository(Core::class)->findBy([], ['rowCount' => 'DESC']);
        foreach ($cores as $core) {
            if ('obj' === $core->code) {
                return $core->code;
            }
        }

        return $cores[0]->code ?? null;
    }

    private function isAdmin(): bool
    {
        try {
            return $this->authorizationChecker?->isGranted('ROLE_ADMIN') ?? false;
        } catch (\Throwable) {
            return false; // no token on this request (firewall-less route)
        }
    }

    /**
     * A dropdown of every facet field this folio's search sidebar exposes (Subjects, Tags,
     * Country, ...), each linking straight to that field's cloud.
     *
     * @param array<string, mixed> $rp
     */
    private function addTermCloudMenu(ItemInterface $menu, Folio $folio, array $rp): void
    {
        $connection = $this->folios->context($folio->code)->em->getConnection();
        $fields = $this->facetFieldResolver->refinementFieldNames($connection);
        if ([] === $fields) {
            return;
        }

        $submenu = $this->addSubmenu($menu, 'Term Cloud', icon: 'tabler:tags', translationDomain: 'routing');
        foreach ($fields as $field) {
            $label = $this->facetFieldResolver->translatedLabel($this->translator, $field['name'], $field['label']);
            $this->add(
                $submenu,
                'survos_folio_termset_wordcloud',
                array_merge($rp, ['field' => $field['name']]),
                label: $label,
                translationDomain: false,
            );
        }
    }
}
