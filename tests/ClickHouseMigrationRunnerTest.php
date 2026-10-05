<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationState;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationStatus;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Captor;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Output\JsonEachRow as JsonEachRowOutput;
use SimPod\ClickHouseClient\Output\Output;
use SimPod\ClickHouseClient\Schema\Table;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(ClickHouseMigrationRunner::class)]
#[Covers(ClickHouseMigrationException::class)]
#[Covers(ClickHouseMigrationState::class)]
#[Covers(ClickHouseMigrationStatus::class)]
final class ClickHouseMigrationRunnerTest
{
    private const string MIGRATIONS_DIR = __DIR__ . '/Fixtures/migrations';

    /** @var list<string> */
    private array $tempDirs = [];

    private Captor $infoMessages;

    private Captor $infoContexts;

    #[AfterTest]
    public function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeRecursively($dir);
        }
        $this->tempDirs = [];
    }

    public function appliesPendingMigrationsInOrder(): void
    {
        $client = $this->clientReturning('');

        $applied = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        Assert::same($applied, ['001_create_demo.sql', '002_add_name.sql']);
        verify(fn() => $client->insert(Arg::any(), Arg::any()), times: 2);
    }

    public function skipsAlreadyAppliedMigrations(): void
    {
        $client = $this->clientReturning($this->appliedRows());

        Assert::same((new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run(), []);
        verify(fn() => $client->insert(Arg::any(), Arg::any()), never: true);
    }

    public function throwsWhenAppliedMigrationContentChanged(): void
    {
        $row = sprintf('{"name":"001_create_demo.sql","current_checksum":"%s","variants":1}', sha1('tampered'));
        $client = $this->clientReturning($row);

        $runner = new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR);

        Expect::exception(ClickHouseMigrationException::class);

        $runner->run();
    }

    public function throwsWhenMigrationHasConflictingChecksums(): void
    {
        $row = sprintf('{"name":"001_create_demo.sql","current_checksum":"%s","variants":2}', sha1('x'));
        $client = $this->clientReturning($row);

        $runner = new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR);

        Expect::exception(ClickHouseMigrationException::class);

        $runner->run();
    }

    public function ensuresMigrationsTableWithMicrosecondVersionColumn(): void
    {
        $client = $this->tracingClient();

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        $queries = $this->executedQueries($client);
        Assert::string($queries[0])->contains('CREATE TABLE IF NOT EXISTS `_migrations`');
        Assert::string($queries[0])->contains('ReplacingMergeTree(applied_at) ORDER BY name');
        Assert::string($queries[0])->contains('DateTime64(6)');
    }

    public function executesEachMigrationSqlVerbatim(): void
    {
        $client = $this->tracingClient();

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        $queries = $this->executedQueries($client);

        foreach (['001_create_demo.sql', '002_add_name.sql'] as $name) {
            Assert::true(in_array((string) file_get_contents(self::MIGRATIONS_DIR . '/' . $name), $queries, strict: true));
        }
    }

    public function substitutesPlaceholdersBeforeExecuting(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/001_create.sql', 'CREATE TABLE IF NOT EXISTS {{events_table}} (id UInt64) ENGINE = MergeTree ORDER BY id');

        $client = $this->tracingClient();

        (new ClickHouseMigrationRunner($client, $dir, placeholders: ['events_table' => 'custom_events']))->run();

        Assert::true(in_array(
            'CREATE TABLE IF NOT EXISTS custom_events (id UInt64) ENGINE = MergeTree ORDER BY id',
            $this->executedQueries($client),
            strict: true,
        ));
    }

    public function checksumCoversTheResolvedSqlNotTheRawFile(): void
    {
        // this is what keeps an installation on default values from seeing a
        // divergence when a package switches its shipped DDL to placeholders:
        // the resolved text is byte-identical to the file it applied
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/001_create.sql', 'CREATE TABLE {{events_table}} (id UInt64)');

        $client = $this->clientReturning('');

        (new ClickHouseMigrationRunner($client, $dir, placeholders: ['events_table' => 'demo']))->run();

        $inserts = $this->insertedValues($client);
        Assert::same($inserts[0][0]['checksum'], sha1('CREATE TABLE demo (id UInt64)'));
    }

    public function throwsOnAnUnresolvedPlaceholder(): void
    {
        // a typo in the key must name the file and the token, not ship
        // "{{events_table}}" to ClickHouse and fail as a parse error there
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/001_create.sql', 'CREATE TABLE {{events_table}} (id UInt64)');

        $client = $this->tracingClient();
        $caught = null;

        try {
            (new ClickHouseMigrationRunner($client, $dir, placeholders: ['wrong_key' => 'demo']))->run();
        } catch (ClickHouseMigrationException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('001_create.sql');
        Assert::string($caught->getMessage())->contains('{{events_table}}');

        // The bookkeeping CREATE runs before any file is read; the unresolved
        // placeholder must stop everything after it — no migration SQL executed.
        $queries = $this->executedQueries($client);
        Assert::same(count($queries), 1);
        Assert::string($queries[0])->contains('CREATE TABLE IF NOT EXISTS `_migrations`');
    }

    public function recordsAppliedMigrationViaInsert(): void
    {
        $client = $this->clientReturning('');

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        $checksum1 = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/001_create_demo.sql'));
        $checksum2 = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/002_add_name.sql'));
        Assert::same($this->insertCalls($client), [
            [
                'table' => '_migrations',
                'values' => [['name' => '001_create_demo.sql', 'checksum' => $checksum1]],
                'columns' => ['name', 'checksum'],
            ],
            [
                'table' => '_migrations',
                'values' => [['name' => '002_add_name.sql', 'checksum' => $checksum2]],
                'columns' => ['name', 'checksum'],
            ],
        ]);
    }

    public function logsAppliedMigrationViaProvidedLogger(): void
    {
        $logger = $this->logger();
        $client = $this->clientReturning('');

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR, $logger))->run();

        verify(fn() => $logger->info(Arg::any(), Arg::any()), times: 2);
        Assert::same($this->infoMessages->last(), 'Applied ClickHouse migration {name}');
        Assert::true(isset($this->infoContexts->last()['name']));
    }

    public function continuesPastAlreadyAppliedMigrationToApplyNext(): void
    {
        $checksum = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/001_create_demo.sql'));
        $row = sprintf('{"name":"001_create_demo.sql","current_checksum":"%s","variants":1}', $checksum);
        $client = $this->clientReturning($row);

        $applied = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        Assert::same($applied, ['002_add_name.sql']);
        verify(fn() => $client->insert(Arg::any(), Arg::any()), times: 1);
    }

    public function skipsWhitespaceOnlyMigrationButAppliesNext(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/001_blank.sql', "   \n\t");
        file_put_contents($dir . '/002_real.sql', 'CREATE TABLE x (a UInt8) ENGINE = Memory');

        $logger = Understudy::for(LoggerInterface::class);
        $client = $this->clientReturning('');

        $applied = (new ClickHouseMigrationRunner($client, $dir, $logger))->run();

        Assert::same($applied, ['002_real.sql']);
        verify(fn() => $client->insert(Arg::any(), Arg::any()), times: 1);
        verify(fn() => $logger->warning(Arg::any(), Arg::any()), times: 1);
    }

    public function throwsWhenMigrationFileUnreadable(): void
    {
        $dir = $this->makeTempDir();
        symlink($dir . '/missing_target', $dir . '/001_unreadable.sql');

        $client = $this->clientReturning('');
        $runner = new ClickHouseMigrationRunner($client, $dir);

        set_error_handler(static fn(): bool => true);

        try {
            Expect::exception(ClickHouseMigrationException::class);

            $runner->run();
        } finally {
            restore_error_handler();
        }
    }

    public function appliesMigrationFilesInSortedOrder(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/030_c.sql', 'SELECT 30');
        file_put_contents($dir . '/010_a.sql', 'SELECT 10');
        file_put_contents($dir . '/020_b.sql', 'SELECT 20');

        $client = $this->clientReturning('');

        $applied = (new ClickHouseMigrationRunner($client, $dir))->run();

        Assert::same($applied, ['010_a.sql', '020_b.sql', '030_c.sql']);
    }

    public function statusMarksAllFilesAppliedWhenChecksumsMatch(): void
    {
        $client = $this->clientReturning($this->appliedRecordsRows([
            '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 1],
            '002_add_name.sql' => ['2026-06-14 11:00:00.000000', 1],
        ]));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        Assert::same(count($statuses), 2);
        $this->assertApplied('001_create_demo.sql', '2026-06-14 10:00:00.000000', $statuses[0]);
        $this->assertApplied('002_add_name.sql', '2026-06-14 11:00:00.000000', $statuses[1]);
    }

    public function statusMarksAllFilesPendingWhenNothingApplied(): void
    {
        $client = $this->clientReturning('');

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        Assert::same(count($statuses), 2);
        Assert::same($statuses[0]->state, ClickHouseMigrationState::Pending);
        Assert::same($statuses[1]->state, ClickHouseMigrationState::Pending);
        Assert::null($statuses[0]->appliedAt);
        Assert::null($statuses[1]->appliedAt);
    }

    public function statusMarksFileDivergedWhenChecksumMismatches(): void
    {
        $client = $this->clientReturning($this->appliedRecordsRows([
            '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 1],
            '002_add_name.sql' => ['2026-06-14 11:00:00.000000', 1],
        ], 'wrong_checksum'));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        Assert::same($statuses[0]->state, ClickHouseMigrationState::Diverged);
        Assert::same($statuses[1]->state, ClickHouseMigrationState::Diverged);
        Assert::false($statuses[0]->appliedAt === null);
    }

    public function statusMarksFileDivergedWhenConflictingChecksumsRecorded(): void
    {
        $client = $this->clientReturning($this->appliedRecordsRows([
            '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 2],
        ]));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        Assert::same($statuses[0]->state, ClickHouseMigrationState::Diverged);
        Assert::same($statuses[1]->name, '002_add_name.sql');
        Assert::same($statuses[1]->state, ClickHouseMigrationState::Pending);
    }

    public function statusMarksRecordedMigrationsMissingWhenFileGone(): void
    {
        $client = $this->clientReturning($this->appliedRecordsRows([
            '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 1],
            '099_dropped.sql' => ['2026-06-14 12:00:00.000000', 1],
        ]));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        Assert::same(count($statuses), 3);
        Assert::same($statuses[0]->name, '001_create_demo.sql');
        Assert::same($statuses[0]->state, ClickHouseMigrationState::Applied);
        Assert::same($statuses[1]->name, '002_add_name.sql');
        Assert::same($statuses[1]->state, ClickHouseMigrationState::Pending);
        Assert::same($statuses[2]->name, '099_dropped.sql');
        Assert::same($statuses[2]->state, ClickHouseMigrationState::Missing);
        Assert::null($statuses[2]->checksum);
        Assert::same($statuses[2]->appliedAt, '2026-06-14 12:00:00.000000');
    }

    public function statusSortsByNameAcrossAllStates(): void
    {
        $client = $this->clientReturning($this->appliedRecordsRows([
            '000_z.sql' => ['2026-06-14 09:00:00.000000', 1],
        ]));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        $names = array_map(static fn(ClickHouseMigrationStatus $s): string => $s->name, $statuses);
        Assert::same($names, ['000_z.sql', '001_create_demo.sql', '002_add_name.sql']);
    }

    public function statusDivergedShowsCurrentFileChecksum(): void
    {
        $client = $this->clientReturning($this->appliedRecordsRows([
            '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 1],
        ], 'stored_value'));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        $expectedFileChecksum = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/001_create_demo.sql'));
        Assert::same($statuses[0]->state, ClickHouseMigrationState::Diverged);
        Assert::same($statuses[0]->checksum, $expectedFileChecksum);
    }

    public function statusCreatesMigrationsTableBeforeReading(): void
    {
        $client = $this->tracingClient();

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        $queries = $this->executedQueries($client);
        Assert::string($queries[0])->contains('CREATE TABLE IF NOT EXISTS `_migrations`');
    }

    public function statusDoesNotThrowOnDivergedOrConflictingRecords(): void
    {
        $client = $this->clientReturning($this->appliedRecordsRows([
            '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 5],
        ], 'totally_wrong'));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        Assert::same($statuses[0]->state, ClickHouseMigrationState::Diverged);
    }

    public function recordsAppliedMigrationsInDefaultTableWhenNoneIsConfigured(): void
    {
        $client = $this->tracingClient();

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        Assert::string($this->executedQueries($client)[0])->contains('CREATE TABLE IF NOT EXISTS `_migrations`');
        Assert::string($this->executedSelects($client)[0])->contains('FROM `_migrations`');
        Assert::same($this->insertedTables($client), ['_migrations', '_migrations']);
    }

    public function routesEveryBookkeepingStatementToTheConfiguredTable(): void
    {
        $client = $this->tracingClient();

        $runner = new ClickHouseMigrationRunner(
            $client,
            self::MIGRATIONS_DIR,
            migrationsTable: 'app_schema_migrations',
        );

        $runner->run();
        $runner->status();

        $queries = $this->executedQueries($client);
        $selects = $this->executedSelects($client);

        // CREATE (run) + the two migration files + CREATE (status).
        Assert::string($queries[0])->contains('CREATE TABLE IF NOT EXISTS `app_schema_migrations`');
        Assert::string($queries[3])->contains('CREATE TABLE IF NOT EXISTS `app_schema_migrations`');
        // getApplied() for run(), fetchAppliedRecords() for status().
        Assert::string($selects[0])->contains('FROM `app_schema_migrations`');
        Assert::string($selects[1])->contains('FROM `app_schema_migrations`');
        Assert::same($this->insertedTables($client), ['app_schema_migrations', 'app_schema_migrations']);
        Assert::same(
            array_filter($queries, static fn(string $sql): bool => str_contains($sql, '_migrations`')),
            array_filter($queries, static fn(string $sql): bool => str_contains($sql, 'app_schema_migrations`')),
        );
    }

    /**
     * A mistyped or stand-specific path used to be indistinguishable from an
     * empty directory: glob() returns [] for both, so run() reported success
     * having created nothing. The path is the one setting that cannot be
     * verified locally, where it is correct by definition.
     */
    public function runThrowsWhenMigrationsPathIsNotADirectory(): void
    {
        $client = $this->clientReturning('');
        $path = sys_get_temp_dir() . '/chmigr_absent_' . uniqid('', more_entropy: true);

        $runner = new ClickHouseMigrationRunner($client, $path);

        Expect::exception(ClickHouseMigrationException::class)->withMessageContaining($path);

        $runner->run();
    }

    public function statusThrowsWhenMigrationsPathIsNotADirectory(): void
    {
        $client = $this->clientReturning('');
        $path = sys_get_temp_dir() . '/chmigr_absent_' . uniqid('', more_entropy: true);

        $runner = new ClickHouseMigrationRunner($client, $path);

        Expect::exception(ClickHouseMigrationException::class)->withMessageContaining($path);

        $runner->status();
    }

    /**
     * A file where the directory is expected is the same misconfiguration.
     */
    public function runThrowsWhenMigrationsPathIsAFile(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir . '/not-a-directory.sql';
        file_put_contents($file, 'CREATE TABLE x (a UInt8) ENGINE = Memory');

        $client = $this->clientReturning('');
        $runner = new ClickHouseMigrationRunner($client, $file);

        Expect::exception(ClickHouseMigrationException::class)->withMessageContaining($file);

        $runner->run();
    }

    /**
     * The name is interpolated into SQL rather than bound, so anything that is
     * not a plain identifier — a `db.table` form included, since backticks wrap
     * the whole string — must be refused before a single statement is sent.
     */
    #[Property(runs: 300)]
    public function acceptsExactlyPlainIdentifiersAsTheBookkeepingTable(string $table): void
    {
        $valid = (bool) preg_match('/^[A-Za-z_]\w*\z/', $table);
        // The alphabet yields ~19% accepted names (measured over 20k draws):
        // only a `.` anywhere, or a leading digit, disqualifies one. The gates
        // sit far enough below that a green run never trips them by chance,
        // while still failing if a generator change stops reaching a branch.
        Classify::cover($valid, 'accepted', 8.0);
        Classify::cover(!$valid, 'rejected', 40.0);

        $client = $this->tracingClient();

        try {
            $runner = new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR, migrationsTable: $table);
        } catch (InvalidArgumentException) {
            Assert::false($valid);
            Assert::same($this->executedQueries($client), []);

            return;
        }

        Assert::true($valid);

        $runner->run();
        $runner->status();

        $queries = $this->executedQueries($client);
        $selects = $this->executedSelects($client);
        Assert::string($queries[0])->contains(sprintf('CREATE TABLE IF NOT EXISTS `%s`', $table));
        Assert::string($selects[0])->contains(sprintf('FROM `%s`', $table));
        Assert::string($selects[1])->contains(sprintf('FROM `%s`', $table));
        Assert::same($this->insertedTables($client), [$table, $table]);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function acceptsExactlyPlainIdentifiersAsTheBookkeepingTableGenerators(): array
    {
        return ['table' => Gen::stringFrom(alphabet: 'abAB01_.', minLength: 0, maxLength: 24)];
    }

    /**
     * @return iterable<array{string}>
     */
    public static function acceptsExactlyPlainIdentifiersAsTheBookkeepingTableExamples(): iterable
    {
        yield 'the default' => ['_migrations'];
        yield 'plain name' => ['app_migrations'];
        yield 'db-qualified is refused' => ['analytics._migrations'];
        yield 'empty' => [''];
        yield 'leading digit' => ['1_migrations'];
        yield 'statement break' => ['_migrations`; DROP TABLE users; --'];
    }

    private function logger(): LoggerInterface
    {
        $logger = Understudy::for(LoggerInterface::class);
        $this->infoMessages = Arg::captor();
        $this->infoContexts = Arg::captor();

        when(fn() => $logger->info($this->infoMessages->capture(), $this->infoContexts->capture()));

        return $logger;
    }

    /**
     * A client whose bookkeeping reads answer with the given rows.
     */
    private function clientReturning(string $rowsJson): ClickHouseClient
    {
        $client = Understudy::for(ClickHouseClient::class);

        when(fn() => $client->select(Arg::any(), Arg::any()))->returns($this->chOutput($rowsJson));

        return $client;
    }

    /**
     * A client whose bookkeeping reads answer with no rows — for the tests
     * that read back what the runner sent through the other methods.
     */
    private function tracingClient(): ClickHouseClient
    {
        return $this->clientReturning('');
    }

    /** @return list<string> */
    private function executedQueries(ClickHouseClient $client): array
    {
        return array_map(
            static fn(Invocation $call): string => $call->arg('query'),
            Understudy::calls(fn() => $client->executeQuery(Arg::any())),
        );
    }

    /** @return list<string> */
    private function executedSelects(ClickHouseClient $client): array
    {
        return array_map(
            static fn(Invocation $call): string => $call->arg('query'),
            Understudy::calls(fn() => $client->select(Arg::any(), Arg::any())),
        );
    }

    /** @return list<string> */
    private function insertedTables(ClickHouseClient $client): array
    {
        return array_map(
            static fn(Invocation $call): string => $call->arg('table') instanceof Table ? $call->arg('table')->fullName() : $call->arg('table'),
            Understudy::calls(fn() => $client->insert(Arg::any(), Arg::any())),
        );
    }

    /**
     * @return list<list<array<string, mixed>>>
     */
    private function insertedValues(ClickHouseClient $client): array
    {
        return array_map(
            static fn(Invocation $call): array => $call->arg('values'),
            Understudy::calls(fn() => $client->insert(Arg::any(), Arg::any())),
        );
    }

    /**
     * @return list<array{table: string, values: list<array<string, mixed>>, columns: list<string>}>
     */
    private function insertCalls(ClickHouseClient $client): array
    {
        return array_map(
            static fn(Invocation $call): array => [
                'table' => $call->arg('table'),
                'values' => $call->arg('values'),
                'columns' => $call->arg('columns'),
            ],
            Understudy::calls(fn() => $client->insert(Arg::any(), Arg::any())),
        );
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/chmig_' . uniqid('', more_entropy: true);
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeRecursively(string $path): void
    {
        if (is_dir($path)) {
            $entries = scandir($path);
            foreach (array_diff($entries === false ? [] : $entries, ['.', '..']) as $entry) {
                $this->removeRecursively($path . '/' . $entry);
            }
            rmdir($path);

            return;
        }

        unlink($path);
    }

    private function appliedRows(): string
    {
        $rows = [];
        foreach (['001_create_demo.sql', '002_add_name.sql'] as $name) {
            $checksum = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/' . $name));
            $rows[] = sprintf('{"name":"%s","current_checksum":"%s","variants":1}', $name, $checksum);
        }

        return implode("\n", $rows);
    }

    /**
     * Builds the rows returned by {@see ClickHouseMigrationRunner::fetchAppliedRecords()}.
     *
     * @param array<string, array{0: string, 1: int}> $records name => [appliedAt, variants]
     * @param string|null $checksumOverride        use the real file checksum when null.
     */
    private function appliedRecordsRows(array $records, ?string $checksumOverride = null): string
    {
        $rows = [];
        foreach ($records as $name => [$appliedAt, $variants]) {
            $checksum = $checksumOverride ?? @sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/' . $name));
            $rows[] = sprintf(
                '{"name":"%s","current_checksum":"%s","current_applied_at":"%s","variants":%d}',
                $name,
                $checksum,
                $appliedAt,
                $variants,
            );
        }

        return implode("\n", $rows);
    }

    private function assertApplied(string $name, string $appliedAt, ClickHouseMigrationStatus $status): void
    {
        Assert::same($status->name, $name);
        Assert::same($status->state, ClickHouseMigrationState::Applied);
        Assert::same($status->appliedAt, $appliedAt);
        Assert::notNull($status->checksum);
    }

    /**
     * Builds a stub ClickHouse output from newline-delimited JSON rows.
     */
    private function chOutput(string $rowsJson): Output
    {
        return new JsonEachRowOutput($rowsJson);
    }
}
