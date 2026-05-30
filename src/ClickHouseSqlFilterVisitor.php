<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use Yiisoft\Data\Reader\Filter\All;
use Yiisoft\Data\Reader\Filter\AndX;
use Yiisoft\Data\Reader\Filter\Between;
use Yiisoft\Data\Reader\Filter\Compare;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Filter\EqualsNull;
use Yiisoft\Data\Reader\Filter\GreaterThan;
use Yiisoft\Data\Reader\Filter\GreaterThanOrEqual;
use Yiisoft\Data\Reader\Filter\In;
use Yiisoft\Data\Reader\Filter\LessThan;
use Yiisoft\Data\Reader\Filter\LessThanOrEqual;
use Yiisoft\Data\Reader\Filter\Like;
use Yiisoft\Data\Reader\Filter\LikeMode;
use Yiisoft\Data\Reader\Filter\None;
use Yiisoft\Data\Reader\Filter\Not;
use Yiisoft\Data\Reader\Filter\OrX;
use Yiisoft\Data\Reader\FilterInterface;

/**
 * Default SQL-generating visitor. Translates each supported filter type into
 * a parameterized ClickHouse WHERE fragment. Field access is controlled via
 * an allow-list; trusted (mandatory) fields bypass it but are still validated.
 *
 * @api
 */
