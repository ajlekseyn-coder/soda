<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\NodeFinder;

/**
 * Detects assignments and calls nested inside an expression subtree.
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
     * Report whether the subtree assigns or invokes, so reevaluation is never pure.
     */
    public static function hasStateWriteOrCall(Node $node): bool
    {
        return self::hasMatch($node, static fn (Node $inner): bool => $inner instanceof Expr\Assign
            || $inner instanceof Expr\AssignRef
            || $inner instanceof Expr\AssignOp
            || $inner instanceof CallLike);
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
