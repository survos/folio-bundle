<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Publisher;

/** Harvest's answer to one receipt. Every case is an acknowledgement: none is ever resent. */
enum ReceiptStatus: string
{
    /** Stored. */
    case Applied = 'applied';
    /** An identical receipt was already stored — a retry. */
    case Unchanged = 'unchanged';
    /** Harvest holds a newer event (occurredAt) for this variant. */
    case Superseded = 'superseded';
    /** No such dataset/artifact variant in Harvest: it was deleted there. */
    case Unknown = 'unknown';
}
