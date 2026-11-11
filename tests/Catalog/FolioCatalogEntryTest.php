<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Catalog\DatasetField as Field;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Symfony\Component\HttpClient\MockHttpClient;

final class FolioCatalogEntryTest extends TestCase
{
    private function dataset(array $artifacts): array
    {
        return ['datasetKey' => 'mus/scrabble', 'provider' => 'mus', 'label' => 'Scrabble interviews',
            'description' => null, 'locale' => 'en', 'tags' => ['oral-history'], 'contentType' => 'interview',
            'rowCount' => 21, 'artifacts' => $artifacts];
    }

    private function artifact(string $type, string $code, bool $available = true): array
    {
        return ['type' => $type, 'code' => $code, 'available' => $available,
            'revision' => $type.':'.$code, 'sizeBytes' => 123, 'checksum' => null, 'updatedAt' => null,
            'compressed' => Field::FOLIO_ARCHIVE_TYPE === $type,
            'remoteUri' => str_starts_with($code, 's3') ? 's3://bucket/dfolio/mus/scrabble.dfolio' : null,
            'downloadUrl' => str_starts_with($code, 's3') ? null : '/api/datasets/mus/scrabble/artifacts/1/download'];
    }

    private function client(): DatasetPublicationClient
    {
        return new DatasetPublicationClient(new MockHttpClient(), 'https://harvest.example', '');
    }

    public function testPublishedDfolioIsDiscoverableWithoutSqlite(): void
    {
        $local = $this->artifact(Field::DUCKDB_TYPE, Field::DEFAULT_CODE);
        $remote = $this->artifact(Field::DUCKDB_TYPE, Field::S3_CODE);
        foreach ([[$local, $remote], [$remote, $local]] as $copies) {
            $entries = FolioCatalogEntry::fromDataset($this->dataset($copies), $this->client());
            self::assertCount(1, $entries);
            self::assertSame('mus/scrabble', $entries[0]->datasetKey);
            self::assertSame(21, $entries[0]->rowCount);
            self::assertNull($entries[0]->locale);
            self::assertSame(Field::DUCKDB_TYPE, $entries[0]->artifactType);
            self::assertSame(Field::S3_CODE, $entries[0]->artifactCode());
            self::assertNull($entries[0]->downloadUrl);
        }
    }

    public function testUnavailableDfolioAndSqliteOnlyAreHiddenFromReaderCatalog(): void
    {
        $data = $this->dataset([$this->artifact(Field::DUCKDB_TYPE, 's3', false),
            $this->artifact(Field::FOLIO_TYPE, 'default'), $this->artifact(Field::FOLIO_ARCHIVE_TYPE, 'default')]);
        self::assertSame([], FolioCatalogEntry::fromDataset($data, $this->client()));
        $archives = FolioCatalogEntry::fromSqliteDataset($data, $this->client());
        self::assertCount(1, $archives);
        self::assertSame(Field::FOLIO_ARCHIVE_TYPE, $archives[0]->artifactType);
        self::assertTrue($archives[0]->compressed);
        self::assertSame('default', $archives[0]->artifactCode());
    }

    public function testTranslationsAreDeduplicatedAndArchiveDoesNotReplaceDfolio(): void
    {
        $data = $this->dataset([$this->artifact(Field::FOLIO_ARCHIVE_TYPE, 'default'),
            $this->artifact(Field::DUCKDB_TYPE, 's3'), $this->artifact(Field::DUCKDB_TYPE, 'es'),
            $this->artifact(Field::DUCKDB_TYPE, 's3.es')]);
        $entries = FolioCatalogEntry::fromDataset($data, $this->client());
        self::assertCount(2, $entries);
        self::assertSame([null, 'es'], array_column($entries, 'locale'));
        self::assertSame('s3.es', $entries[1]->artifactCode());
        self::assertSame('s3.es', FolioCatalogEntry::fromArray(get_object_vars($entries[1]))->artifactCode());
    }
}
