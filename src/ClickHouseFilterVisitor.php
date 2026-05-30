<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

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
 * Visitor that dispatches a {@see FilterInterface} to the
 * appropriate SQL-generation method. Implementations produce a `[sql, params]`
 * tuple (or `['', []]` to signal "no condition").
 *
 * The visitor does NOT see every possible FilterInterface — only the types the
 * toolkit knows about. Unknown filters are silently dropped (empty result).
 *
 * @api
 */
interface ClickHouseFilterVisitor
{
    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitAll(All $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitNone(None $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitRaw(ClickHouseRawFilter $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitEquals(Equals $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitGreaterThan(GreaterThan $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitGreaterThanOrEqual(GreaterThanOrEqual $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitLessThan(LessThan $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitLessThanOrEqual(LessThanOrEqual $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitEqualsNull(EqualsNull $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitLike(Like $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitIn(In $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitBetween(Between $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitNot(Not $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitAndX(AndX $filter, int &$index, bool $trusted): array;

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visitOrX(OrX $filter, int &$index, bool $trusted): array;

    /**
     * Routes a filter to the correct visit* method. Returns `['', []]` for unknown types.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function dispatch(FilterInterface $filter, int &$index, bool $trusted): array;
}
