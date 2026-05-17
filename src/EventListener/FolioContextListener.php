<?php

declare(strict_types=1);

namespace Survos\FolioBundle\EventListener;

use Survos\FolioBundle\Attribute\FolioContext;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerAttributeEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS . '.' . FolioContext::class)]
final class FolioContextListener
{
    public function __construct(private readonly FolioService $folioService) {}

    /**
     * @param ControllerAttributeEvent<FolioContext> $event
     */
    public function __invoke(ControllerAttributeEvent $event): void
    {
        $attr    = $event->attribute;
        $request = $event->kernelEvent->getRequest();

        $provider = $request->attributes->get($attr->providerParam, '');
        $dataset  = $request->attributes->get($attr->datasetParam, '');

        if ($provider && $dataset) {
            $this->folioService->switch("$provider/$dataset");
        }
    }
}
