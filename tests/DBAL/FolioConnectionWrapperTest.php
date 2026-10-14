<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\DBAL;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\DBAL\FolioConnectionWrapper;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The folio connection is deliberately non-standard: one Doctrine connection is pointed at
 * thousands of SQLite files in turn, and it manages journal_mode by hand so that a folio at rest
 * is always in DELETE mode (a WAL file needs a writable directory for its -shm, which read-only
 * consumers like inkstory.org do not have). Both halves of that have already broken production
 * once, hence this test: it pins the journal_mode lifecycle, the read-only open and the
 * lazily-resolved currentPath against the file header, not against the implementation.
 */
final class FolioConnectionWrapperTest extends TestCase
{
    private const HEADER_DELETE = "\x01";
    private const HEADER_WAL = "\x02";

    private string $root;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->root = sys_get_temp_dir().'/folio-connection-'.bin2hex(random_bytes(5));
        $this->fs->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        // A directory a test made read-only has to be writable again before anything in it can go.
        if (is_dir($this->root)) {
            @chmod($this->root, 0o755);
            foreach (new \DirectoryIterator($this->root) as $entry) {
                if ($entry->isDir() && !$entry->isDot()) {
                    @chmod($entry->getPathname(), 0o755);
                }
            }
            $this->fs->remove($this->root);
        }
    }

    public function testCurrentPathIsResolvedFromTheConnectionParameters(): void
    {
        // The wrapper declares no constructor -- overriding DBAL's, which is @internal, is
        // deprecated -- so currentPath resolves on first read instead of being assigned at build time.
        $conn = $this->connect($path = $this->root.'/first.sqlite');

        self::assertSame($path, $conn->currentPath);
    }

    public function testSelectDatabaseSwitchesTheFileAndTheQueriesFollowIt(): void
    {
        $conn = $this->connect($this->root.'/first.sqlite');
        $this->seed($conn, 'first');

        $conn->selectDatabase($second = $this->root.'/second.sqlite');
        $this->seed($conn, 'second');

        self::assertSame($second, $conn->currentPath);
        self::assertSame('second', $conn->fetchOne('SELECT label FROM folio'));

        $conn->selectDatabase($first = $this->root.'/first.sqlite');
        self::assertSame($first, $conn->currentPath);
        self::assertSame('first', $conn->fetchOne('SELECT label FROM folio'));
    }

    public function testReselectingTheSameFileKeepsTheConnectionAsItIs(): void
    {
        $conn = $this->connect($path = $this->root.'/first.sqlite');
        $this->seed($conn, 'first');

        $conn->selectDatabase($path);

        self::assertTrue($conn->isConnected(), 'an unchanged selectDatabase() must not reconnect');
        self::assertSame($path, $conn->currentPath);
    }

    public function testReadingAFolioLeavesItsHeaderInDeleteMode(): void
    {
        // Regression guard: asserting WAL on connect left 3,942 folios in WAL mode on 2026-09-23,
        // and web workers kept flipping ~90 a day after that. Reads must not touch the header.
        $conn = $this->connect($path = $this->root.'/read.sqlite');
        $this->seed($conn, 'read');
        $conn->close();

        $conn->selectDatabase($path);
        self::assertSame('read', $conn->fetchOne('SELECT label FROM folio'));
        $conn->close();

        self::assertSame(self::HEADER_DELETE, $this->journalByte($path));
    }

    public function testAWriteUsesWalAndCloseRestoresDeleteMode(): void
    {
        $conn = $this->connect($path = $this->root.'/write.sqlite');
        $this->seed($conn, 'write');

        $conn->beginTransaction();
        $conn->executeStatement("INSERT INTO folio (label) VALUES ('in wal')");
        self::assertSame('wal', $conn->fetchOne('PRAGMA journal_mode'), 'a write transaction runs in WAL');
        $conn->commit();

        $conn->close();

        self::assertSame(self::HEADER_DELETE, $this->journalByte($path), 'a folio at rest is in DELETE mode');
        $conn->selectDatabase($path);
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM folio'), 'the WAL write survived the restore');
    }

    public function testAReadOnlySelectionRefusesWrites(): void
    {
        $conn = $this->connect($path = $this->root.'/ro.sqlite');
        $this->seed($conn, 'ro');
        $conn->close();

        $conn->selectDatabase($path, readOnly: true);

        self::assertSame($path, $conn->currentPath, 'currentPath stays the plain path, not the file: URI');
        self::assertSame('ro', $conn->fetchOne('SELECT label FROM folio'));

        $this->expectExceptionMessageMatches('/readonly|read-only/i');
        $conn->executeStatement("INSERT INTO folio (label) VALUES ('nope')");
    }

    public function testAReadOnlyOpenOfAWalFileStaysPlainWhileTheDirectoryIsWritable(): void
    {
        $path = $this->root.'/wal.sqlite';
        $this->walFile($path);

        self::assertSame(self::HEADER_WAL, $this->journalByte($path));
        self::assertSame('mode=ro', FolioConnectionWrapper::readOnlyQuery($path));
    }

    public function testAWalFileInAReadOnlyDirectoryIsOpenedImmutable(): void
    {
        // The inkstory.org outage of 2026-09-24: SQLite cannot create the -shm a WAL file needs in
        // a directory the reader cannot write, and every open dies with "unable to open database
        // file". immutable=1 skips WAL and locking, and is used in exactly this case.
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root ignores directory permissions');
        }

        $dir = $this->root.'/locked';
        $this->fs->mkdir($dir);
        $this->walFile($path = $dir.'/wal.sqlite');
        chmod($dir, 0o555);

        if (is_writable($dir)) {
            self::markTestSkipped('this filesystem ignores the directory mode');
        }

        self::assertSame('mode=ro&immutable=1', FolioConnectionWrapper::readOnlyQuery($path));

        $conn = $this->connect($this->root.'/unused.sqlite');
        $conn->selectDatabase($path, readOnly: true);
        self::assertSame('wal', $conn->fetchOne('SELECT label FROM folio'), 'the reader gets its rows anyway');
    }

    public function testADeleteModeFileIsOpenedPlainReadOnly(): void
    {
        $conn = $this->connect($path = $this->root.'/plain.sqlite');
        $this->seed($conn, 'plain');
        $conn->close();

        self::assertSame(self::HEADER_DELETE, $this->journalByte($path));
        self::assertSame('mode=ro', FolioConnectionWrapper::readOnlyQuery($path));
    }

    private function connect(string $path): FolioConnectionWrapper
    {
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $path,
            'wrapperClass' => FolioConnectionWrapper::class,
        ]);
        self::assertInstanceOf(FolioConnectionWrapper::class, $conn);

        return $conn;
    }

    private function seed(FolioConnectionWrapper $conn, string $label): void
    {
        $conn->executeStatement('CREATE TABLE IF NOT EXISTS folio (label TEXT)');
        $conn->executeStatement('INSERT INTO folio (label) VALUES (?)', [$label]);
    }

    /** A file left in WAL mode, the way a scan used to leave one behind. */
    private function walFile(string $path): void
    {
        $db = new \PDO('sqlite:'.$path);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('CREATE TABLE folio (label TEXT)');
        $db->exec("INSERT INTO folio (label) VALUES ('wal')");
        unset($db);
    }

    /** Byte 18 of the SQLite header: 1 for a rollback journal, 2 for WAL. */
    private function journalByte(string $path): string
    {
        return (string) file_get_contents($path, false, null, 18, 1);
    }
}
