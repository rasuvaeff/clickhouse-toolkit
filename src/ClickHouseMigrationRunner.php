<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Format\JsonEachRow;

/**
 * Applies *.sql migration files from a directory, in filename order, recording
 * applied files (with a content checksum) in a `_migrations` table.
 *
 * - Idempotent: already-applied files are skipped.
 * - Tamper-evident: if an already-applied file's contents changed, a
 *   {@see ClickHouseMigrationException} is thrown instead of silently diverging.
 * - One statement per file (the contents are sent as a single query).
 *
 * Concurrency & failure: ClickHouse has no transactions, and this runner uses
 * no distributed lock. The `_migrations` table is a ReplacingMergeTree keyed by
 * name and read with argMax, so duplicate records collapse deterministically —
 * but the execution path is still not serialized, so:
 *  - two runners started at once may both execute the same pending file;
 *  - if a file's DDL succeeds but the `_migrations` insert does not, the next
 *    run re-executes that file.
 * Run migrations from a single deploy step, and prefer idempotent DDL
 * (`CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`). For
 * stronger guarantees, wrap {@see run()} in an external lock.
 *
 * @api
 */
final readonly class ClickHouseMigrationRunner implements ClickHouseMigrationRunnerInterface
{
    private const string MIGRATIONS_TABLE = '_migrations';

    private LoggerInterface $logger;

    public function __construct(
        private ClickHouseClient $client,
        private string $migrationsPath,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @return list<string> Applied migration names
     *
     * @throws ClickHouseMigrationException
     */
    #[\Override]
    public function run(): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->getApplied();
        $result = [];

        foreach ($this->getMigrationFiles() as $file) {
            $name = basename(path: $file);

            $sql = file_get_contents(filename: $file);
            if ($sql === false) {
                throw new ClickHouseMigrationException(sprintf('Cannot read migration file "%s".', $name));
            }
            if (trim(string: $sql) === '') {
                $this->logger->warning('Skipping empty ClickHouse migration {name}', ['name' => $name]);
                continue;
            }

            $checksum = sha1(string: $sql);

            if (isset($applied[$name])) {
                if ($applied[$name] !== $checksum) {
                    throw new ClickHouseMigrationException(sprintf(
                        'ClickHouse migration "%s" was changed after it was applied (checksum %s != %s).',
                        $name,
                        $applied[$name],
                        $checksum,
                    ));
                }
                continue;
            }

            $this->client->executeQuery($sql);
            $this->client->insert(
                table: self::MIGRATIONS_TABLE,
                values: [['name' => $name, 'checksum' => $checksum]],
                columns: ['name', 'checksum'],
            );

            $this->logger->info('Applied ClickHouse migration {name}', ['name' => $name]);
            $result[] = $name;
        }

        return $result;
    }

    private function ensureMigrationsTable(): void
    {
        // ReplacingMergeTree ORDER BY name collapses duplicate rows for the same
        // migration (possible under concurrent runs or manual tampering). The
        // version column is DateTime64(6) (microsecond) so that, even for rows
        // written within the same second, the most recent record wins
        // deterministically.
        $this->client->executeQuery(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (name %s, checksum %s, applied_at %s DEFAULT now64(6)) ENGINE = ReplacingMergeTree(applied_at) ORDER BY name',
            self::MIGRATIONS_TABLE,
            ClickHouseDataType::String,
            ClickHouseDataType::String,
            ClickHouseDataType::dateTime64(6),
        ));
    }

    /**
     * @return array<string, string> Migration name => stored checksum
     *
     * @throws ClickHouseMigrationException when a migration has conflicting checksums recorded.
     */
    private function getApplied(): array
    {
        // argMax picks one deterministic checksum per migration regardless of
        // whether ReplacingMergeTree has merged duplicates yet; uniqExact surfaces
        // genuine divergence (the same name recorded with different checksums).
        // The argMax alias must not be `checksum`, or it would shadow the column
        // referenced by uniqExact(checksum) and trigger nested-aggregation errors.
        /** @var \SimPod\ClickHouseClient\Output\JsonEachRow<array{name: string, current_checksum: string, variants: int|string}> $output */
        $output = $this->client->select(
            sprintf(
                'SELECT name, argMax(checksum, applied_at) AS current_checksum, uniqExact(checksum) AS variants FROM `%s` GROUP BY name',
                self::MIGRATIONS_TABLE,
            ),
            new JsonEachRow(),
        );

        $map = [];
        foreach ($output->data as $row) {
            if ((int) $row['variants'] > 1) {
                throw new ClickHouseMigrationException(sprintf(
                    'ClickHouse migration "%s" has %d conflicting checksums recorded; manual intervention required.',
                    $row['name'],
                    (int) $row['variants'],
                ));
            }
            $map[$row['name']] = $row['current_checksum'];
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function getMigrationFiles(): array
    {
        $files = glob(pattern: $this->migrationsPath . '/*.sql');
        if ($files === false) {
            return [];
        }

        sort(array: $files);

        return $files;
    }
}
