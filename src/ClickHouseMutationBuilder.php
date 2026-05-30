<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Format\JsonEachRow;
use SimPod\ClickHouseClient\Sql\Escaper;

/**
 * Submits and tracks ClickHouse mutations (`ALTER TABLE … UPDATE/DELETE`), the
 * only way to modify or delete existing rows. Mutations run asynchronously;
 * use {@see getMutations()} / {@see waitForMutations()} to track completion.
 *
 * The `$set` and `$condition` fragments are emitted verbatim (trusted,
 * developer-authored) — pass user values as bound `{name:Type}` parameters via
 * `$params`, never by string concatenation. Table names are validated.
 *
 * @api
 */
final readonly class ClickHouseMutationBuilder
{
    public function __construct(
        private ClickHouseClient $client,
    ) {}

    /**
     * `ALTER TABLE <table> UPDATE <set> WHERE <condition>`.
     *
     * @param string $set e.g. "status = {st:String}, score = {sc:UInt32}"
     * @param string $condition e.g. "id = {id:UInt64}"
     * @param array<string, mixed> $params Bound values for placeholders in $set/$condition.
     */
    public function update(string $table, string $set, string $condition, array $params = []): void
    {
        Identifier::assert($table);
        $this->client->executeQueryWithParams(
            sprintf('ALTER TABLE %s UPDATE %s WHERE %s', $table, $set, $condition),
            $params,
        );
    }

    /**
     * `ALTER TABLE <table> DELETE WHERE <condition>`.
     *
     * @param array<string, mixed> $params Bound values for placeholders in $condition.
     */
    public function delete(string $table, string $condition, array $params = []): void
    {
        Identifier::assert($table);
        $this->client->executeQueryWithParams(
            sprintf('ALTER TABLE %s DELETE WHERE %s', $table, $condition),
            $params,
        );
    }

    /**
     * Mutations recorded for a table (most recent first).
     *
     * @return list<array{mutation_id: string, command: string, is_done: bool, parts_to_do: int, latest_fail_reason: string}>
     */
    public function getMutations(string $table): array
    {
        Identifier::assert($table);
        $dot = strpos($table, '.');
        if ($dot === false) {
            $where = 'database = currentDatabase() AND table = {tbl:String}';
            $params = ['tbl' => $table];
        } else {
            $where = 'database = {db:String} AND table = {tbl:String}';
            $params = ['db' => substr($table, 0, $dot), 'tbl' => substr($table, $dot + 1)];
        }

        $sql = 'SELECT mutation_id, command, is_done, parts_to_do, latest_fail_reason '
            . 'FROM system.mutations WHERE ' . $where . ' ORDER BY create_time DESC';

        /** @var \SimPod\ClickHouseClient\Output\JsonEachRow<array{mutation_id: string, command: string, is_done: int|string, parts_to_do: int|string, latest_fail_reason: string}> $output */
        $output = $this->client->selectWithParams($sql, $params, new JsonEachRow());

        return array_map(
            static fn(array $row): array => [
                'mutation_id' => $row['mutation_id'],
                'command' => $row['command'],
                'is_done' => (bool) (int) $row['is_done'],
                'parts_to_do' => (int) $row['parts_to_do'],
                'latest_fail_reason' => $row['latest_fail_reason'],
            ],
            $output->data,
        );
    }

    /**
     * Polls until all mutations of $table are done or $timeout (seconds) elapses.
     * Returns true if all finished in time.
     */
    public function waitForMutations(string $table, float $timeout = 30.0): bool
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            $pending = array_filter($this->getMutations($table), static fn(array $m): bool => !$m['is_done']);
            if ($pending === []) {
                return true;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(200_000);
        }
    }

    public function killMutation(string $table, string $mutationId): void
    {
        Identifier::assert($table);
        $dot = strpos($table, '.');
        if ($dot === false) {
            $scope = 'database = currentDatabase()';
            $name = $table;
        } else {
            $scope = "database = '" . Escaper::escape(substr($table, 0, $dot)) . "'";
            $name = substr($table, $dot + 1);
        }

        $this->client->executeQuery(sprintf(
            "KILL MUTATION WHERE %s AND table = '%s' AND mutation_id = '%s'",
            $scope,
            Escaper::escape($name),
            Escaper::escape($mutationId),
        ));
    }
}
