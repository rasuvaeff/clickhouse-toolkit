<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use Rasuvaeff\ClickHouseToolkit\ContextClickHouseClient;
use Rasuvaeff\ClickHouseToolkit\Tests\Support\Clients;
use Rasuvaeff\ClickHouseToolkit\Tests\Support\SteppingTimeSource;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\TimeSource;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
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
        Assert::same($calls[0]->arg('settings'), [
            'max_execution_time' => 0.4,
            'cancel_http_readonly_queries_on_client_close' => 1,
        ]);
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
        Assert::same($calls[0]->arg('settings'), [
            'max_execution_time' => 2,
            'cancel_http_readonly_queries_on_client_close' => 1,
        ]);
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

    /**
     * @param array<string, float|int|string> $configured
     * @param array<string, float|int|string> $expected
     */
    #[DataProvider('configuredExecutionSettingProvider')]
    public function theBudgetReplacesALooserOrUnlimitedExecutionSetting(array $configured, array $expected): void
    {
        [$context, $controller] = Context::background(new SteppingTimeSource(0))->withTimeout(1.25);
        $client = Clients::plain();

        (new ContextClickHouseClient($client, $context))->executeQuery('SELECT 1', $configured);

        $calls = Understudy::calls(fn() => $client->executeQuery(Arg::any(), Arg::any()));
        Assert::same($calls[0]->arg('settings'), $expected);
        $controller->cancel();
    }

    public static function configuredExecutionSettingProvider(): iterable
    {
        $cancel = ['cancel_http_readonly_queries_on_client_close' => 1];

        yield 'unlimited zero' => [['max_execution_time' => 0], ['max_execution_time' => 1.25] + $cancel];
        yield 'negative' => [['max_execution_time' => -5], ['max_execution_time' => 1.25] + $cancel];
        yield 'looser' => [['max_execution_time' => 30], ['max_execution_time' => 1.25] + $cancel];
        yield 'equal is kept' => [['max_execution_time' => '1.25'], ['max_execution_time' => '1.25'] + $cancel];
        yield 'stricter fraction is kept' => [['max_execution_time' => 0.5], ['max_execution_time' => 0.5] + $cancel];
        yield 'not numeric' => [['max_execution_time' => 'soon'], ['max_execution_time' => 1.25] + $cancel];
        yield 'explicit cancel setting wins' => [
            ['cancel_http_readonly_queries_on_client_close' => 0],
            ['cancel_http_readonly_queries_on_client_close' => 0, 'max_execution_time' => 1.25],
        ];
    }

    public function withoutADeadlineOnlyTheCancelSettingIsAdded(): void
    {
        $client = Clients::plain();

        (new ContextClickHouseClient($client, Context::background()))->executeQuery('SELECT 1');

        $calls = Understudy::calls(fn() => $client->executeQuery(Arg::any(), Arg::any()));
        Assert::same($calls[0]->arg('settings'), ['cancel_http_readonly_queries_on_client_close' => 1]);
    }

    public function aDeadlinePassingBeforeTheQueryNeverYieldsAnUnlimitedOne(): void
    {
        // Every clock read moves 0.6 s, so the deadline passes between the
        // budget and the check; a 0 max_execution_time would be unlimited.
        [$context, $controller] = Context::background(new SteppingTimeSource(600_000_000))->withTimeout(1.0);
        $client = Clients::plain();

        try {
            (new ContextClickHouseClient($client, $context))->executeQuery('SELECT 1');
        } catch (ContextExpiredException) {
            Assert::same(Understudy::calls(fn() => $client->executeQuery(Arg::any(), Arg::any())), []);

            return;
        } finally {
            $controller->cancel();
        }

        throw new \LogicException('an expiry was expected, the query was sent');
    }
}
