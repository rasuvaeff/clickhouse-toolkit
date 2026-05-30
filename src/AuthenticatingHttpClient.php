<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client decorator that adds a fixed set of headers (ClickHouse auth and
 * database) to every outgoing request. Keeps credentials out of the URL while
 * working with any PSR-18 client.
 *
 * @api
 */
final readonly class AuthenticatingHttpClient implements ClientInterface
{
    /**
     * @param array<string, string> $headers Headers added to every request.
     */
    public function __construct(
        private ClientInterface $client,
        private array $headers,
    ) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->client->sendRequest($request);
    }
}
