<?php

declare(strict_types=1);

namespace Survos\FolioBundle\EventListener;

use Survos\FolioBundle\Service\FolioService;
use Survos\FolioBundle\Service\FolioSlugResolverInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Normalizes two URL conventions into plain request attributes before any controller code
 * runs, so no individual route/controller has to know about either one:
 *
 * - Public slug (survos-sites/scanseum#12): {slug} → {provider}/{dataset}, via
 *   FolioSlugResolverInterface.
 * - Content locale suffix (survos-sites/scanseum#18): a trailing ".{locale}" on {dataset} or
 *   {slug} (e.g. "jarc.en") is stripped and set as FolioService::$requestContentLocale, which
 *   every context()/path()/switch() call falls back to unless a caller explicitly overrides it.
 *   "jarc.en" is chosen deliberately over a locale path segment or query param — it reads as
 *   one identifier (this folio, in English) and needs no route changes: Symfony's default
 *   route-parameter requirement is `[^/]+`, which already allows dots.
 *
 * Runs on kernel.request, after RouterListener (priority 32) has populated $request attributes
 * from the matched route, but before kernel.controller_arguments resolves those attributes into
 * controller method arguments. Doing this from a kernel.controller_arguments listener would be
 * too late — arguments are already resolved by the time that event fires.
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

        foreach (['dataset', 'slug'] as $param) {
            $value = $request->attributes->get($param);
            if ($value === null || !str_contains($value, '.')) {
                continue;
            }

            [$bare, $locale] = explode('.', $value, 2);
            $request->attributes->set($param, $bare);
            $request->attributes->set('content_locale', $locale);
            $this->folioService->requestContentLocale = $locale;
        }

        $slug = $request->attributes->get('slug');
        if ($slug === null) {
            return;
        }

        $folioCode = $this->slugResolver?->resolve($slug);
        if ($folioCode === null) {
            throw new NotFoundHttpException(sprintf('Unknown folio slug "%s".', $slug));
        }

        [$provider, $dataset] = explode('/', $folioCode, 2);
        $request->attributes->set('provider', $provider);
        $request->attributes->set('dataset', $dataset);
    }
}
