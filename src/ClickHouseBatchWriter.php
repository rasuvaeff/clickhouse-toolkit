<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Schema\Table;

/**
 * Buffers rows and inserts them into a ClickHouse table in fixed-size batches.
 * Each row is projected onto the declared columns (extra keys dropped, missing
 * keys filled with null), so callers may pass loosely-shaped associative rows.
 *
 * @api
 */
final readonly class ClickHouseBatchWriter implements ClickHouseWriterInterface
{
    /** Resolved insert target: a Table for `db.table`, otherwise the plain name. */
    private Table|string $target;

    /**
     * @param string $table Target table, optionally db-qualified (`db.table`).
     * @param list<string> $columns Target columns, in insert order. Validated as
     *     identifiers — they are not escaped, so pass only trusted names.
     *
     * @throws \InvalidArgumentException on a malformed table/column identifier or batch size < 1.
     */
    public function __construct(
        private ClickHouseClient $client,
        private string $table,
        private array $columns,
        private int $batchSize = 1000,
    ) {
        if ($this->batchSize < 1) {
            throw new \InvalidArgumentException('Batch size must be at least 1.');
        }

        Identifier::assert($this->table);
        foreach ($this->columns as $column) {
            // Columns go into an INSERT column list — a db-qualified name is invalid there.
            Identifier::assertPlain($column);
        }

        // A db-qualified name must become a Table so each part is quoted
        // separately; simpod would otherwise backtick "db.table" as one identifier.
        $dot = strpos($this->table, '.');
        $this->target = $dot === false
            ? $this->table
            : new Table(
                name: substr($this->table, $dot + 1),
                database: substr($this->table, 0, $dot),
            );
    }

    #[\Override]
    public function write(iterable $rows): void
    {
        $buffer = [];
        $count = 0;

        foreach ($rows as $row) {
            $buffer[] = $this->project($row);
            if (++$count >= $this->batchSize) {
                $this->flush($buffer);
                $buffer = [];
                $count = 0;
            }
        }

        if ($buffer !== []) {
            $this->flush($buffer);
        }
    }

    /**
     * Keeps only the declared columns, in declared order, filling missing ones
     * with null and dropping any extra keys.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function project(array $row): array
    {
        $defaults = array_fill_keys($this->columns, null);

        return array_replace($defaults, array_intersect_key($row, $defaults));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function flush(array $rows): void
    {
        try {
            $this->client->insert(table: $this->target, values: $rows, columns: $this->columns);
        } catch (\Throwable $e) {
            throw new ClickHouseWriteException(
                sprintf('Failed to insert %d row(s) into "%s": %s', count($rows), $this->table, $e->getMessage()),
                (int) $e->getCode(),
                previous: $e,
            );
        }
    }
}
