<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Exception;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * No folio file for the requested code on this host.
 *
 * A request naming a folio that is not here (a dropped snapshot, a mistyped code, a folio built on
 * another machine) is a 404, not a server error: it was answered with a 500 carrying the absolute
 * file path. HttpException extends \RuntimeException, so callers that caught the old
 * RuntimeException still catch this.
 */
final class FolioNotFoundException extends NotFoundHttpException
{
    public function __construct(
        public readonly string $folioCode,
        public readonly string $path,
    ) {
        parent::__construct(sprintf('Folio "%s" not found. Run folio:migrate first.', $folioCode));
    }
}
