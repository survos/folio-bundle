<?php

declare(strict_types=1);

namespace Survos\FolioBundle\EventListener;

use Survos\FolioBundle\Attribute\FolioContext;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Event\FolioContextResolvedEvent;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ControllerAttributeEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS . '.' . FolioContext::class)]
final class FolioContextListener
{
    public function __construct(
        private readonly FolioService $folioService,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * @param ControllerAttributeEvent<FolioContext> $event
     */
    public function __invoke(ControllerAttributeEvent $event): void
    {
        $attr    = $event->attribute;
        $request = $event->kernelEvent->getRequest();

        $provider = $request->attributes->get($attr->providerParam, '');
        $dataset  = $request->attributes->get($attr->datasetParam, '');

        if (!$provider || !$dataset) {
            return;
        }

        $folioCode = "$provider/$dataset";

        $this->folioService->switch($folioCode);

        $ctx = $this->folioService->context($folioCode);
        $folio = $ctx->em->find(Folio::class, $ctx->folioCode);

        $request->attributes->set('current_folio_code', $ctx->folioCode);
        $request->attributes->set(
            'current_folio_title',
            $folio?->label ?: $ctx->folioCode
        );

        // folio-bundle stops here — it has no concept of "tenant" or host-app chrome. A host
        // app listens for this to resolve whatever else IT needs (a Tenant entity, which layout
        // to render, …) and stash it on the request before rendering starts.
        $this->eventDispatcher->dispatch(new FolioContextResolvedEvent($ctx->folioCode, $provider, $dataset, $request));
    }
}
