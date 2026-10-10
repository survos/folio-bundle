<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Catalog;

/** Downloaded artifact bytes do not match the provider's X-Artifact-Sha256. */
final class ArtifactChecksumException extends \UnexpectedValueException
{
}
