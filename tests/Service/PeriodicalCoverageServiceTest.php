<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Service\PeriodicalCoverageService;

final class PeriodicalCoverageServiceTest extends TestCase
{
    /** Each row: [id, local id, core, label, dto_data, extras]. Every field the coverage needs is in dto_data. */
    private const array ROWS = [
        ['news/test:doc:i1', 'i1', 'news/test:doc', 'January 1, 1953', ['issueKind' => 'issue', 'issueProcessing' => ['stage' => 'segmented', 'pages' => 4]], ['textsheetGrouping' => ['pages' => []]]],
        ['news/test:doc:i2', 'i2', 'news/test:doc', 'January 8, 1953', ['issueKind' => 'issue'], null],
        ['news/test:doc:index', 'index', 'news/test:doc', 'Index 1953', ['issueKind' => 'annual index'], null],
        ['news/test:article:a1', 'a1', 'news/test:article', 'Story', ['issueId' => 'i1', 'recordKind' => 'article', 'articleTier' => 'full'], null],
        ['news/test:article:a2', 'a2', 'news/test:article', 'Ad', ['issueId' => 'i1', 'recordKind' => 'advertisement', 'articleTier' => 'block'], null],
        ['news/test:article:a3', 'a3', 'news/test:article', 'Block', ['issueId' => 'i1', 'recordKind' => 'article', 'articleTier' => 'block'], null],
        ['news/test:article:a4', 'a4', 'news/test:article', 'Block', ['issueId' => 'i2', 'articleTier' => 'block'], ['issueId' => 'i1', 'recordKind' => 'advertisement']],
    ];

    public function testASqliteFolioAndItsDfolioGiveTheSameCoverage(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Native PDO DuckDB required.');
        }
        $sqlite = new \PDO('sqlite::memory:');
        $sqlite->exec('CREATE TABLE item (id TEXT PRIMARY KEY, local_id TEXT, core_id TEXT, label TEXT, dto_data TEXT, extras TEXT)');
        $duck = new \PDO('duckdb::memory:');
        $duck->exec('CREATE TABLE item (id VARCHAR PRIMARY KEY, local_id VARCHAR, core_id VARCHAR, label VARCHAR, dto_data VARIANT, extras VARIANT)');
        foreach (self::ROWS as [$id, $local, $core, $label, $data, $extras]) {
            $values = [$id, $local, $core, $label, json_encode($data), null === $extras ? null : json_encode($extras)];
            $sqlite->prepare('INSERT INTO item VALUES (?, ?, ?, ?, ?, ?)')->execute($values);
            $duck->prepare('INSERT INTO item VALUES (?, ?, ?, ?, CAST(CAST(? AS JSON) AS VARIANT), CAST(CAST(? AS JSON) AS VARIANT))')->execute($values);
        }

        $coverage = PeriodicalCoverageService::fromDfolio($duck, 'news/test');
        self::assertSame(2, $coverage['issues'], 'The annual index is not an issue');
        self::assertSame(1, $coverage['articles']);
        self::assertSame(1, $coverage['ads']);
        self::assertSame(2, $coverage['headlines']);
        self::assertSame(3, $coverage['blocks'], 'A block counts whatever its kind');
        self::assertSame('segmented', $coverage['byIssue']['i1']['stage']);
        self::assertSame('Extraction run finished · review needed', $coverage['byIssue']['i1']['status']);
        // a4 belongs to i2 by its dto_data; its extras say otherwise and are not read.
        self::assertSame(1, $coverage['byIssue']['i2']['headlines']);
        self::assertSame(0, $coverage['byIssue']['i2']['ads']);

        self::assertEquals($coverage, PeriodicalCoverageService::fromFolio($sqlite, 'news/test'));
    }

    public function testAnArticleWithoutItsIssueInDtoDataIsRefused(): void
    {
        $sqlite = new \PDO('sqlite::memory:');
        $sqlite->exec('CREATE TABLE item (id TEXT PRIMARY KEY, local_id TEXT, core_id TEXT, label TEXT, dto_data TEXT, extras TEXT)');
        $sqlite->exec("INSERT INTO item VALUES ('news/test:doc:i1', 'i1', 'news/test:doc', 'Issue', '{}', NULL), ('news/test:article:a1', 'a1', 'news/test:article', 'Story', '{}', '{\"issueId\":\"i1\"}')");
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('issueId');
        PeriodicalCoverageService::fromFolio($sqlite, 'news/test');
    }
}
