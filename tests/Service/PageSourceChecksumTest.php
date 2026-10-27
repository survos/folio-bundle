<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Dto\FolioBuildInput;
use Survos\FolioBundle\Dto\PageDto;
use Survos\FolioBundle\Entity\Row;
use Survos\FolioBundle\Service\FolioIngestService;
use Symfony\Component\Filesystem\Filesystem;

final class PageSourceChecksumTest extends TestCase
{
    public function testPageStreamCarriesTheSourceChecksumIntoThePageTable(): void
    {
        $root = sys_get_temp_dir().'/page-source-checksum-'.bin2hex(random_bytes(8));
        mkdir($root);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $conn->executeStatement('CREATE TABLE item (id TEXT PRIMARY KEY, dto_data TEXT)');
            $conn->executeStatement('CREATE TABLE page (id TEXT PRIMARY KEY, row_id TEXT, seq INT, page_index INT, type TEXT, url TEXT, source_url TEXT, media_id TEXT, text TEXT, htr TEXT, dense_summary TEXT, ledger TEXT, layout TEXT, dialogue TEXT, width INT, height INT, thumb_hash TEXT, color TEXT, source_checksum TEXT)');
            $rowId = Row::id('oni/pennsylvania-sn1:doc', 'sn1-1857-06-24-ed-1');
            $conn->insert('item', ['id' => $rowId, 'dto_data' => '{}']);

            $file = $root.'/page.jsonl';
            file_put_contents($file, implode("\n", [
                json_encode(['coreCode' => 'doc', 'localId' => 'sn1-1857-06-24-ed-1', 'url' => 'https://example.test/1.jp2', 'seq' => 1, 'pageIndex' => 0, 'sourceChecksum' => '0123456789abcdef0123456789abcdef']),
                json_encode(['coreCode' => 'doc', 'localId' => 'sn1-1857-06-24-ed-1', 'url' => 'https://example.test/2.jp2', 'seq' => 2, 'pageIndex' => 1]),
                json_encode(['coreCode' => 'doc', 'localId' => 'sn1-1857-06-24-ed-1', 'url' => 'https://example.test/3.jp2', 'seq' => 3, 'pageIndex' => 2, 'sourceChecksum' => '']),
            ])."\n");

            $em = $this->createStub(EntityManagerInterface::class);
            $em->method('getConnection')->willReturn($conn);
            $reflection = new \ReflectionClass(FolioIngestService::class);
            $service = $reflection->newInstanceWithoutConstructor();
            $input = new FolioBuildInput('oni/pennsylvania-sn1', ['doc' => $file], [], 'en', files: ['page' => $file]);
            $reflection->getMethod('ingestPages')->invoke($service, $em, $input, 500, 0, null);

            self::assertSame(
                ['0123456789abcdef0123456789abcdef', null, null],
                $conn->fetchFirstColumn('SELECT source_checksum FROM page ORDER BY seq'),
                'A page keeps its checksum; a page without one, or with an empty one, stores NULL.',
            );
        } finally {
            $conn->close();
            (new Filesystem())->remove($root);
        }
    }

    public function testPageDtoExposesTheChecksum(): void
    {
        $page = new PageDto('doc', 'id', 'https://example.test/1.jp2', sourceChecksum: 'abc123');
        self::assertSame('abc123', $page->sourceChecksum);
        self::assertNull((new PageDto('doc', 'id', 'https://example.test/1.jp2'))->sourceChecksum);
    }
}
