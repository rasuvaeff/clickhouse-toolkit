<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Context\Context;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Format\Format;
use SimPod\ClickHouseClient\Output\Output;
use SimPod\ClickHouseClient\Schema\Table;

/**
 * Adds cooperative context checks and ClickHouse's native execution budget to
 * every operation. The underlying client remains responsible for HTTP I/O.
 *
 * @api
 */
final readonly class ContextClickHouseClient implements ClickHouseClient
{
    public function __construct(
        private ClickHouseClient $client,
        private Context $context,
    ) {}

    #[\Override]
    /** @param array<string, float|int|string> $settings */
    public function executeQuery(string $query, array $settings = []): void
    {
        $this->before();
        $this->client->executeQuery($query, $this->settings($settings));
        $this->after();
    }

    #[\Override]
    /** @param array<string, float|int|string> $settings */
    public function executeQueryWithParams(string $query, array $params, array $settings = []): void
    {
        $this->before();
        $this->client->executeQueryWithParams($query, $params, $this->settings($settings));
        $this->after();
    }

    #[\Override]
    /** @param array<string, float|int|string> $settings */
    public function select(string $query, Format $outputFormat, array $settings = []): Output
    {
        $this->before();
        $result = $this->client->select($query, $outputFormat, $this->settings($settings));
        $this->after();

        return $result;
    }

    #[\Override]
    /** @param array<string, float|int|string> $settings */
    public function selectWithParams(string $query, array $params, Format $outputFormat, array $settings = []): Output
    {
        $this->before();
        $result = $this->client->selectWithParams($query, $params, $outputFormat, $this->settings($settings));
        $this->after();

        return $result;
    }

    #[\Override]
    /** @param array<string, float|int|string> $settings */
    public function insert(Table|string $table, array $values, ?array $columns = null, array $settings = []): void
    {
        $this->before();
        $this->client->insert($table, $values, $columns, $this->settings($settings));
        $this->after();
    }

    #[\Override]
    /** @param array<string, float|int|string> $settings */
    public function insertWithFormat(Table|string $table, Format $inputFormat, string $data, array $settings = []): void
    {
        $this->before();
        $this->client->insertWithFormat($table, $inputFormat, $data, $this->settings($settings));
        $this->after();
    }

    #[\Override]
    /** @param array<string, float|int|string> $settings */
    public function insertPayload(
        Table|string $table,
        Format $inputFormat,
        StreamInterface $payload,
        array $columns = [],
        array $settings = [],
    ): void {
        $this->before();
        $this->client->insertPayload($table, $inputFormat, $payload, $columns, $this->settings($settings));
        $this->after();
    }

    /**
     * @param array<string, float|int|string> $settings
     * @return array<string, float|int|string>
     */
    private function settings(array $settings): array
    {
        $remaining = $this->context->remainingMs();
        if ($remaining !== null) {
            $budgetSeconds = max(1, (int) ceil($remaining / 1000.0));
            $configured = $settings['max_execution_time'] ?? null;
            $settings['max_execution_time'] = is_numeric($configured)
                ? min($budgetSeconds, max(1, (int) $configured))
                : $budgetSeconds;
        }

        return $settings;
    }

    private function before(): void
    {
        $this->context->assertActive();
    }

    private function after(): void
    {
        $this->context->assertActive();
    }
}
