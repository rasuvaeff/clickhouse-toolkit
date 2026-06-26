<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Rasuvaeff\ClickHouseToolkit\AuthenticatingHttpClient;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(AuthenticatingHttpClient::class)]
final class AuthenticatingHttpClientTest
{
    public function addsConfiguredHeadersToEveryRequest(): void
    {
        $captured = null;
        $inner = (new FakePsrHttpClient())->withSendRequestCallback(
            static function (RequestInterface $request) use (&$captured) {
                $captured = $request;

                return new Response(200);
            },
        );

        $client = new AuthenticatingHttpClient($inner, [
            'X-ClickHouse-User' => 'default',
            'X-ClickHouse-Key' => 'secret',
            'X-ClickHouse-Database' => 'app',
        ]);

        $response = $client->sendRequest(new Request('POST', 'http://ch:8123/'));

        Assert::same($response->getStatusCode(), 200);
        Assert::instanceOf($captured, RequestInterface::class);
        Assert::same($captured->getHeaderLine('X-ClickHouse-User'), 'default');
        Assert::same($captured->getHeaderLine('X-ClickHouse-Key'), 'secret');
        Assert::same($captured->getHeaderLine('X-ClickHouse-Database'), 'app');
    }
}
