<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ClickHouseConfig::class)]
final class ClickHouseConfigTest
{
    public function defaultsToLocalHttp(): void
    {
        Assert::same((new ClickHouseConfig())->baseUri(), 'http://127.0.0.1:8123');
    }

    public function buildsHttpsUriWhenSecure(): void
    {
        $config = new ClickHouseConfig(host: 'ch.example.com', port: 8443, secure: true);

        Assert::same($config->baseUri(), 'https://ch.example.com:8443');
    }

    public function bracketsIpv6Host(): void
    {
        $config = new ClickHouseConfig(host: '::1', port: 8123);

        Assert::same($config->baseUri(), 'http://[::1]:8123');
    }

    public function rejectsEmptyHost(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseConfig(host: '');
    }

    public function acceptsBoundaryPorts(): void
    {
        Assert::same((new ClickHouseConfig(port: 1))->baseUri(), 'http://127.0.0.1:1');
        Assert::same((new ClickHouseConfig(port: 65535))->baseUri(), 'http://127.0.0.1:65535');
    }

    public function rejectsOutOfRangePort(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseConfig(port: 70000);
    }
}
