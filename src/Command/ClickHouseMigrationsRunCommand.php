<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Command;

use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Applies pending ClickHouse migrations from the configured directory.
 *
 * Wraps {@see ClickHouseMigrationRunner::run()}. Prints one line per applied
 * migration and a summary. Idempotent — already-applied migrations are skipped.
 *
 * After applying, it reads {@see ClickHouseMigrationRunner::status()} once more to
 * report recorded migrations whose file is gone from disk. `run()` cannot see them
 * — it only walks the files — so without this the two commands contradicted each
 * other: `status` reported `1 missing` where `migrate` reported an up-to-date
 * schema. Missing records are a warning here and still exit `SUCCESS`: unlike
 * `status`, this command's job is applying what is pending, and a deleted file is
 * not a reason to fail a deploy.
 *
 * @api
 */
#[AsCommand(
    name: 'clickhouse:migrations:migrate',
    description: 'Apply pending ClickHouse migrations',
)]
final class ClickHouseMigrationsRunCommand extends Command
{
    public function __construct(
        private readonly ClickHouseMigrationRunner $runner,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $applied = $this->runner->run();
            $missing = $this->missingMigrations();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($applied as $name) {
            $io->text(sprintf('  <info>✓</info> %s', $name));
        }

        if ($missing !== []) {
            $io->warning(sprintf(
                '%d recorded migration(s) no longer exist on disk: %s',
                count($missing),
                implode(', ', $missing),
            ));
        }

        $io->success(match (true) {
            $applied !== [] => sprintf('Applied %d migration(s).', count($applied)),
            $missing !== [] => 'Nothing to apply; recorded migrations are missing from disk (see above).',
            default => 'Schema is up to date — nothing to apply.',
        });

        return Command::SUCCESS;
    }

    /**
     * @return list<string> Names recorded in the bookkeeping table with no file on disk.
     */
    private function missingMigrations(): array
    {
        $missing = [];

        foreach ($this->runner->status() as $status) {
            if ($status->state === ClickHouseMigrationState::Missing) {
                $missing[] = $status->name;
            }
        }

        return $missing;
    }
}
