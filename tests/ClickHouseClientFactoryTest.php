<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ClickHouseClientFactory::class)]
final class ClickHouseClientFactoryTest
{
    public function createReturnsClientWithInjectedHttpLayer(): void
    {
        $captured = null;
        $httpClient = (new FakePsrHttpClient())->withSendRequestCallback(
            static function (RequestInterface $request) use (&$captured) {
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

        Assert::instanceOf($captured, RequestInterface::class);
        Assert::same($captured->getHeaderLine('X-ClickHouse-User'), 'admin');
        Assert::same($captured->getHeaderLine('X-ClickHouse-Key'), 'secret');
        Assert::same($captured->getHeaderLine('X-ClickHouse-Database'), 'testdb');
        Assert::string((string) $captured->getUri())->startsWith('https://ch.internal:8123');
    }

    public function createWithHttpScheme(): void
    {
        $captured = null;
        $httpClient = (new FakePsrHttpClient())->withSendRequestCallback(
            static function (RequestInterface $request) use (&$captured) {
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

        Assert::instanceOf($captured, RequestInterface::class);
        Assert::string((string) $captured->getUri())->startsWith('http://localhost:8123');
    }

    public function createUsesInjectedPsrFactories(): void
    {
        $calls = new \stdClass();
        $calls->requestFactory = false;
        $calls->streamFactory = false;
        $calls->uriFactory = false;
        $inner = new \GuzzleHttp\Psr7\HttpFactory();
        $httpClient = (new FakePsrHttpClient())->withSendRequestCallback(
            static function () {
                return new Response(200, [], 'Ok.');
            },
        );

        $requestFactory = new readonly class ($calls, $inner) implements \Psr\Http\Message\RequestFactoryInterface {
            public function __construct(
                private \stdClass $calls,
                private \GuzzleHttp\Psr7\HttpFactory $inner,
            ) {}

            #[\Override]
            public function createRequest(string $method, $uri): RequestInterface
            {
                $this->calls->requestFactory = true;

                return $this->inner->createRequest($method, $uri);
            }
        };
        $streamFactory = new readonly class ($calls, $inner) implements \Psr\Http\Message\StreamFactoryInterface {
            public function __construct(
                private \stdClass $calls,
                private \GuzzleHttp\Psr7\HttpFactory $inner,
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
        $uriFactory = new readonly class ($calls, $inner) implements \Psr\Http\Message\UriFactoryInterface {
            public function __construct(
                private \stdClass $calls,
                private \GuzzleHttp\Psr7\HttpFactory $inner,
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

        Assert::true($calls->requestFactory);
        Assert::true($calls->streamFactory);
        Assert::true($calls->uriFactory);
    }
}
