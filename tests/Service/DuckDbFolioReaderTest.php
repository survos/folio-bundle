<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Dto\DuckDbS3Configuration;
use Survos\FolioBundle\Service\DuckDbFolioReader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class DuckDbFolioReaderTest extends TestCase
{
    private string $root;
    private ?Process $server = null;

    protected function setUp(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Requires ext-pdo_duckdb with DuckDB 2.0.');
        }
        $this->root = (getenv('FOLIO_TEST_ROOT') ?: sys_get_temp_dir()).'/duckdb-reader-'.bin2hex(random_bytes(5));
        mkdir($this->root, 0700, true);
        $db = new \PDO('duckdb:'.$this->root.'/fixture.duckdb');
        $db->exec('CREATE TABLE objects AS SELECT range::BIGINT AS n, repeat(md5(range::VARCHAR), 20) AS payload FROM range(10000)');
        $db->exec('CHECKPOINT');
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
    }

    public function testLocalSnapshotIsReadOnly(): void
    {
        $db = (new DuckDbFolioReader())->open($this->root.'/fixture.duckdb');
        self::assertSame(10000, $db->query('SELECT count(*) FROM objects')->fetchColumn());
        $this->expectException(\PDOException::class);
        $db->exec('DELETE FROM objects');
    }

    public function testS3UsesAuthenticatedRangeRequestsWithoutDownloadingTheDatabase(): void
    {
        // A local S3-shaped HTTP fixture; no real credentials or external storage.
        file_put_contents($this->root.'/router.php', <<<'PHP'
<?php
$file = __DIR__.'/fixture.duckdb';
$size = filesize($file);
$start = 0;
$end = $size - 1;
$range = $_SERVER['HTTP_RANGE'] ?? null;
if (null !== $range) {
    if (!preg_match('/^bytes=(\d+)-(\d*)$/', $range, $parts)) {
        http_response_code(416);
        exit;
    }
    $start = (int) $parts[1];
    $end = '' === $parts[2] ? $end : min($end, (int) $parts[2]);
    http_response_code(206);
    header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
}
$length = $end - $start + 1;
header('Content-Type: application/octet-stream');
header('Accept-Ranges: bytes');
header('ETag: "fixture"');
header('Content-Length: '.$length);
$head = 'HEAD' === $_SERVER['REQUEST_METHOD'];
file_put_contents(__DIR__.'/requests.jsonl', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'], 'range' => $range, 'bytes' => $head ? 0 : $length,
    'signed' => str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'AWS4-HMAC-SHA256 '),
])."\n", FILE_APPEND);
if (!$head) {
    $stream = fopen($file, 'rb');
    fseek($stream, $start);
    while ($length > 0 && !feof($stream)) {
        $chunk = fread($stream, min($length, 65536));
        echo $chunk;
        $length -= strlen($chunk);
    }
    fclose($stream);
}
PHP);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->server = new Process([PHP_BINARY, '-S', $address, $this->root.'/router.php'], $this->root);
        $this->server->start();
        self::assertTrue($this->server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'Development Server')), $this->server->getErrorOutput());
        $config = new DuckDbS3Configuration('http://'.$address, 'fixture-key', 'fixture-secret', 'us-east-1');
        $before = hash_file('sha256', $this->root.'/fixture.duckdb');
        try {
            $db = (new DuckDbFolioReader())->open('s3://fixture-bucket/fixture.duckdb', $config);
        } catch (\PDOException $e) {
            throw new \RuntimeException($this->server->getErrorOutput(), previous: $e);
        }
        self::assertSame(10000, $db->query('SELECT count(*) FROM objects')->fetchColumn());
        $statement = $db->prepare('SELECT n FROM objects WHERE n = ?');
        $statement->execute([123]);
        self::assertSame(123, $statement->fetchColumn());
        $statement = null;
        try {
            $db->exec('DELETE FROM objects');
            self::fail('Remote snapshots must be read-only.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('read-only', $e->getMessage());
        }
        $db = null;
        $requests = array_map(static fn (string $row): array => json_decode($row, true, flags: JSON_THROW_ON_ERROR), file($this->root.'/requests.jsonl', FILE_IGNORE_NEW_LINES));
        self::assertNotEmpty($requests);
        self::assertTrue(array_any($requests, static fn (array $request): bool => null !== $request['range']));
        self::assertTrue(array_all($requests, static fn (array $request): bool => $request['signed']));
        self::assertLessThan(filesize($this->root.'/fixture.duckdb'), array_sum(array_column($requests, 'bytes')));
        self::assertSame($before, hash_file('sha256', $this->root.'/fixture.duckdb'));
    }

    public function testConfigurationDoesNotExposeCredentialsInDebugOutput(): void
    {
        $configuration = new DuckDbS3Configuration('https://s3.example.test', 'fixture-key', 'fixture-secret', 'test-region');
        ob_start();
        var_dump($configuration);
        $debug = ob_get_clean();
        self::assertStringNotContainsString('fixture-key', $debug);
        self::assertStringNotContainsString('fixture-secret', $debug);
    }
}
