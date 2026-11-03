<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use GuzzleHttp\Psr7\Utils;
use Rasuvaeff\ClickHouseToolkit\ContextClickHouseClient;
use Rasuvaeff\ClickHouseToolkit\Tests\Support\Clients;
use Rasuvaeff\ClickHouseToolkit\Tests\Support\ManualTimeSource;
use Rasuvaeff\ClickHouseToolkit\Tests\Support\SteppingTimeSource;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\TimeSource;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Format\JsonEachRow as JsonEachRowFormat;
use SimPod\ClickHouseClient\Output\JsonEachRow;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

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

    /**
     * @param \Closure(ClickHouseClient): mixed $call
     * @param \Closure(ClickHouseClient, \Closure(): void): void $stub
     */
    #[DataProvider('everyOperationProvider')]
    public function everyOperationReachesTheWrappedClientThenChecksTheContextAgain(\Closure $call, \Closure $stub): void
    {
        $clock = new ManualTimeSource();
        [$context, $controller] = Context::background($clock)->withTimeout(5.0);
        $inner = Understudy::for(ClickHouseClient::class);
        $stub($inner, static function () use ($clock): void {
            $clock->advanceSeconds(10);
        });

        try {
            $call(new ContextClickHouseClient($inner, $context));
        } catch (ContextExpiredException) {
            return;
        } finally {
            $controller->cancel();
        }

        throw new \LogicException('the context expired during the call, an expiry was expected');
    }

    /**
     * @return iterable<string, array{\Closure, \Closure}>
     */
    public static function everyOperationProvider(): iterable
    {
        yield 'executeQuery' => [
            static fn(ClickHouseClient $c) => $c->executeQuery('SELECT 1'),
            static fn(ClickHouseClient $i, \Closure $expire) => when(fn() => $i->executeQuery(Arg::any(), Arg::any()))->answers($expire),
        ];
        yield 'executeQueryWithParams' => [
            static fn(ClickHouseClient $c) => $c->executeQueryWithParams('SELECT {a:UInt8}', ['a' => 1]),
            static fn(ClickHouseClient $i, \Closure $expire) => when(fn() => $i->executeQueryWithParams(Arg::any(), Arg::any(), Arg::any()))->answers($expire),
        ];
        yield 'select' => [
            static fn(ClickHouseClient $c) => $c->select('SELECT 1', new JsonEachRowFormat()),
            static fn(ClickHouseClient $i, \Closure $expire) => when(fn() => $i->select(Arg::any(), Arg::any(), Arg::any()))->answers(static function () use ($expire): JsonEachRow {
                $expire();

                return new JsonEachRow('');
            }),
        ];
        yield 'selectWithParams' => [
            static fn(ClickHouseClient $c) => $c->selectWithParams('SELECT {a:UInt8}', ['a' => 1], new JsonEachRowFormat()),
            static fn(ClickHouseClient $i, \Closure $expire) => when(fn() => $i->selectWithParams(Arg::any(), Arg::any(), Arg::any(), Arg::any()))->answers(static function () use ($expire): JsonEachRow {
                $expire();

                return new JsonEachRow('');
            }),
        ];
        yield 'insert' => [
            static fn(ClickHouseClient $c) => $c->insert('events', [[1]], ['id']),
            static fn(ClickHouseClient $i, \Closure $expire) => when(fn() => $i->insert(Arg::any(), Arg::any(), Arg::any(), Arg::any()))->answers($expire),
        ];
        yield 'insertWithFormat' => [
            static fn(ClickHouseClient $c) => $c->insertWithFormat('events', new JsonEachRowFormat(), '{"id":1}'),
            static fn(ClickHouseClient $i, \Closure $expire) => when(fn() => $i->insertWithFormat(Arg::any(), Arg::any(), Arg::any(), Arg::any()))->answers($expire),
        ];
        yield 'insertPayload' => [
            static fn(ClickHouseClient $c) => $c->insertPayload('events', new JsonEachRowFormat(), Utils::streamFor('{"id":1}')),
            static fn(ClickHouseClient $i, \Closure $expire) => when(fn() => $i->insertPayload(Arg::any(), Arg::any(), Arg::any(), Arg::any(), Arg::any()))->answers($expire),
        ];
    }
}
