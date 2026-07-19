<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/**
 * Resolves a public folio slug (e.g. "jarc") to its internal folio code
 * ("provider/dataset", e.g. "mus/jarc"). folio-bundle has no opinion on how
 * slugs are assigned or stored — the host app owns that registry (in zm,
 * App\Entity\FolioRegistration::$code via FolioCodeAssigner).
 *
 * Not implementing this in an app is fine: FolioRouteAttributeListener treats a
 * missing resolver as "slug routing unavailable" and 404s slug URLs while
 * leaving the existing /{folioCode}/... routes untouched. See
 * survos-sites/scanseum#12.
 */
interface FolioSlugResolverInterface
{
    /** Returns the folio code ("provider/dataset") for $slug, or null if unknown. */
    public function resolve(string $slug): ?string;
}
