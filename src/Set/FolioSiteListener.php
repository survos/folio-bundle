<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Set;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Host → site. Puts the folio site serving this host on the request as `_folio_site`, and for a
 * site with `restrict: true` 404s any folio outside it — what TobaccoSiteListener did for one host,
 * for every configured site. Runs after the router (priority 32) so `folioCode` is known.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final readonly class FolioSiteListener
{
    public function __construct(private FolioSiteRegistry $sites) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->sites->all() === []) {
            return;
        }
        $request = $event->getRequest();
        $site = $this->sites->forHost($request->getHost());
        $request->attributes->set('_folio_site', $site);
        if ($site === null || !$this->sites->get($site)['restrict']) {
            return;
        }

        $folioCode = $request->attributes->get('folioCode');
        if (is_string($folioCode) && $folioCode !== '' && !$this->sites->contains($site, $folioCode)) {
            throw new NotFoundHttpException(sprintf('Folio "%s" is not part of this site.', $folioCode));
        }
    }
}
