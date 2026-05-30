<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

/**
 * Connection settings for {@see ClickHouseClientFactory}.
 *
 * `$host` is a bare hostname or IP (no scheme, no port) — e.g. `clickhouse`,
 * `127.0.0.1`, or an IPv6 literal `::1` (bracketed automatically in the URI).
 *
 * Timeouts, retries and TLS options are concerns of the PSR-18 client — inject
 * a pre-configured one into {@see ClickHouseClientFactory} to control them.
 *
 * @api
 */
final readonly class ClickHouseConfig
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 8123,
        public string $database = 'default',
        public string $username = 'default',
        public string $password = '',
        public bool $secure = false,
    ) {
        if ($host === '') {
            throw new \InvalidArgumentException('Host must not be empty.');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(sprintf('Port must be between 1 and 65535, got %d.', $port));
        }
    }

    public function baseUri(): string
    {
        // Bracket IPv6 literals (contain ':') unless already bracketed.
        $host = str_contains($this->host, ':') && !str_starts_with($this->host, '[')
            ? '[' . $this->host . ']'
            : $this->host;

        return sprintf('%s://%s:%d', $this->secure ? 'https' : 'http', $host, $this->port);
    }
}
