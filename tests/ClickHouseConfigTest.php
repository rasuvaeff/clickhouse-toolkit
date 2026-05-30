<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;

#[CoversClass(ClickHouseConfig::class)]
final class ClickHouseConfigTest extends TestCase
{
    #[Test]
    public function defaultsToLocalHttp(): void
    {
        $this->assertSame('http://127.0.0.1:8123', (new ClickHouseConfig())->baseUri());
    }

    #[Test]
    public function buildsHttpsUriWhenSecure(): void
    {
        $config = new ClickHouseConfig(host: 'ch.example.com', port: 8443, secure: true);

        $this->assertSame('https://ch.example.com:8443', $config->baseUri());
    }

    #[Test]
    public function bracketsIpv6Host(): void
    {
        $config = new ClickHouseConfig(host: '::1', port: 8123);

        $this->assertSame('http://[::1]:8123', $config->baseUri());
    }

    #[Test]
    public function rejectsEmptyHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClickHouseConfig(host: '');
    }

    #[Test]
    public function rejectsOutOfRangePort(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClickHouseConfig(port: 70000);
    }
}
