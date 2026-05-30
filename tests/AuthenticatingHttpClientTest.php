<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\ClickHouseToolkit\AuthenticatingHttpClient;

#[CoversClass(AuthenticatingHttpClient::class)]
final class AuthenticatingHttpClientTest extends TestCase
{
    #[Test]
    public function addsConfiguredHeadersToEveryRequest(): void
    {
        $captured = null;
        $inner = $this->createMock(ClientInterface::class);
        $inner->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use (&$captured): ResponseInterface {
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

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(RequestInterface::class, $captured);
        $this->assertSame('default', $captured->getHeaderLine('X-ClickHouse-User'));
        $this->assertSame('secret', $captured->getHeaderLine('X-ClickHouse-Key'));
        $this->assertSame('app', $captured->getHeaderLine('X-ClickHouse-Database'));
    }
}
