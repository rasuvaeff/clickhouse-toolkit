<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Command;

use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a new ClickHouse migration file with the next sequential numeric prefix.
 *
 * Wraps {@see ClickHouseMigrationGenerator::generate()}. The generator is a plain
 * filesystem helper; this command never touches a ClickHouse server.
 *
 * @api
 */
#[AsCommand(
    name: 'clickhouse:migrations:generate',
    description: 'Create a new ClickHouse migration file with the next sequential prefix',
)]
final class ClickHouseMigrationsGenerateCommand extends Command
{
    public function __construct(
        private readonly ClickHouseMigrationGenerator $generator,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument(
                'description',
                InputArgument::REQUIRED,
                'Human-readable description, sanitised to a slug for the filename.',
            )
            ->setHelp(
                <<<'HELP'
                    Creates <info>NNN_description.sql</info> in the configured migrations directory,
                    where <info>NNN</info> is one greater than the highest existing numeric prefix.

                    The description is sanitised: lowercase, every run of non-alphanumeric characters
                    collapses to a single underscore, leading/trailing underscores are stripped.

                    The file is created with a header comment — write your DDL after it.
                    HELP,
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $description = (string) $input->getArgument('description');

        try {
            $path = $this->generator->generate($description);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Created migration: %s', basename($path)));
        $io->writeln(sprintf('<comment>%s</comment>', $path));

        return Command::SUCCESS;
    }
}
