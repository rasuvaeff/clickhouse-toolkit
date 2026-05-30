<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use SimPod\ClickHouseClient\Client\Http\RequestFactory as ChRequestFactory;
use SimPod\ClickHouseClient\Client\PsrClickHouseClient;
use SimPod\ClickHouseClient\Param\ParamValueConverterRegistry;

/**
 * Builds a {@see PsrClickHouseClient} over any PSR-18 client and PSR-17
 * factories. When they are not supplied, they are auto-discovered via
 * php-http/discovery (install a PSR-18 client such as guzzlehttp/guzzle or
 * symfony/http-client + nyholm/psr7).
 *
 * The endpoint is built as an absolute URI from {@see ClickHouseConfig}; auth
 * and database travel in X-ClickHouse-* headers. Inject your own configured
 * PSR-18 client to control timeouts, retries or TLS options.
 *
 * @api
 */
final readonly class ClickHouseClientFactory
{
    public function __construct(
        private ClickHouseConfig $config,
        private ?ClientInterface $httpClient = null,
        private ?RequestFactoryInterface $requestFactory = null,
        private ?StreamFactoryInterface $streamFactory = null,
        private ?UriFactoryInterface $uriFactory = null,
    ) {}

    public function create(): PsrClickHouseClient
    {
        $httpClient = $this->httpClient ?? Psr18ClientDiscovery::find();
        $requestFactory = $this->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory = $this->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $uriFactory = $this->uriFactory ?? Psr17FactoryDiscovery::findUriFactory();

        $authenticatedClient = new AuthenticatingHttpClient($httpClient, [
            'X-ClickHouse-User' => $this->config->username,
            'X-ClickHouse-Key' => $this->config->password,
            'X-ClickHouse-Database' => $this->config->database,
        ]);

        return new PsrClickHouseClient(
            client: $authenticatedClient,
            requestFactory: new ChRequestFactory(
                paramValueConverterRegistry: new ParamValueConverterRegistry(),
                requestFactory: $requestFactory,
                streamFactory: $streamFactory,
                uriFactory: $uriFactory,
                uri: $this->config->baseUri(),
            ),
        );
    }
}
