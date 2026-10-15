<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\NodeFinder;

/**
 * Detects state changes and calls nested inside an expression subtree.
 *
 * @internal
 */
final readonly class StateWriteSearch
{
    /**
     * Report whether the subtree assigns, by value or by reference.
     */
    public static function hasStateWrite(Node $node): bool
    {
        return self::hasMatch($node, static fn (Node $inner): bool => $inner instanceof Expr\Assign
            || $inner instanceof Expr\AssignRef
            || $inner instanceof Expr\AssignOp);
    }

    /**
     * Calls and increments also make reevaluation of an assignment target observable.
     */
    public static function hasStateWriteOrCall(Node $node): bool
    {
        return self::hasStateWrite($node) || self::hasMatch($node, static fn (Node $inner): bool => $inner instanceof CallLike
            || in_array($inner->getType(), ['Expr_PreInc', 'Expr_PostInc', 'Expr_PreDec', 'Expr_PostDec'], true));
    }

    /**
     * Search the subtree with the given membership question.
     *
     * @param \Closure(Node): bool $matches
     */
    private static function hasMatch(Node $node, \Closure $matches): bool
    {
        return (new NodeFinder)->findFirst([$node], $matches) instanceof Node;
    }
}
