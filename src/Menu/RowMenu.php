<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Menu;

use Survos\DataContracts\Metadata\ContentType;
use Survos\FolioBundle\Entity\Page;
use Survos\FolioBundle\Entity\Row;
use Survos\FolioBundle\Enum\PageType;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Survos\TablerBundle\Service\IconService;
use Survos\TablerBundle\Service\RouteAliasService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\RouterInterface;

/**
 * Document-level action menu for the row-scoped folio pages (row/show, item_chat, page_chat,
 * ai) — populated whenever row/base.html.twig sets the `row` menu option. On by default for
 * every app using folio-bundle; a host app overrides it by defining its own PAGE_ACTIONS
 * listener at a higher priority and stopping propagation, or by not extending row/base.html.twig
 * at all.
 *
 * Edit/Bookmark point at app-defined route names (app_folio_row_edit, app_bookmark_row) that
 * this bundle doesn't itself provide — MenuBuilderTrait::add()'s checkRouteExists (on by
 * default) silently drops the item in any app that doesn't define them, rather than erroring.
 *
 * Rendered ONLY via base.html.twig's own automatic PAGE_ACTIONS-in-page-header mechanism — the
 * same one every other page uses. row/show.html.twig does NOT render its own competing action
 * bar (it used to, both a bundle-default hardcoded one and a per-app opt-in override; both were
 * removed because that split mechanism kept silently drifting out of sync with this one — see
 * git history around 2026-07-18). Don't reintroduce a `folio_detail_actions`-style block; if a
 * host app needs this menu to look different, style PAGE_ACTIONS itself (component('tabler:menu',
 * {type: PAGE_ACTIONS, ...}) is what base.html.twig calls) rather than rendering a parallel copy.
 */
final class RowMenu
{
    use MenuBuilderTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
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

        $this->add($menu, 'survos_folio_row_show', $row, 'Show', icon: 'tabler:eye');
        $this->add($menu, 'app_folio_row_edit', $rp, 'Edit', icon: 'tabler:pencil');

        // Opens the per-row "which folder?" page (app_bookmark_row) rather than toggling a bare
        // flag — there's a real folder/notes system behind bookmarks (BookmarkManager, App\Service\
        // BookmarkService) and this is where the user picks/creates a folder and jots why.
        $this->add($menu, 'app_bookmark_row', $rp, 'Bookmark', icon: 'tabler:bookmark');

        // Ask document ("Ask the Scholar") chats off the row's DTO metadata/description — it
        // renders fine with zero pages (item_chat.html.twig has an explicit "No image available"
        // / no-pages fallback), so it belongs on every row, not just image-bearing ones. This used
        // to be gated the same as OCR/Handwriting/Ask page (which genuinely need Page entities to
        // work from) and silently disappeared for page-less rows (e.g. a YouTube video item) as a
        // result — that was the wrong spot for this gate, not a deliberate restriction.
        $this->add($menu, 'survos_folio_item_chat', $rp, 'Ask document', icon: 'tabler:sparkles');

        if (!$row->pages->isEmpty()) {
            // A row's pages are audio for interviews/film-as-audio content (AssemblyAI transcripts,
            // TEI-parsed interview parts) — OCR/handwriting recognition is meaningless there, and
            // "Ask page" scoped to one part of a multi-part interview has too little context to be
            // worth a dedicated action (unlike a scanned document, where each page IS a distinct unit).
            $isAudio = $row->pages->exists(static fn (int $i, Page $page): bool => $page->type === PageType::Audio);

            if ($row->pages->count() > 1 && $row->dtoType !== ContentType::INTERVIEW) {
                $this->add(
                    $menu,
                    'survos_folio_page_chat',
                    $rp + ['seq' => $row->pages->first()->seq],
                    'Ask page',
                    icon: 'tabler:message-chatbot',
                );
            }

            // survos_folio_ai's route has no {dtoType} segment, unlike $rp -- extra params not
            // in a route's pattern get appended as a query string instead of silently dropped,
            // so this stays an explicit subset rather than just reusing $rp directly.
            $aiParams = [
                'folioCode' => $rp['folioCode'],
                'coreCode' => $rp['coreCode'],
                'localId' => $rp['localId'],
            ];

            // 'dev'-only: OCR/handwriting recognition isn't tuned/reliable enough yet to expose to
            // production users. Also never shown for audio, dev or not (see $isAudio above).
            if ('dev' === $this->environment && !$isAudio) {
                $this->add($menu, 'survos_folio_ai', $aiParams + ['task' => 'ocr_mistral', 'run' => 1, 'page' => 0], 'OCR', icon: 'tabler:file-text');
                $this->add($menu, 'survos_folio_ai', $aiParams + ['task' => 'handwriting', 'run' => 1, 'page' => 0], 'Handwriting', icon: 'tabler:writing');
            }
        }

        // Not wired yet — "share" hasn't been defined (copy link? citation? social?). Kept as a
        // disabled placeholder, same pattern as Slideshow/Bookbag in host apps' folio-level menu:
        // the slot exists so the menu shape is settled, behavior comes once it's decided.
        $share = $this->add($menu, label: 'Share', uri: '#', icon: 'tabler:share', checkRouteExists: false);
        $share->setLinkAttribute('tabindex', '-1');
        $share->setLinkAttribute('aria-disabled', 'true');
    }
}
