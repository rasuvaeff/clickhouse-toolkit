<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;

#[CoversClass(ClickHouseClientFactory::class)]
final class ClickHouseClientFactoryTest extends TestCase
{
    #[Test]
    public function createReturnsClientWithInjectedHttpLayer(): void
    {
        $captured = null;
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use (&$captured): ResponseInterface {
                $captured = $request;

                return new Response(200, [], 'Ok.');
            },
        );

        $config = new ClickHouseConfig(
            host: 'ch.internal',
            port: 8123,
            database: 'testdb',
            username: 'admin',
            password: 'secret',
            secure: true,
        );

        $factory = new ClickHouseClientFactory(
            config: $config,
            httpClient: $httpClient,
            requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            streamFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            uriFactory: new \GuzzleHttp\Psr7\HttpFactory(),
        );

        $client = $factory->create();

        $client->executeQuery('SELECT 1');

        $this->assertInstanceOf(RequestInterface::class, $captured);
        $this->assertSame('admin', $captured->getHeaderLine('X-ClickHouse-User'));
        $this->assertSame('secret', $captured->getHeaderLine('X-ClickHouse-Key'));
        $this->assertSame('testdb', $captured->getHeaderLine('X-ClickHouse-Database'));
        $this->assertStringStartsWith('https://ch.internal:8123', (string) $captured->getUri());
    }

    #[Test]
    public function createWithHttpScheme(): void
    {
        $captured = null;
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use (&$captured): ResponseInterface {
                $captured = $request;

                return new Response(200, [], 'Ok.');
            },
        );

        $factory = new ClickHouseClientFactory(
            config: new ClickHouseConfig(host: 'localhost', port: 8123),
            httpClient: $httpClient,
            requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            streamFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            uriFactory: new \GuzzleHttp\Psr7\HttpFactory(),
        );

        $factory->create()->executeQuery('SELECT 1');

        $this->assertInstanceOf(RequestInterface::class, $captured);
        $this->assertStringStartsWith('http://localhost:8123', (string) $captured->getUri());
    }
}
