<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use SimPod\ClickHouseClient\Client\ClickHouseClient;

/**
 * Fluent builder for `CREATE TABLE` DDL. Builds the SQL (`build()`) or runs it
 * (`execute()`).
 *
 * The table name and column names are validated as identifiers; everything else
 * — column types, engine, and the ORDER BY / PARTITION BY / PRIMARY KEY
 * expressions — is emitted verbatim. DDL is developer-authored, so these are
 * trusted: never build them from untrusted input.
 *
 * @api
 */
final class ClickHouseTableBuilder
{
    private bool $ifNotExists = false;

    /** @var array<string, string> Column name => type/definition. */
    private array $columns = [];

    private ?string $engine = null;
    private ?string $orderBy = null;
    private ?string $partitionBy = null;
    private ?string $primaryKey = null;

    /**
     * @param string $table Target table, optionally db-qualified (`db.table`).
     *
     * @throws \InvalidArgumentException on a malformed table identifier.
     */
    public function __construct(
        private readonly ClickHouseClient $client,
        private readonly string $table,
    ) {
        Identifier::assert($this->table);
    }

    public static function create(ClickHouseClient $client, string $table): self
    {
        return new self($client, $table);
    }

    public function ifNotExists(bool $ifNotExists = true): self
    {
        $this->ifNotExists = $ifNotExists;

        return $this;
    }

    /**
     * @param string $name Column name (plain identifier — no dot).
     * @param string $type ClickHouse type/definition, e.g. "UInt64",
     *     "Nullable(String)", "DateTime DEFAULT now()". Emitted verbatim.
     *
     * @throws \InvalidArgumentException on a malformed column name.
     */
    public function column(string $name, string $type): self
    {
        Identifier::assertPlain($name);
        $this->columns[$name] = $type;

        return $this;
    }

    public function engine(string $engine): self
    {
        $this->engine = $engine;

        return $this;
    }

    public function orderBy(string $expression): self
    {
        $this->orderBy = $expression;

        return $this;
    }

    public function partitionBy(string $expression): self
    {
        $this->partitionBy = $expression;

        return $this;
    }

    public function primaryKey(string $expression): self
    {
        $this->primaryKey = $expression;

        return $this;
    }

    /**
     * @throws \InvalidArgumentException when columns or engine are missing.
     */
    public function build(): string
    {
        if ($this->columns === []) {
            throw new \InvalidArgumentException('At least one column is required.');
        }
        if ($this->engine === null) {
            throw new \InvalidArgumentException('Engine is required.');
        }

        $columns = [];
        foreach ($this->columns as $name => $type) {
            $columns[] = $name . ' ' . $type;
        }

        $sql = sprintf(
            'CREATE TABLE %s%s (%s) ENGINE = %s',
            $this->ifNotExists ? 'IF NOT EXISTS ' : '',
            $this->table,
            implode(', ', $columns),
            $this->engine,
        );

        if ($this->partitionBy !== null) {
            $sql .= ' PARTITION BY ' . $this->partitionBy;
        }
        if ($this->primaryKey !== null) {
            $sql .= ' PRIMARY KEY ' . $this->primaryKey;
        }
        if ($this->orderBy !== null) {
            $sql .= ' ORDER BY ' . $this->orderBy;
        }

        return $sql;
    }

    /**
     * @throws \InvalidArgumentException when columns or engine are missing.
     */
    public function execute(): void
    {
        $this->client->executeQuery($this->build());
    }
}
