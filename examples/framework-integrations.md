# Framework integrations

The migration API (`ClickHouseMigrationRunner`, `ClickHouseMigrationGenerator`,
`status()`) is plain PHP. This document shows how to wire it directly into the
DI container / console layer of common frameworks — **without** the bundled
`Symfony\Component\Console` commands in `src/Command/`.

If you are already on Symfony (or use `symfony/console` in your app), the
[bundled commands](console-application.php) are the fastest path. The recipes
below are for apps that want a native command in their own stack.

---

## Yii3

Yii3 ships [`yiisoft/yii-console`](https://github.com/yiisoft/yii-console) (a
thin Symfony Console wrapper) and [`yiisoft/di`](https://github.com/yiisoft/di).
Register the runner + generator in the container, then expose them through a
single `Command` extending `Yiisoft\Yii\Console\AbstractCommand`.

### `config/common/di.php`

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationGenerator;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunnerInterface;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Client\PsrClickHouseClient;

return [
    ClickHouseConfig::class => [
        'class' => ClickHouseConfig::class,
        '__construct()' => [
            'host' => $_ENV['CLICKHOUSE_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['CLICKHOUSE_PORT'] ?? 8123),
            'database' => $_ENV['CLICKHOUSE_DB'] ?? 'default',
            'username' => $_ENV['CLICKHOUSE_USER'] ?? 'default',
            'password' => $_ENV['CLICKHOUSE_PASSWORD'] ?? '',
        ],
    ],

    ClickHouseClient::class => static fn(ClickHouseClientFactory $f): PsrClickHouseClient => $f->create(),

    ClickHouseMigrationRunnerInterface::class => ClickHouseMigrationRunner::class,

    ClickHouseMigrationRunner::class => [
        'class' => ClickHouseMigrationRunner::class,
        '__construct()' => [
            'migrationsPath' => dirname(__DIR__, 2) . '/resources/clickhouse/migrations',
        ],
    ],

    ClickHouseMigrationGenerator::class => [
        'class' => ClickHouseMigrationGenerator::class,
        '__construct()' => [
            'migrationsPath' => dirname(__DIR__, 2) . '/resources/clickhouse/migrations',
        ],
    ],
];
```

### `src/Command/ClickHouseMigrateCommand.php`

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;

final class ClickHouseMigrateCommand extends Command
{
    public function __construct(
        private readonly ClickHouseMigrationRunner $runner,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('clickhouse:migrate')->setDescription('Apply pending ClickHouse migrations');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $applied = $this->runner->run();

        if ($applied === []) {
            $output->writeln('<info>Up to date.</info>');

            return ExitCode::OK;
        }

        foreach ($applied as $name) {
            $output->writeln("  <info>✓</info> $name");
        }

        return ExitCode::OK;
    }
}
```

### `config/console/commands.php`

```php
use App\Command\ClickHouseMigrateCommand;

return [
    'clickhouse:migrate' => ClickHouseMigrateCommand::class,
];
```

Run with `./yii clickhouse:migrate`. Repeat the pattern for `status()` (custom
table output) and `generate()` (add an `InputArgument`).

---

## Symfony (full-stack)

With Symfony's default service configuration (autowire + autoconfigure), drop
the bundled commands into `src/Command/` and they auto-register. If you prefer
your own command, bind the dependencies and call the API directly.

### `config/services.yaml`

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    Rasuvaeff\ClickHouseToolkit\ClickHouseConfig:
        arguments:
            $host: '%env(CLICKHOUSE_HOST)%'
            $port: '%env(int:CLICKHOUSE_PORT)%'
            $database: '%env(CLICKHOUSE_DB)%'
            $username: '%env(CLICKHOUSE_USER)%'
            $password: '%env(CLICKHOUSE_PASSWORD)%'

    Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner:
        arguments:
            $migrationsPath: '%kernel.project_dir%/migrations/clickhouse'

    Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationGenerator:
        arguments:
            $migrationsPath: '%kernel.project_dir%/migrations/clickhouse'

    # PsrClickHouseClient and ClickHouseClientFactory are autowired via the interfaces
    # from php-http/discovery and the installed PSR-18 / PSR-17 packages.
```

### `src/Command/ClickHouseMigrateCommand.php`

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:clickhouse:migrate', description: 'Apply pending ClickHouse migrations')]
final class ClickHouseMigrateCommand extends Command
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

        foreach ($this->runner->run() as $name) {
            $io->text("  <info>✓</info> $name");
        }

        $io->success('Done.');

        return Command::SUCCESS;
    }
}
```

Run with `bin/console app:clickhouse:migrate`.

---

## Laravel

Laravel's container binds interfaces in a service provider; Artisan commands
extend `Illuminate\Console\Command`.

### `app/Providers/AppServiceProvider.php`

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationGenerator;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Client\PsrClickHouseClient;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClickHouseConfig::class, static fn (): ClickHouseConfig => new ClickHouseConfig(
            host: (string) config('services.clickhouse.host', '127.0.0.1'),
            port: (int) config('services.clickhouse.port', 8123),
            database: (string) config('services.clickhouse.database', 'default'),
            username: (string) config('services.clickhouse.username', 'default'),
            password: (string) config('services.clickhouse.password', ''),
        ));

        $this->app->singleton(ClickHouseClient::class, static function ($app): PsrClickHouseClient {
            /** @var ClickHouseClientFactory $factory */
            $factory = $app->make(ClickHouseClientFactory::class);

            return $factory->create();
        });

        $path = database_path('clickhouse-migrations');

        $this->app->singleton(ClickHouseMigrationRunner::class, static fn ($app): ClickHouseMigrationRunner => new ClickHouseMigrationRunner(
            client: $app->make(ClickHouseClient::class),
            migrationsPath: $path,
        ));

        $this->app->singleton(ClickHouseMigrationGenerator::class, static fn (): ClickHouseMigrationGenerator => new ClickHouseMigrationGenerator($path));
    }
}
```

### `app/Console/Commands/ClickHouseMigrate.php`

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;

final class ClickHouseMigrate extends Command
{
    protected $signature = 'clickhouse:migrate';
    protected $description = 'Apply pending ClickHouse migrations';

    public function __construct(
        private readonly ClickHouseMigrationRunner $runner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $applied = $this->runner->run();

        if ($applied === []) {
            $this->info('Up to date.');

            return self::SUCCESS;
        }

        foreach ($applied as $name) {
            $this->line("  <info>✓</info> $name");
        }

        return self::SUCCESS;
    }
}
```

Run with `php artisan clickhouse:migrate`. For `status()`, render the array with
`$this->table(['Migration', 'State', 'Checksum', 'Applied at'], $rows)`.

---

## Plain PHP (no framework)

If you have no DI container, see [`run-migrations.php`](run-migrations.php) and
[`generate-migration.php`](generate-migration.php) for direct construction with
`new` — that is the only thing the framework recipes above do for you.
