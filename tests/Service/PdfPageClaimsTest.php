<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Path\DataPaths;
use Survos\FolioBundle\Service\FolioIngestService;
use Symfony\Component\Filesystem\Filesystem;

final class PdfPageClaimsTest extends TestCase
{
    public function testPdfTranscriptDoesNotOverwriteIndividualPages(): void
    {
        $root = sys_get_temp_dir() . '/pdf-page-claims-' . bin2hex(random_bytes(8));
        $paths = new DataPaths($root);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $conn->executeStatement('CREATE TABLE page (row_id TEXT, media_id TEXT, page_index INT, text TEXT, htr TEXT, dense_summary TEXT, layout TEXT)');
            $conn->executeStatement('CREATE TABLE item (id TEXT, dto_data TEXT)');
            $conn->insert('item', ['id' => 'folder', 'dto_data' => '{}']);
            foreach ([['pdf', 0], ['pdf', 1], ['image', 0]] as [$id, $index]) {
                $conn->insert('page', ['row_id' => 'folder', 'media_id' => $id, 'page_index' => $index]);
            }
            $claims = [
                ['subjectId' => 'pdf', 'predicate' => 'ai:ocrPage', 'value' => ['index' => 0, 'markdown' => 'First page']],
                ['subjectId' => 'pdf', 'predicate' => 'ai:ocrPage', 'value' => json_encode(['index' => 1, 'markdown' => 'Second page'])],
                // Combined text arrives last: it must still not overwrite either page.
                ['subjectId' => 'pdf', 'predicate' => 'ai:ocrText', 'value' => 'First page Second page'],
                ['subjectId' => 'image', 'predicate' => 'ai:ocrText', 'value' => 'Existing image OCR'],
            ];
            file_put_contents($paths->claimsFile('test/pdf'), implode("\n", array_map(json_encode(...), $claims)) . "\n");
            $em = $this->createStub(EntityManagerInterface::class);
            $em->method('getConnection')->willReturn($conn);
            $reflection = new \ReflectionClass(FolioIngestService::class);
            $service = $reflection->newInstanceWithoutConstructor();
            $reflection->getProperty('dataPaths')->setValue($service, $paths);
            $reflection->getMethod('ingestPageClaims')->invoke($service, $em, 'test/pdf');
            self::assertSame(['First page', 'Second page', 'Existing image OCR'], $conn->fetchFirstColumn('SELECT text FROM page ORDER BY rowid'));
            self::assertSame('First page Second page', json_decode($conn->fetchOne('SELECT dto_data FROM item'), true)['ocrText']);
        } finally {
            $conn->close();
            (new Filesystem())->remove($root);
        }
    }
}
