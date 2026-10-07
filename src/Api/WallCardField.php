<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Api;

/** Source DTO fields not yet represented in ItemField/MuseumVocab. */
final class WallCardField
{
    public const string DIMENSIONS_RAW = 'dimensionsRaw';
    public const string SOURCE_CAPTION = 'sourceCaption';
    public const string SOURCE_URL = 'sourceUrl';
    public const string DONOR = 'donor';
    public const string TOMBSTONE = 'tombstone';
    public const string CREDIT_LINE = 'creditline';
    public const string RIGHTS_URI = 'rightsUri';
    public const string LATITUDE = 'latitude';
    public const string LONGITUDE = 'longitude';
    public const string TAGS = 'tags';
}
