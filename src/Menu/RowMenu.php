<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Menu;

use Survos\FolioBundle\Entity\Row;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Survos\TablerBundle\Service\IconService;
use Survos\TablerBundle\Service\RouteAliasService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\RouterInterface;

/**
 * Document-level action menu for the row-scoped folio pages (detail, item_chat, page_chat,
 * ai) — populated whenever row/base.html.twig sets the `row` menu option. On by default for
 * every app using folio-bundle; a host app overrides it by defining its own PAGE_ACTIONS
 * listener at a higher priority and stopping propagation, or by not extending row/base.html.twig
 * at all.
 *
 * Edit/Bookmark point at app-defined route names (app_folio_row_edit, app_bookmark_row) that
 * this bundle doesn't itself provide — MenuBuilderTrait::add()'s checkRouteExists (on by
 * default) silently drops the item in any app that doesn't define them, rather than erroring.
 * Detail.html.twig's own folio_detail_actions block still renders its own hardcoded Bookmark
 * widget/Ask document/OCR/Handwriting by default (unchanged, so no visual change for apps not
 * opting in) — a host app opts into this menu there too by overriding that block to render
 * `{{ component('tabler:menu', {type: PAGE_ACTIONS, caller: _self}) }}` instead.
 */
final class RowMenu
{
    use MenuBuilderTrait;

    public function __construct(
        protected readonly ?RouterInterface $router = null,
        protected readonly ?RouteAliasService $routeAliasService = null,
        protected readonly ?IconService $iconService = null,
    ) {
    }

    #[AsEventListener(event: MenuEvent::PAGE_ACTIONS, priority: 50)]
    public function pageActions(MenuEvent $event): void
    {
        $row = $event->getOption('row');

        if (!$row instanceof Row) {
            return;
        }

        $rp = $row->getRp();
        $menu = $event->getMenu();

        $this->add($menu, 'survos_folio_row_show', $rp, 'Show', icon: 'tabler:eye');
        $this->add($menu, 'app_folio_row_edit', $rp, 'Edit', icon: 'tabler:pencil');

        // Opens the per-row "which folder?" page (app_bookmark_row) rather than toggling a bare
        // flag — there's a real folder/notes system behind bookmarks (BookmarkManager, App\Service\
        // BookmarkService) and this is where the user picks/creates a folder and jots why.
        $this->add($menu, 'app_bookmark_row', $rp, 'Bookmark', icon: 'tabler:bookmark');

        // Ask document / Ask page / OCR / Handwriting only make sense once there's imagery to
        // work from — same gate the bundle's own folio_detail_actions block uses (see
        // detail.html.twig: pageAiUrls|length > 0 or th).
        if (!$row->pages->isEmpty()) {
            $this->add($menu, 'survos_folio_item_chat', $rp, 'Ask document', icon: 'tabler:sparkles');

            if ($row->pages->count() > 1) {
                $this->add(
                    $menu,
                    'survos_folio_page_chat',
                    $rp + ['seq' => $row->pages->first()->seq],
                    'Ask page',
                    icon: 'tabler:message-chatbot',
                );
            }

            $aiParams = [
                'provider' => $rp['provider'],
                'dataset' => $rp['dataset'],
                'coreCode' => $rp['coreCode'],
                'localId' => $rp['localId'],
            ];

            $this->add($menu, 'survos_folio_ai', $aiParams + ['task' => 'ocr_mistral', 'run' => 1, 'page' => 0], 'OCR', icon: 'tabler:file-text');
            $this->add($menu, 'survos_folio_ai', $aiParams + ['task' => 'handwriting', 'run' => 1, 'page' => 0], 'Handwriting', icon: 'tabler:writing');
        }

        // Not wired yet — "share" hasn't been defined (copy link? citation? social?). Kept as a
        // disabled placeholder, same pattern as Slideshow/Bookbag in host apps' folio-level menu:
        // the slot exists so the menu shape is settled, behavior comes once it's decided.
        $share = $this->add($menu, label: 'Share', uri: '#', icon: 'tabler:share', checkRouteExists: false);
        $share->setLinkAttribute('tabindex', '-1');
        $share->setLinkAttribute('aria-disabled', 'true');
    }
}
