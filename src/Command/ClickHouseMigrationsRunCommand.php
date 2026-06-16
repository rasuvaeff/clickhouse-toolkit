<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Command;

use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
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
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($applied === []) {
            $io->success('Schema is up to date — nothing to apply.');

            return Command::SUCCESS;
        }

        foreach ($applied as $name) {
            $io->text(sprintf('  <info>✓</info> %s', $name));
        }

        $io->success(sprintf('Applied %d migration(s).', count($applied)));

        return Command::SUCCESS;
    }
}
