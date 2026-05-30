<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Format\JsonEachRow;
use SimPod\ClickHouseClient\Sql\Escaper;

/**
 * Manages MergeTree partitions (list / drop / detach / attach / move / replace /
 * freeze / clear column) via `ALTER TABLE … PARTITION`.
 *
 * Partition operations cannot use bound parameters, so the partition is always
 * addressed by its **id** (as returned by {@see getPartitions()}) and emitted as
 * an escaped string literal: `PARTITION ID '…'`. Table and column names are
 * validated as identifiers. `getPartitions()` reads system.parts with bound
 * parameters.
 *
 * @api
 */
final readonly class ClickHousePartitionManager
{
    public function __construct(
        private ClickHouseClient $client,
    ) {}

    /**
     * Active partitions of a table (optionally db-qualified).
     *
     * @return list<array{partition: string, partition_id: string, rows: int, bytes: int}>
     */
    public function getPartitions(string $table): array
    {
        $dot = strpos($table, '.');
        if ($dot === false) {
            Identifier::assert($table);
            $where = 'active AND database = currentDatabase() AND table = {tbl:String}';
            $params = ['tbl' => $table];
        } else {
            Identifier::assert($table);
            $where = 'active AND database = {db:String} AND table = {tbl:String}';
            $params = ['db' => substr($table, 0, $dot), 'tbl' => substr($table, $dot + 1)];
        }

        $sql = 'SELECT partition, partition_id, sum(rows) AS rows, sum(bytes_on_disk) AS bytes '
            . 'FROM system.parts WHERE ' . $where . ' GROUP BY partition, partition_id ORDER BY partition';

        /** @var \SimPod\ClickHouseClient\Output\JsonEachRow<array{partition: string, partition_id: string, rows: int|string, bytes: int|string}> $output */
        $output = $this->client->selectWithParams($sql, $params, new JsonEachRow());

        return array_map(
            static fn(array $row): array => [
                'partition' => $row['partition'],
                'partition_id' => $row['partition_id'],
                'rows' => (int) $row['rows'],
                'bytes' => (int) $row['bytes'],
            ],
            $output->data,
        );
    }

    public function dropPartition(string $table, string $partitionId): void
    {
        $this->alter($table, 'DROP ' . $this->partition($partitionId));
    }

    public function detachPartition(string $table, string $partitionId): void
    {
        $this->alter($table, 'DETACH ' . $this->partition($partitionId));
    }

    public function attachPartition(string $table, string $partitionId): void
    {
        $this->alter($table, 'ATTACH ' . $this->partition($partitionId));
    }

    public function freezePartition(string $table, string $partitionId): void
    {
        $this->alter($table, 'FREEZE ' . $this->partition($partitionId));
    }

    public function clearColumnInPartition(string $table, string $partitionId, string $column): void
    {
        Identifier::assertPlain($column);
        $this->alter($table, sprintf('CLEAR COLUMN %s IN %s', $column, $this->partition($partitionId)));
    }

    /**
     * Moves a partition from $sourceTable into $targetTable.
     */
    public function movePartition(string $sourceTable, string $targetTable, string $partitionId): void
    {
        Identifier::assert($targetTable);
        $this->alter($sourceTable, sprintf('MOVE %s TO TABLE %s', $this->partition($partitionId), $targetTable));
    }

    /**
     * Replaces $targetTable's partition with the one from $sourceTable.
     */
    public function replacePartition(string $sourceTable, string $targetTable, string $partitionId): void
    {
        Identifier::assert($sourceTable);
        $this->alter($targetTable, sprintf('REPLACE %s FROM %s', $this->partition($partitionId), $sourceTable));
    }

    private function alter(string $table, string $action): void
    {
        Identifier::assert($table);
        $this->client->executeQuery(sprintf('ALTER TABLE %s %s', $table, $action));
    }

    private function partition(string $partitionId): string
    {
        return "PARTITION ID '" . Escaper::escape($partitionId) . "'";
    }
}
