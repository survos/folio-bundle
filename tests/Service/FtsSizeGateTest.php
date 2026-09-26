<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Service\FolioFtsIndexer;

/**
 * The FTS size gate: which folios are built without a SQLite full-text index, and what a gated
 * build leaves behind. Permission comes from the dataset's declared search policy (Elasticsearch
 * backend + allowFtsSkip, carried into folio.fts_content); the size limit decides whether to use
 * it. A page-level newspaper folio's index is the longest phase of its build and the largest table
 * in the file; a small folio is indexed anyway, so the file stays self-contained.
 */
final class FtsSizeGateTest extends TestCase
{
    public function testAnOptedOutFolioOverTheLimitGetsNoIndex(): void
    {
        $file = $this->folio(rows: 3, ftsContent: Folio::FTS_CONTENT_OFF);
        $result = (new FolioFtsIndexer(maxRows: 2))->rebuild($file);

        self::assertSame('policy', $result['skipped']);
        self::assertSame(3, $result['items']);
        self::assertSame(0, $result['rows']);
        self::assertFalse($this->hasTable($file, 'item_fts'));
        // The cheap derived tables readers depend on are still built.
        self::assertTrue($this->hasTable($file, 'item_facet_count'));
    }

    public function testAFolioThatHasNotOptedOutIsIndexedHoweverLarge(): void
    {
        // No search anywhere is worse than a slow build: only a dataset with an Elasticsearch
        // backend may be published unindexed, and this one has not said so.
        $file = $this->folio(rows: 3);
        $result = (new FolioFtsIndexer(maxRows: 2))->rebuild($file);

        self::assertNull($result['skipped']);
        self::assertTrue($this->hasTable($file, 'item_fts'));
    }

    public function testAnOptedOutFolioUnderTheLimitIsIndexedAnyway(): void
    {
        $file = $this->folio(rows: 3, ftsContent: Folio::FTS_CONTENT_OFF);

        self::assertNull((new FolioFtsIndexer(maxRows: 10))->rebuild($file)['skipped']);
        self::assertTrue($this->hasTable($file, 'item_fts'));
    }

    public function testAnIndexFromAnEarlierBuildIsDroppedRatherThanLeftBehind(): void
    {
        // A rebuild reassigns item rowids, so yesterday's index points at the wrong rows.
        $file = $this->folio(rows: 3, ftsContent: Folio::FTS_CONTENT_OFF);
        (new FolioFtsIndexer(maxRows: 10))->rebuild($file);
        self::assertTrue($this->hasTable($file, 'item_fts'));

        (new FolioFtsIndexer(maxRows: 2))->rebuild($file);
        self::assertFalse($this->hasTable($file, 'item_fts'));
    }

    public function testForceIndexesAGatedFolioAnyway(): void
    {
        $file = $this->folio(rows: 3, ftsContent: Folio::FTS_CONTENT_OFF);
        $forced = (new FolioFtsIndexer(maxRows: 2))->rebuild($file, force: true);

        self::assertNull($forced['skipped']);
        self::assertSame(3, $forced['rows']);
    }

    private function folio(int $rows, string $ftsContent = Folio::FTS_CONTENT_STORED): string
    {
        $file = sys_get_temp_dir() . '/fts-gate-' . bin2hex(random_bytes(6)) . '.folio';
        $pdo = new \PDO('sqlite:' . $file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE folio (code TEXT PRIMARY KEY, row_count INTEGER NOT NULL DEFAULT 0, fts_content TEXT NOT NULL)');
        $pdo->exec(sprintf("INSERT INTO folio (code, row_count, fts_content) VALUES ('news/x', %d, '%s')", $rows, $ftsContent));
        $pdo->exec('CREATE TABLE item (id TEXT PRIMARY KEY, core_id TEXT, local_id TEXT, label TEXT, dto_type TEXT, dto_data TEXT, extras TEXT)');
        for ($i = 0; $i < $rows; ++$i) {
            $pdo->exec(sprintf(
                "INSERT INTO item (id, core_id, local_id, label, dto_type, dto_data, extras) VALUES ('news/x:obj:%1\$d', 'news/x:obj', '%1\$d', 'Page %1\$d', 'document', '{\"title\":\"Page %1\$d\",\"ocrText\":\"one face at a time\"}', '{}')",
                $i,
            ));
        }

        return $file;
    }

    private function hasTable(string $file, string $table): bool
    {
        $pdo = new \PDO('sqlite:' . $file);

        return (bool) $pdo->query(sprintf("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '%s'", $table))->fetchColumn();
    }
}
