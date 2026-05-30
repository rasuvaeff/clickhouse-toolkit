<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Examples;

/**
 * Typed value object for a row of the `events` table used in the reader example.
 */
final readonly class EventRow
{
    public function __construct(
        public int $id,
        public string $type,
        public int $userId,
        public string $payload,
        public \DateTimeImmutable $createdAt,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            type: (string) $row['type'],
            userId: (int) $row['user_id'],
            payload: (string) $row['payload'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