final readonly class ClickHouseSqlFilterVisitor implements ClickHouseFilterVisitor
{
    /**
     * @param list<string> $allowedFields
     * @param array<string, string> $fieldTypes
     * @param ?\DateTimeZone $serverTimezone When set, DateTimeInterface values are
     *     converted to this timezone before formatting. Null = use the object's own timezone.
     */
    public function __construct(
        private array $allowedFields,
        private array $fieldTypes = [],
        private ?\DateTimeZone $serverTimezone = null,
    ) {}

    #[\Override]
    public function visitAll(All $filter, int &$index, bool $trusted): array
    {
        return ['', []];
    }

    #[\Override]
    public function visitNone(None $filter, int &$index, bool $trusted): array
    {
        return ['0', []];
    }

    #[\Override]
    public function visitRaw(ClickHouseRawFilter $filter, int &$index, bool $trusted): array
    {
        return [$filter->sql, $filter->params];
    }

    #[\Override]
    public function visitEquals(Equals $filter, int &$index, bool $trusted): array
    {
        return $this->buildCompare($filter, '=', $index, $trusted);
    }

    #[\Override]
    public function visitGreaterThan(GreaterThan $filter, int &$index, bool $trusted): array
    {
        return $this->buildCompare($filter, '>', $index, $trusted);
    }

    #[\Override]
    public function visitGreaterThanOrEqual(GreaterThanOrEqual $filter, int &$index, bool $trusted): array
    {
        return $this->buildCompare($filter, '>=', $index, $trusted);
    }

    #[\Override]
    public function visitLessThan(LessThan $filter, int &$index, bool $trusted): array
    {
        return $this->buildCompare($filter, '<', $index, $trusted);
    }

    #[\Override]
    public function visitLessThanOrEqual(LessThanOrEqual $filter, int &$index, bool $trusted): array
    {
        return $this->buildCompare($filter, '<=', $index, $trusted);
    }

    #[\Override]
    public function visitEqualsNull(EqualsNull $filter, int &$index, bool $trusted): array
    {
        if (!$this->fieldAllowed($filter->field, $trusted)) {
            return ['', []];
        }

        return [sprintf('%s IS NULL', $filter->field), []];
    }

    #[\Override]
    public function visitLike(Like $filter, int &$index, bool $trusted): array
    {
        if (!$this->fieldAllowed($filter->field, $trusted)) {
            return ['', []];
        }

        $key = 'p' . $index++;
        $operator = $filter->caseSensitive === true ? 'LIKE' : 'ILIKE';
        $escaped = addcslashes((string) $filter->value, '%_\\');
        $pattern = match ($filter->mode) {
            LikeMode::StartsWith => $escaped . '%',
            LikeMode::EndsWith => '%' . $escaped,
            LikeMode::Contains => '%' . $escaped . '%',
        };

        return [
            sprintf('%s %s {%s:%s}', $filter->field, $operator, $key, ClickHouseDataType::String),
            [$key => $pattern],
        ];
    }

    #[\Override]
    public function visitIn(In $filter, int &$index, bool $trusted): array
    {
        if (!$this->fieldAllowed($filter->field, $trusted)) {
            return ['', []];
        }

        if ($filter->values === []) {
            return ['0', []];
        }

        $type = $this->fieldTypes[$filter->field] ?? ClickHouseDataType::String;
        $placeholders = [];
        $params = [];

        /** @var list<bool|float|int|string|\Stringable> $values */
        $values = $filter->values;
        foreach ($values as $value) {
            $key = 'p' . $index++;
            $placeholders[] = sprintf('{%s:%s}', $key, $type);
            $params[$key] = $this->normalize($value);
        }

        return [
            sprintf('%s IN (%s)', $filter->field, implode(', ', $placeholders)),
            $params,
        ];
    }

    #[\Override]
    public function visitBetween(Between $filter, int &$index, bool $trusted): array
    {
        if (!$this->fieldAllowed($filter->field, $trusted)) {
            return ['', []];
        }

        $type = $this->fieldTypes[$filter->field] ?? ClickHouseDataType::String;
        $minKey = 'p' . $index++;
        $maxKey = 'p' . $index++;

        return [
            sprintf('%s BETWEEN {%s:%s} AND {%s:%s}', $filter->field, $minKey, $type, $maxKey, $type),
            [$minKey => $this->normalize($filter->minValue), $maxKey => $this->normalize($filter->maxValue)],
        ];
    }

    #[\Override]
    public function visitNot(Not $filter, int &$index, bool $trusted): array
    {
        [$inner, $params] = $this->dispatch($filter->filter, $index, $trusted);

        if ($inner === '') {
            return ['', []];
        }

        return ['NOT (' . $inner . ')', $params];
    }

    #[\Override]
    public function visitAndX(AndX $filter, int &$index, bool $trusted): array
    {
        return $this->buildComposite($filter->filters, 'AND', $index, $trusted);
    }

    #[\Override]
    public function visitOrX(OrX $filter, int &$index, bool $trusted): array
    {
        return $this->buildComposite($filter->filters, 'OR', $index, $trusted);
    }

    /**
     * Dispatches a filter to the correct visit* method.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    #[\Override]
    public function dispatch(FilterInterface $filter, int &$index, bool $trusted): array
    {
        return match (true) {
            $filter instanceof All => $this->visitAll($filter, $index, $trusted),
            $filter instanceof None => $this->visitNone($filter, $index, $trusted),
            $filter instanceof ClickHouseRawFilter => $this->visitRaw($filter, $index, $trusted),
            $filter instanceof EqualsNull => $this->visitEqualsNull($filter, $index, $trusted),
            $filter instanceof Equals => $this->visitEquals($filter, $index, $trusted),
            $filter instanceof GreaterThan => $this->visitGreaterThan($filter, $index, $trusted),
            $filter instanceof GreaterThanOrEqual => $this->visitGreaterThanOrEqual($filter, $index, $trusted),
            $filter instanceof LessThan => $this->visitLessThan($filter, $index, $trusted),
            $filter instanceof LessThanOrEqual => $this->visitLessThanOrEqual($filter, $index, $trusted),
            $filter instanceof Like => $this->visitLike($filter, $index, $trusted),
            $filter instanceof In => $this->visitIn($filter, $index, $trusted),
            $filter instanceof Between => $this->visitBetween($filter, $index, $trusted),
            $filter instanceof Not => $this->visitNot($filter, $index, $trusted),
            $filter instanceof AndX => $this->visitAndX($filter, $index, $trusted),
            $filter instanceof OrX => $this->visitOrX($filter, $index, $trusted),
            default => ['', []],
        };
    }

    private function fieldAllowed(string $field, bool $trusted): bool
    {
        if ($trusted) {
            Identifier::assert($field);

            return true;
        }

        return in_array(needle: $field, haystack: $this->allowedFields, strict: true);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildCompare(Compare $filter, string $operator, int &$index, bool $trusted): array
    {
        if (!$this->fieldAllowed($filter->field, $trusted)) {
            return ['', []];
        }

        $key = 'p' . $index++;
        $type = $this->fieldTypes[$filter->field] ?? ClickHouseDataType::String;

        return [
            sprintf('%s %s {%s:%s}', $filter->field, $operator, $key, $type),
            [$key => $this->normalize($filter->value)],
        ];
    }

    /**
     * @param iterable<FilterInterface> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildComposite(iterable $filters, string $operator, int &$index, bool $trusted): array
    {
        $parts = [];
        $params = [];

        foreach ($filters as $sub) {
            [$subSql, $subParams] = $this->dispatch($sub, $index, $trusted);
            if ($subSql !== '') {
                $parts[] = $subSql;
                $params = array_merge($params, $subParams);
            }
        }

        if ($parts === []) {
            return ['', []];
        }

        return ['(' . implode(separator: ' ' . $operator . ' ', array: $parts) . ')', $params];
    }

    private function normalize(bool|\DateTimeInterface|float|int|string|\Stringable $value): string|int|float
    {
        if ($value instanceof \DateTime) {
            if ($this->serverTimezone !== null) {
                $value = (clone $value)->setTimezone($this->serverTimezone);
            }

            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof \DateTimeImmutable) {
            if ($this->serverTimezone !== null) {
                $value = $value->setTimezone($this->serverTimezone);
            }

            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return (int) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return $value;
    }
}
