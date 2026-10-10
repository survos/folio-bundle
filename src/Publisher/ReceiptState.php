<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Publisher;

/** What a receipt says about a variant. Withdrawal is always explicit, never inferred. */
enum ReceiptState: string
{
    case Published = 'published';
    case Withdrawn = 'withdrawn';
}
