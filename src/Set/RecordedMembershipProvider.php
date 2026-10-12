<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Set;

/** Optional application-owned membership source; consumers need no producer registry. */
interface RecordedMembershipProvider
{
    /** @return list<array<string, mixed>> */
    public function members(string $code): array;
}
