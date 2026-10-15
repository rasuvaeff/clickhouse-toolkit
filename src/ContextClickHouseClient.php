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
        $settings = $this->prepare($settings);
        $this->client->executeQuery($query, $settings);
        $this->after();
    }

    #[\Override]
    /** @param array<string, float|int|string> $settings */
    public function executeQueryWithParams(string $query, array $params, array $settings = []): void
    {
        $settings = $this->prepare($settings);
        $this->client->executeQueryWithParams($query, $params, $settings);
        $this->after();
    }

    #[\Override]
    /** @param array<string, float|int|string> $settings */
    public function select(string $query, Format $outputFormat, array $settings = []): Output
    {
        $settings = $this->prepare($settings);
        $result = $this->client->select($query, $outputFormat, $settings);
        $this->after();

        return $result;
    }

    #[\Override]
    /** @param array<string, float|int|string> $settings */
    public function selectWithParams(string $query, array $params, Format $outputFormat, array $settings = []): Output
    {
        $settings = $this->prepare($settings);
        $result = $this->client->selectWithParams($query, $params, $outputFormat, $settings);
        $this->after();

        return $result;
    }

    #[\Override]
    /** @param array<string, float|int|string> $settings */
    public function insert(Table|string $table, array $values, ?array $columns = null, array $settings = []): void
    {
        $settings = $this->prepare($settings);
        $this->client->insert($table, $values, $columns, $settings);
        $this->after();
    }

    #[\Override]
    /** @param array<string, float|int|string> $settings */
    public function insertWithFormat(Table|string $table, Format $inputFormat, string $data, array $settings = []): void
    {
        $settings = $this->prepare($settings);
        $this->client->insertWithFormat($table, $inputFormat, $data, $settings);
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
        $settings = $this->prepare($settings);
        $this->client->insertPayload($table, $inputFormat, $payload, $columns, $settings);
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
            // ClickHouse accepts fractional seconds; remainingMs() is at
            // least 1 ms while active, so the budget never becomes 0
            // ("unlimited"). A configured 0 or negative value is unlimited too.
            $budgetSeconds = $remaining / 1000.0;
            $configured = $settings['max_execution_time'] ?? null;
            $stricter = is_numeric($configured) && (float) $configured > 0.0 && (float) $configured <= $budgetSeconds;
            $settings['max_execution_time'] = $stricter ? $configured : $budgetSeconds;
        }

        // Lets the server stop a read-only query once the HTTP client gives
        // up and closes the connection; ignored for writes.
        $settings['cancel_http_readonly_queries_on_client_close'] ??= 1;

        return $settings;
    }

    /**
     * The budget is read before the check: once assertActive() passes it
     * cannot be the 0.0 of an expired context, which ClickHouse would take
     * as an unlimited max_execution_time.
     *
     * @param array<string, float|int|string> $settings
     * @return array<string, float|int|string>
     */
    private function prepare(array $settings): array
    {
        $settings = $this->settings($settings);
        $this->context->assertActive();

        return $settings;
    }

    private function after(): void
    {
        $this->context->assertActive();
    }
}
