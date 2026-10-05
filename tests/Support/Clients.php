<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Output\JsonEachRow;
use SimPod\ClickHouseClient\Output\Output;
use SimPod\ClickHouseClient\Schema\Table;

use function Rasuvaeff\Understudy\when;

/**
 * Understudy doubles of {@see ClickHouseClient} and readers of what they received.
 *
 * @internal
 */
final class Clients
{
    /**
     * Accepts every write and answers every read with an empty result.
     */
    public static function plain(?Output $rows = null): ClickHouseClient
    {
        $client = Understudy::for(ClickHouseClient::class);
        $output = $rows ?? new JsonEachRow('');
        when(fn() => $client->select(Arg::any(), Arg::any()))->returns($output);
        when(fn() => $client->selectWithParams(Arg::any(), Arg::any(), Arg::any()))->returns($output);

        return $client;
    }

    /**
     * @return list<string>
     */
    public static function executedQueries(ClickHouseClient $client): array
    {
        return array_map(
            static fn(Invocation $call): string => $call->arg('query'),
            Understudy::calls(fn() => $client->executeQuery(Arg::any())),
        );
    }

    /**
     * @return list<Invocation>
     */
    public static function parameterisedExecutions(ClickHouseClient $client): array
    {
        return Understudy::calls(fn() => $client->executeQueryWithParams(Arg::any(), Arg::any()));
    }

    /**
     * @return list<Invocation>
     */
    public static function selects(ClickHouseClient $client): array
    {
        return Understudy::calls(fn() => $client->select(Arg::any(), Arg::any()));
    }

    /**
     * @return list<Invocation>
     */
    public static function parameterisedSelects(ClickHouseClient $client): array
    {
        return Understudy::calls(fn() => $client->selectWithParams(Arg::any(), Arg::any(), Arg::any()));
    }

    /**
     * @return list<Invocation>
     */
    public static function inserts(ClickHouseClient $client): array
    {
        return Understudy::calls(fn() => $client->insert(Arg::any(), Arg::any()));
    }

    public static function insertedTable(Invocation $insert): Table|string
    {
        /** @var Table|string */
        return $insert->arg('table');
    }

    /**
     * A PSR-18 client that answers every request with the given response.
     */
    public static function http(?ResponseInterface $response = null): ClientInterface
    {
        $http = Understudy::for(ClientInterface::class);
        when(fn() => $http->sendRequest(Arg::any()))->returns($response ?? new Response(200, [], 'Ok.'));

        return $http;
    }

    /**
     * @return list<RequestInterface> every request the PSR-18 double received, in order
     */
    public static function sentRequests(ClientInterface $http): array
    {
        return array_map(
            static fn(Invocation $call): RequestInterface => $call->arg('request'),
            Understudy::calls(fn() => $http->sendRequest(Arg::any())),
        );
    }
}
