<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Event;

use Symfony\Component\HttpFoundation\Request;

/**
 * Dispatched once folio-bundle has resolved which folio (provider/dataset) the current request
 * is for — from FolioContextListener, itself triggered by the #[FolioContext] controller
 * attribute (a kernel.controller_arguments.<AttributeClass> listener). folio-bundle deliberately
 * has no concept of "tenant" or host-app chrome; this is the seam a host app hooks into to
 * resolve whatever ELSE it needs from that folio code (a Tenant entity, which base layout to
 * render, …) and stash it on the request for templates to read, before rendering starts — see
 * openfoto's FolioTenantContextListener for the concrete example.
 */
final readonly class FolioContextResolvedEvent
{
    public function __construct(
        public string $folioCode,
        public string $provider,
        public string $dataset,
        public Request $request,
    ) {
    }
}
