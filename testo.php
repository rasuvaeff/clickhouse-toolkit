<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;
use Testo\Bench\BenchmarkPlugin;

return new ApplicationConfig(
    suites: [
        new SuiteConfig(
            name: 'Benchmarks',
            location: new FinderConfig(include: ['benchmarks']),
            plugins: [new BenchmarkPlugin()],
        ),
    ],
);
