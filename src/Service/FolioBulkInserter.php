<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Buffered multi-row INSERT writer for the folio bulk-ingest hot path.
 *
 * Why this exists (intent — read before "simplifying" back to the ORM):
 * At 1M+ rows the dominant cost of restoring normalized JSONL is NOT the SQL — it is Doctrine's
 * per-row `new Entity()` + `persist()` + UnitOfWork change-tracking + flush hydration, which is what
 * drove the multi-GB RSS and multi-hour runtimes we hit on large NARA record groups. The folio is a
 * throwaway, single-writer SQLite file rebuilt from JSONL on demand, so the ORM buys us nothing here:
 * we never read these rows back during ingest, and the JSONL is already the source of truth.
 *
 * This writer collapses each batch into one prepared `INSERT ... VALUES (..),(..),..` statement —
 * the same C-level inserts SQLite's own `.import` performs, but driven straight from the single PHP
 * pass that already parses + reshapes each row (dtoType resolution, dtoData/extras split). That keeps
 * everything in one process with no CSV serialization/quoting round-trip and no external `sqlite3`
 * dependency, while writing every column the entity maps. Memory stays flat: nothing is retained
 * past a flush.
 *
 * Statement size is bounded by SQLite's bound-parameter limit (SQLITE_MAX_VARIABLE_NUMBER, 32766 on
 * SQLite >= 3.32). We auto-flush before the buffer could exceed {@see self::MAX_PARAMS} placeholders.
 *
 * Duplicate ids are tolerated, not fatal. Normalized JSONL can carry the same local id twice (a real
 * data bug we hit on nara/rg_114). The ORM blew up on this with an EntityIdentityCollisionException;
 * here we append `ON CONFLICT(id) DO NOTHING`, so the first occurrence wins and later duplicates are
 * skipped. They are counted ({@see self::skipped()}) and reported by the caller — tolerated, never
 * silently swallowed.
 */
final class FolioBulkInserter
{
    /** Stay comfortably under SQLite's 32766 bound-parameter ceiling. */
    private const MAX_PARAMS = 16000;

    private readonly string $sqlPrefix;
    private readonly string $sqlSuffix;
    private readonly string $tuple;
    private readonly int $maxRows;

    /** @var list<list<mixed>> positional value tuples, each aligned to $columns */
    private array $buffer = [];

    private int $inserted = 0;
    private int $skipped = 0;

    /**
     * @param list<string> $columns        physical column names, in bind order
     * @param string       $conflictColumn unique/PK column whose duplicates are skipped (DO NOTHING)
     */
    public function __construct(
        private readonly Connection $conn,
        string $table,
        private readonly array $columns,
        string $conflictColumn = 'id',
    ) {
        $colCount = \count($this->columns);
        $this->sqlPrefix = sprintf('INSERT INTO %s (%s) VALUES ', $table, implode(', ', $this->columns));
        $this->sqlSuffix = sprintf(' ON CONFLICT(%s) DO NOTHING', $conflictColumn);
        $this->tuple = '(' . implode(', ', array_fill(0, $colCount, '?')) . ')';
        // e.g. 8 columns -> 2000 rows per statement (16000 params); a logical batch of 500 fits easily.
        $this->maxRows = max(1, intdiv(self::MAX_PARAMS, $colCount));
    }

    /** @param list<mixed> $values one row, aligned to $columns */
    public function add(array $values): void
    {
        $this->buffer[] = $values;
        if (\count($this->buffer) >= $this->maxRows) {
            $this->flush();
        }
    }

    /** Emit the buffered rows as a single multi-row INSERT. Idempotent when the buffer is empty. */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $rows = \count($this->buffer);
        $sql = $this->sqlPrefix . implode(', ', array_fill(0, $rows, $this->tuple)) . $this->sqlSuffix;
        $params = [];
        foreach ($this->buffer as $row) {
            foreach ($row as $value) {
                $params[] = $value;
            }
        }
        // affected = rows actually inserted; the remainder were duplicate-id conflicts we skipped.
        $affected = (int) $this->conn->executeStatement($sql, $params);
        $this->inserted += $affected;
        $this->skipped += $rows - $affected;
        $this->buffer = [];
    }

    /** Rows actually written (after dropping duplicate-id conflicts). */
    public function inserted(): int
    {
        return $this->inserted;
    }

    /** Duplicate-id rows skipped by ON CONFLICT DO NOTHING. */
    public function skipped(): int
    {
        return $this->skipped;
    }
}
