<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
final class FakePsrHttpClient implements ClientInterface
{
    /** @var Closure|null */
    private ?Closure $sendRequestCallback = null;

    public function withSendRequestCallback(?Closure $callback): self
    {
        $this->sendRequestCallback = $callback;
        return $this;
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->sendRequestCallback !== null) {
            return ($this->sendRequestCallback)($request);
        }

        throw new \RuntimeException('sendRequest callback not set');
    }
}
