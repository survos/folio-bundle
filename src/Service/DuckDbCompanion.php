<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/** One selected revision. Optional reads never change the ORM connection. */
final class DuckDbCompanion
{
    private ?\PDO $connection = null;

    public function __construct(
        private readonly string $path,
        private readonly ?string $buildId,
        private readonly ?int $schemaVersion,
    ) {
    }

    public function open(): ?\PDO
    {
        if (null !== $this->connection) {
            return $this->connection;
        }
        if (!is_file($this->path) || null === $this->buildId) {
            return null;
        }
        $db = (new DuckDbFolioReader())->open($this->path);
        $identities = $db->query('SELECT build_id, schema_version FROM _folio_build')->fetchAll(\PDO::FETCH_ASSOC);
        if (1 !== count($identities) || $identities[0]['build_id'] !== $this->buildId
            || (int) $identities[0]['schema_version'] !== $this->schemaVersion
            || DuckDbFolioExporter::SCHEMA_VERSION !== $this->schemaVersion) {
            throw new \RuntimeException('DuckDB companion belongs to a different or unsupported folio build: '.$this->path);
        }

        return $this->connection = $db;
    }
}
