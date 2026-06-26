<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use Rasuvaeff\ClickHouseToolkit\ClickHouseFilterVisitor;
use Rasuvaeff\ClickHouseToolkit\ClickHouseRawFilter;
use Yiisoft\Data\Reader\Filter\All;
use Yiisoft\Data\Reader\Filter\AndX;
use Yiisoft\Data\Reader\Filter\Between;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Filter\EqualsNull;
use Yiisoft\Data\Reader\Filter\GreaterThan;
use Yiisoft\Data\Reader\Filter\GreaterThanOrEqual;
use Yiisoft\Data\Reader\Filter\In;
use Yiisoft\Data\Reader\Filter\LessThan;
use Yiisoft\Data\Reader\Filter\LessThanOrEqual;
use Yiisoft\Data\Reader\Filter\Like;
use Yiisoft\Data\Reader\Filter\None;
use Yiisoft\Data\Reader\Filter\Not;
use Yiisoft\Data\Reader\Filter\OrX;
use Yiisoft\Data\Reader\FilterInterface;

/**
 * @internal
 */
final class FakeClickHouseFilterVisitor implements ClickHouseFilterVisitor
{
    public function __construct(
        private readonly array $returnValue = [],
    ) {}

    #[\Override]
    public function visitAll(All $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitNone(None $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitRaw(ClickHouseRawFilter $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitEquals(Equals $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitGreaterThan(GreaterThan $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitGreaterThanOrEqual(GreaterThanOrEqual $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitLessThan(LessThan $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitLessThanOrEqual(LessThanOrEqual $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitEqualsNull(EqualsNull $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitLike(Like $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitIn(In $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitBetween(Between $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitNot(Not $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitAndX(AndX $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function visitOrX(OrX $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }

    #[\Override]
    public function dispatch(FilterInterface $filter, int &$index, bool $trusted): array
    {
        return $this->returnValue;
    }
}
