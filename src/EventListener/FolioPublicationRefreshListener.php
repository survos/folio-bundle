<?php

declare(strict_types=1);

namespace Survos\FolioBundle\EventListener;

use Survos\FolioBundle\Catalog\FolioCatalogClient;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/** Observe the sync process's catalog before routes, controllers or application caches run. */
final readonly class FolioPublicationRefreshListener
{
    public function __construct(private FolioCatalogClient $catalog)
    {
    }

    #[AsEventListener(priority: 512)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->catalog->reset();
            $this->catalog->all();
        }
    }
}
