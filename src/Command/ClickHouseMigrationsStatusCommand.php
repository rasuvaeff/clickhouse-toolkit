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
 * Reports the state of every migration file relative to the `_migrations` table.
 *
 * Wraps {@see ClickHouseMigrationRunner::status()}. Prints a table with one row
 * per migration (applied / pending / missing / diverged) and a summary line.
 *
 * @api
 */
#[AsCommand(
    name: 'clickhouse:migrations:status',
    description: 'Show the state of every ClickHouse migration (applied / pending / missing / diverged)',
)]
final class ClickHouseMigrationsStatusCommand extends Command
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
            $statuses = $this->runner->status();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $rows = [];
        $counts = [ClickHouseMigrationState::Applied->value => 0, ClickHouseMigrationState::Pending->value => 0, ClickHouseMigrationState::Missing->value => 0, ClickHouseMigrationState::Diverged->value => 0];

        foreach ($statuses as $status) {
            $counts[$status->state->value]++;
            $rows[] = [
                $status->name,
                $status->state->value,
                $status->checksum ?? '',
                $status->appliedAt ?? '',
            ];
        }

        $io->table(['Migration', 'State', 'Checksum', 'Applied at'], $rows);

        $io->writeln(sprintf(
            '<info>%d</info> applied, <comment>%d</comment> pending, <fg=red>%d</fg=red> missing, <fg=red>%d</fg=red> diverged',
            $counts[ClickHouseMigrationState::Applied->value],
            $counts[ClickHouseMigrationState::Pending->value],
            $counts[ClickHouseMigrationState::Missing->value],
            $counts[ClickHouseMigrationState::Diverged->value],
        ));

        return $counts[ClickHouseMigrationState::Diverged->value] > 0 || $counts[ClickHouseMigrationState::Missing->value] > 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
