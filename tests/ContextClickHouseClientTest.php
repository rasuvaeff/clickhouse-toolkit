<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use Rasuvaeff\ClickHouseToolkit\ContextClickHouseClient;
use Rasuvaeff\ClickHouseToolkit\Tests\Support\Clients;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\TimeSource;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ContextClickHouseClient::class)]
final class ContextClickHouseClientTest
{
    public function mapsRemainingBudgetToExecutionSetting(): void
    {
        $source = new class implements TimeSource {
            #[\Override]
            public function nowNs(): int
            {
                return 1_000_000_000;
            }
        };
        [$context, $controller] = Context::background($source)->withTimeout(0.4);
        $client = Clients::plain();

        (new ContextClickHouseClient($client, $context))->executeQuery('SELECT 1');

        $calls = Understudy::calls(fn() => $client->executeQuery(Arg::any(), Arg::any()));
        Assert::same($calls[0]->arg('settings'), ['max_execution_time' => 1]);
        $controller->cancel();
    }

    public function neverExpandsAStricterConfiguredExecutionSetting(): void
    {
        $source = new class implements TimeSource {
            #[\Override]
            public function nowNs(): int
            {
                return 1_000_000_000;
            }
        };
        [$context, $controller] = Context::background($source)->withTimeout(5.0);
        $client = Clients::plain();

        (new ContextClickHouseClient($client, $context))->executeQuery(
            'SELECT 1',
            ['max_execution_time' => 2],
        );

        $calls = Understudy::calls(fn() => $client->executeQuery(Arg::any(), Arg::any()));
        Assert::same($calls[0]->arg('settings'), ['max_execution_time' => 2]);
        $controller->cancel();
    }

    public function refusesAnExpiredContextBeforeStartingQuery(): void
    {
        $source = new class implements TimeSource {
            #[\Override]
            public function nowNs(): int
            {
                return 2_000_000_000;
            }
        };
        [$context, $controller] = Context::background($source)->withTimeout(0.0);
        $client = Clients::plain();

        Expect::exception(ContextExpiredException::class);
        (new ContextClickHouseClient($client, $context))->executeQuery('SELECT 1');

        Assert::same(Understudy::calls(fn() => $client->executeQuery(Arg::any(), Arg::any())), []);
        $controller->cancel();
    }
}
