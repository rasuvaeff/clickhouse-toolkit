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

    #[Test]
    public function createUsesInjectedPsrFactories(): void
    {
        $calls = new \stdClass();
        $calls->requestFactory = false;
        $calls->streamFactory = false;
        $calls->uriFactory = false;
        $inner = new \GuzzleHttp\Psr7\HttpFactory();
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn(new Response(200, [], 'Ok.'));

        $requestFactory = new class ($calls, $inner) implements \Psr\Http\Message\RequestFactoryInterface {
            public function __construct(
                private readonly \stdClass $calls,
                private readonly \GuzzleHttp\Psr7\HttpFactory $inner,
            ) {}

            #[\Override]
            public function createRequest(string $method, $uri): RequestInterface
            {
                $this->calls->requestFactory = true;

                return $this->inner->createRequest($method, $uri);
            }
        };
        $streamFactory = new class ($calls, $inner) implements \Psr\Http\Message\StreamFactoryInterface {
            public function __construct(
                private readonly \stdClass $calls,
                private readonly \GuzzleHttp\Psr7\HttpFactory $inner,
            ) {}

            #[\Override]
            public function createStream(string $content = ''): \Psr\Http\Message\StreamInterface
            {
                $this->calls->streamFactory = true;

                return $this->inner->createStream($content);
            }

            #[\Override]
            public function createStreamFromFile(string $filename, string $mode = 'r'): \Psr\Http\Message\StreamInterface
            {
                return $this->inner->createStreamFromFile($filename, $mode);
            }

            #[\Override]
            public function createStreamFromResource($resource): \Psr\Http\Message\StreamInterface
            {
                return $this->inner->createStreamFromResource($resource);
            }
        };
        $uriFactory = new class ($calls, $inner) implements \Psr\Http\Message\UriFactoryInterface {
            public function __construct(
                private readonly \stdClass $calls,
                private readonly \GuzzleHttp\Psr7\HttpFactory $inner,
            ) {}

            #[\Override]
            public function createUri(string $uri = ''): \Psr\Http\Message\UriInterface
            {
                $this->calls->uriFactory = true;

                return $this->inner->createUri($uri);
            }
        };

        $factory = new ClickHouseClientFactory(
            config: new ClickHouseConfig(host: 'localhost', port: 8123),
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            uriFactory: $uriFactory,
        );

        $factory->create()->executeQuery('SELECT 1');

        $this->assertTrue($calls->requestFactory);
        $this->assertTrue($calls->streamFactory);
        $this->assertTrue($calls->uriFactory);
    }
}
