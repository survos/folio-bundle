<?php

declare(strict_types=1);

namespace Survos\FolioBundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\{RequestEvent, ResponseEvent};
use Symfony\Component\HttpKernel\KernelEvents;

final class WallCardResponseListener
{
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 300)]
    public function preflight(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->isMethod('OPTIONS') && preg_match('~^/api/[^/]+(?:/[^/]+)?/rows$~D', $request->getPathInfo())) {
            $request->attributes->set('_wallcard_response', true);
            $event->setResponse(new Response(status: 204));
        }
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 18)]
    public function mark(RequestEvent $event): void
    {
        // Mark before folio resolution so errors (unknown folio, invalid filters) also carry CORS.
        if ($event->getRequest()->attributes->get('_api_operation_name') === \Survos\FolioBundle\Entity\Row::API_ROWS) {
            $event->getRequest()->attributes->set('_wallcard_response', true);
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -512)]
    public function response(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->attributes->get('_wallcard_response')) { return; }
        $response = $event->getResponse();
        // Public catalogue data: allow sandboxed itch.io origins (including opaque/null origins).
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Accept, Content-Type, If-None-Match');
        $response->headers->set('Access-Control-Expose-Headers', 'ETag, Cache-Control');
        $response->headers->set('Access-Control-Max-Age', '3600');
        if ($response->isSuccessful() && !$request->isMethod('OPTIONS')) {
            $response->headers->set(\Symfony\Component\HttpKernel\EventListener\AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, '1');
            $response->setPublic()->setMaxAge(300)->setSharedMaxAge(300);
            $response->setEtag(hash('sha256', $response->getContent()));
            $response->isNotModified($request);
        }
    }
}
