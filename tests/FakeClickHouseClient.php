<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use Closure;
use Psr\Http\Message\StreamInterface;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Format\Format;
use SimPod\ClickHouseClient\Output\Output;
use SimPod\ClickHouseClient\Schema\Table;

/**
 * @internal
 */
final class FakeClickHouseClient implements ClickHouseClient
{
    /** @var Closure|null */
    private ?Closure $executeQueryCallback = null;

    /** @var Closure|null */
    private ?Closure $executeQueryWithParamsCallback = null;

    /** @var Closure|null */
    private ?Closure $selectCallback = null;

    /** @var Closure|null */
    private ?Closure $selectWithParamsCallback = null;

    /** @var Closure|null */
    private ?Closure $insertCallback = null;

    /** @var Closure|null */
    private ?Closure $insertWithFormatCallback = null;

    /** @var Closure|null */
    private ?Closure $insertPayloadCallback = null;

    public function withExecuteQueryCallback(?Closure $callback): self
    {
        $this->executeQueryCallback = $callback;
        return $this;
    }

    public function withExecuteQueryWithParamsCallback(?Closure $callback): self
    {
        $this->executeQueryWithParamsCallback = $callback;
        return $this;
    }

    public function withSelectCallback(?Closure $callback): self
    {
        $this->selectCallback = $callback;
        return $this;
    }

    public function withSelectWithParamsCallback(?Closure $callback): self
    {
        $this->selectWithParamsCallback = $callback;
        return $this;
    }

    public function withInsertCallback(?Closure $callback): self
    {
        $this->insertCallback = $callback;
        return $this;
    }

    public function withInsertWithFormatCallback(?Closure $callback): self
    {
        $this->insertWithFormatCallback = $callback;
        return $this;
    }

    public function withInsertPayloadCallback(?Closure $callback): self
    {
        $this->insertPayloadCallback = $callback;
        return $this;
    }

    #[\Override]
    public function executeQuery(string $query, array $settings = []): void
    {
        if ($this->executeQueryCallback !== null) {
            ($this->executeQueryCallback)($query, $settings);
        }
    }

    #[\Override]
    public function executeQueryWithParams(string $query, array $params, array $settings = []): void
    {
        if ($this->executeQueryWithParamsCallback !== null) {
            ($this->executeQueryWithParamsCallback)($query, $params, $settings);
        }
    }

    #[\Override]
    public function select(string $query, Format $outputFormat, array $settings = []): Output
    {
        if ($this->selectCallback !== null) {
            return ($this->selectCallback)($query, $outputFormat, $settings);
        }

        return new FakeOutput();
    }

    #[\Override]
    public function selectWithParams(string $query, array $params, Format $outputFormat, array $settings = []): Output
    {
        if ($this->selectWithParamsCallback !== null) {
            return ($this->selectWithParamsCallback)($query, $params, $outputFormat, $settings);
        }

        return new FakeOutput();
    }

    #[\Override]
    public function insert(Table|string $table, array $values, array|null $columns = null, array $settings = []): void
    {
        if ($this->insertCallback !== null) {
            ($this->insertCallback)($table, $values, $columns, $settings);
        }
    }

    #[\Override]
    public function insertWithFormat(
        Table|string $table,
        Format $inputFormat,
        string $data,
        array $settings = [],
    ): void
    {
        if ($this->insertWithFormatCallback !== null) {
            ($this->insertWithFormatCallback)($table, $inputFormat, $data, $settings);
        }
    }

    #[\Override]
    public function insertPayload(
        Table|string $table,
        Format $inputFormat,
        StreamInterface $payload,
        array $columns = [],
        array $settings = [],
    ): void
    {
        if ($this->insertPayloadCallback !== null) {
            ($this->insertPayloadCallback)($table, $inputFormat, $payload, $columns, $settings);
        }
    }
}
