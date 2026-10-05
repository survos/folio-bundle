<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Service\FolioIngestService;

/**
 * Claims resolve to items inside SQLite, for only the subjects the claims name. The old way loaded
 * every item id into PHP first and ran a news title with millions of rows out of memory.
 */
final class ClaimSubjectsTest extends TestCase
{
    public function testOnlyNamedSubjectsResolveByLocalIdThenAssetId(): void
    {
        $file = sys_get_temp_dir().'/claim-subjects-'.bin2hex(random_bytes(8)).'.jsonl';
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $conn->executeStatement('CREATE TABLE item (id TEXT PRIMARY KEY, core_id TEXT, local_id TEXT)');
            $conn->executeStatement('CREATE UNIQUE INDEX uniq_item_core_local ON item (core_id, local_id)');
            $conn->executeStatement('CREATE TABLE page (row_id TEXT, media_id TEXT)');
            foreach ([['i1', 'obj', 'a'], ['i2', 'obj', 'b'], ['i3', 'article', 'c'], ['i4', 'obj', 'untouched']] as [$id, $core, $local]) {
                $conn->insert('item', ['id' => $id, 'core_id' => $core, 'local_id' => $local]);
            }
            $conn->insert('page', ['row_id' => 'i2', 'media_id' => 'asset-9']);
            $claims = [
                ['subjectId' => 'a', 'predicate' => 'p'],
                ['subjectId' => 'c', 'predicate' => 'p'],        // another core
                ['subjectId' => 'asset-9', 'predicate' => 'p'],  // image AI claim, via page.media_id
                ['subjectId' => 'a', 'predicate' => 'q'],        // repeated subject
                ['subjectId' => 'nowhere', 'predicate' => 'p'],  // no item
                ['predicate' => 'p'],                             // no subject
            ];
            file_put_contents($file, implode("\n", array_map(json_encode(...), $claims))."\n");

            $reflection = new \ReflectionClass(FolioIngestService::class);
            $resolved = $reflection->getMethod('claimSubjects')->invoke($reflection->newInstanceWithoutConstructor(), $conn, $file);

            self::assertSame(['a' => 'i1', 'asset-9' => 'i2', 'c' => 'i3'], (static function (array $r): array { ksort($r); return $r; })($resolved));
            self::assertSame(0, (int) $conn->fetchOne("SELECT count(*) FROM sqlite_temp_master WHERE name = 'claim_subject'"));

            file_put_contents($file, "\n");
            self::assertSame([], $reflection->getMethod('claimSubjects')->invoke($reflection->newInstanceWithoutConstructor(), $conn, $file));
        } finally {
            @unlink($file);
            $conn->close();
        }
    }
}
