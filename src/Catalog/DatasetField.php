<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Catalog;

/** Wire vocabulary for the version 1 dataset publication API. */
final class DatasetField
{
    public const FOLIO_TYPE = 'folio';
    public const FOLIO_ARCHIVE_TYPE = 'folio_archive';
    public const DEFAULT_CODE = 'default';
    public const FOLIOS = 'folios';
    public const RECEIPTS = 'receipts';
    public const VERSION = 'version';
    public const ITEMS = 'items';
    public const DATASET_KEY = 'datasetKey';
    public const ARTIFACTS = 'artifacts';
    public const CODE = 'code';
    public const PROVIDER = 'provider';
    public const LOCALE = 'locale';
    public const SIZE_BYTES = 'sizeBytes';
    public const CHECKSUM = 'checksum';
    public const UPDATED_AT = 'updatedAt';
    public const DTO_COUNTS = 'dtoCounts';
    public const REVISION = 'revision';
    public const AVAILABLE = 'available';
    public const DOWNLOAD_URL = 'downloadUrl';
    public const COMPRESSED = 'compressed';
    public const CURSOR = 'cursor';
    public const THROUGH = 'through';
    public const NEXT = 'next';
    public const HAS_MORE = 'hasMore';
    public const OPERATION = 'operation';
    public const RESOURCE = 'resource';
    public const ARTIFACT_ID = 'artifactId';
    public const OCCURRED_AT = 'occurredAt';
    public const ERROR = 'error';
    public const SOURCE = 'source';
    public const FETCHED_AT = 'fetchedAt';
    public const LAST_SUCCESSFUL_SYNC_AT = 'lastSuccessfulSyncAt';
}
