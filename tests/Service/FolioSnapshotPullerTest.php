<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Path\DataPaths;
use Survos\FolioBundle\Service\FolioSnapshotPuller;
use Symfony\Component\Filesystem\Filesystem;

final class FolioSnapshotPullerTest extends TestCase
{
    private string $directory;
    private string $snapshot;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/pull-test-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
        $this->snapshot = $this->directory.'/source.dfolio';
        $db = new \PDO('duckdb:'.$this->snapshot);
        $db->exec("CREATE TABLE _folio_build AS SELECT 'build-1' AS build_id; CHECKPOINT");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testLocalObjectWinsAndExistingFileSkipsNetwork(): void
    {
        $remote = $this->createMock(FilesystemOperator::class);
        $remote->expects(self::never())->method('fileExists');
        $puller = new FolioSnapshotPuller(new DataPaths($this->directory), remote: $remote, local: $this->available());
        $result = $puller->pull($this->entry());
        self::assertSame('local', $result['source']);
        self::assertFileEquals($this->snapshot, $result['path']);
        self::assertTrue($puller->pull($this->entry())['reused']);
    }

    public function testMissingLocalObjectUsesRemote(): void
    {
        $local = $this->createMock(FilesystemOperator::class);
        $local->expects(self::once())->method('fileExists')->willReturn(false);
        $result = (new FolioSnapshotPuller(new DataPaths($this->directory), remote: $this->available(), local: $local))->pull($this->entry());
        self::assertSame('remote', $result['source']);
    }

    public function testTruncatedDownloadKeepsExistingSnapshot(): void
    {
        $paths = new DataPaths($this->directory);
        $target = $paths->folioFile('news/test', 'dfolio', true);
        copy($this->snapshot, $target);
        try {
            (new FolioSnapshotPuller($paths, local: $this->available(truncated: true)))->pull($this->entry(), true);
            self::fail('Expected validation failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('size does not match', $e->getMessage());
        }
        self::assertFileEquals($this->snapshot, $target);
        self::assertSame([], glob(dirname($target).'/.pull-*'));
    }

    public function testStaleLocalCopyFallsBackToPublishedRemote(): void
    {
        $local = $this->createMock(FilesystemOperator::class);
        $local->expects(self::once())->method('fileExists')->willReturn(true);
        $local->method('fileSize')->willReturn(5);
        $local->method('readStream')->willReturnCallback(static function () {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, 'stale');
            rewind($stream);

            return $stream;
        });
        $result = (new FolioSnapshotPuller(new DataPaths($this->directory), remote: $this->available(), local: $local))->pull($this->entry());
        self::assertSame('remote', $result['source']);
        self::assertFileEquals($this->snapshot, $result['path']);
    }

    public function testStorageErrorsAreNotTreatedAsMissingObjects(): void
    {
        $local = $this->createMock(FilesystemOperator::class);
        $local->expects(self::once())->method('fileExists')->willThrowException(\League\Flysystem\UnableToCheckFileExistence::forLocation('dfolio/news/test.dfolio'));
        $remote = $this->createMock(FilesystemOperator::class);
        $remote->expects(self::never())->method('fileExists');
        $this->expectException(\League\Flysystem\UnableToCheckFileExistence::class);
        (new FolioSnapshotPuller(new DataPaths($this->directory), remote: $remote, local: $local))->pull($this->entry());
    }

    public function testANewspaperPullBringsItsRendAndWordIndex(): void
    {
        $bucket = $this->directory.'/bucket';
        (new Filesystem())->mkdir($bucket.'/dfolio/news');
        $paper = $bucket.'/dfolio/news/test.dfolio';
        $db = new \PDO('duckdb:'.$paper);
        $db->exec("CREATE TABLE _folio_build AS SELECT 'build-1' AS build_id; CREATE TABLE _renditions AS SELECT 'i1' AS issue_id, 's3://survos-platform/dfolio/news/test.drend' AS uri; CHECKPOINT");
        $db = null;
        file_put_contents($bucket.'/dfolio/news/test.drend', 'rend');
        file_put_contents($bucket.'/dfolio/news/test.dwords', 'words');
        $storage = new \League\Flysystem\Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter($bucket));
        $paths = new DataPaths($this->directory);
        $entry = new \Survos\FolioBundle\Catalog\FolioCatalogEntry('news/test', 'news', 'test', 'Test', checksum: hash_file('sha256', $paper), remoteUri: 's3://survos-platform/dfolio/news/test.dfolio');
        $result = (new FolioSnapshotPuller($paths, remote: $storage))->pull($entry);
        $local = substr($result['path'], 0, -strlen('.dfolio'));
        self::assertStringEqualsFile($local.'.drend', 'rend');
        self::assertStringEqualsFile($local.'.dwords', 'words');

        // An existing dfolio whose rend is missing is completed, not reported as current.
        unlink($local.'.drend');
        self::assertTrue((new FolioSnapshotPuller($paths, remote: $storage))->pull($entry)['reused']);
        self::assertStringEqualsFile($local.'.drend', 'rend');

        unlink($local.'.drend');
        unlink($bucket.'/dfolio/news/test.drend');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rend');
        (new FolioSnapshotPuller($paths, remote: $storage))->pull($entry);
    }

    private function entry(): \Survos\FolioBundle\Catalog\FolioCatalogEntry
    {
        return new \Survos\FolioBundle\Catalog\FolioCatalogEntry('news/test', 'news', 'test', 'Test', checksum: hash_file('sha256', $this->snapshot), remoteUri: 's3://survos-platform/dfolio/news/test.dfolio');
    }

    private function available(bool $truncated = false): FilesystemOperator
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())->method('fileExists')->with('dfolio/news/test.dfolio')->willReturn(true);
        $storage->method('fileSize')->willReturn(filesize($this->snapshot));
        $storage->expects(self::once())->method('readStream')->willReturnCallback(function () use ($truncated) {
            if (!$truncated) {
                return fopen($this->snapshot, 'rb');
            }
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, 'bad');
            rewind($stream);

            return $stream;
        });

        return $storage;
    }
}
