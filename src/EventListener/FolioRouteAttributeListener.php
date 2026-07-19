<?php

declare(strict_types=1);

namespace Survos\FolioBundle\EventListener;

use Survos\FolioBundle\Service\FolioService;
use Survos\FolioBundle\Service\FolioSlugResolverInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Normalizes a folio-scoped request down to the one thing every folio route/controller
 * needs: the shared per-folio SQLite connection pointed at the right file, before controller
 * arguments are resolved. Single job now that routing is keyed on one {folioCode} param
 * (survos-sites/scanseum#12/#18) instead of the old {provider}/{dataset} pair:
 *
 * - Public slug: a {folioCode} with no "/" is a short public slug (zm's registry), resolved to
 *   a real "provider/dataset" folio code via FolioSlugResolverInterface. A missing resolver
 *   (not configured) or an unknown slug both 404. A {folioCode} that already contains "/" is
 *   used directly — no separate {slug} route needed, both shapes travel through the same
 *   single path param (see FolioController's route requirement).
 * - Content locale suffix: a trailing ".{locale}" on {folioCode} (e.g. "jarc.en" or "larco.en")
 *   is stripped *before* slug resolution and set as FolioService::$requestContentLocale, which
 *   every context()/path()/switch() call falls back to unless a caller explicitly overrides it.
 *   The folio code itself never carries locale — only the physical file variant does — so
 *   stripping first and resolving the bare code is correct for both slugs and real codes.
 *
 * Once this listener has run, `Folio $folio` (and any other #[RouteIdentity]-carrying entity
 * scoped to the same connection, e.g. Core/Row) resolves normally via field-bundle's generic
 * RouteIdentityValueResolver — no folio-bundle-specific argument resolver needed.
 *
 * Runs on kernel.request, after RouterListener (priority 32) has populated $request attributes
 * from the matched route, but before kernel.controller_arguments resolves those attributes into
 * controller method arguments — doing this from a kernel.controller_arguments listener would be
 * too late.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class FolioRouteAttributeListener
{
    public function __construct(
        private readonly FolioService $folioService,
        private readonly ?FolioSlugResolverInterface $slugResolver = null,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        $folioCode = $request->attributes->get('folioCode');
        if ($folioCode === null) {
            return;
        }

        $folioCode = $this->stripLocaleSuffix($folioCode, $request);

        if (!str_contains($folioCode, '/')) {
            $resolved = $this->slugResolver?->resolve($folioCode);
            if ($resolved === null) {
                throw new NotFoundHttpException(sprintf('Unknown folio slug "%s".', $folioCode));
            }
            $folioCode = $resolved;
        }

        $request->attributes->set('folioCode', $folioCode);
        $this->folioService->switch($folioCode);
    }

    private function stripLocaleSuffix(string $value, Request $request): string
    {
        if (!str_contains($value, '.')) {
            return $value;
        }

        [$bare, $locale] = explode('.', $value, 2);
        $request->attributes->set('content_locale', $locale);
        $this->folioService->requestContentLocale = $locale;

        return $bare;
    }
}
