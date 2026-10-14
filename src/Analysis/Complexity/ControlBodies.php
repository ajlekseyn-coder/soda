<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Complexity;

use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Knows which statement types hold plain statement lists and extracts those lists.
 */
final class ControlBodies
{
    /**
     * Accept the statement types whose bodies hold plain statement lists.
     */
    public static function isControlBody(Node $node): bool
    {
        return self::isBranch($node)
            || self::isLoop($node)
            || self::isHandler($node)
            || self::isSwitchPart($node);
    }

    /**
     * Return the statement list held by a known control body.
     *
     * @return list<Stmt>
     */
    public static function statements(Node $node): array
    {
        return match (true) {
            $node instanceof Stmt\If_,
            $node instanceof Stmt\ElseIf_,
            $node instanceof Stmt\Else_,
            $node instanceof Stmt\For_,
            $node instanceof Stmt\Foreach_,
            $node instanceof Stmt\While_,
            $node instanceof Stmt\Do_,
            $node instanceof Stmt\TryCatch,
            $node instanceof Stmt\Catch_,
            $node instanceof Stmt\Switch_,
            $node instanceof Stmt\Case_ => $node->stmts,
            default                     => [],
        };
    }

    /**
     * Conditional and alternative branches hold their own statement list.
     */
    private static function isBranch(Node $node): bool
    {
        return $node instanceof Stmt\If_
            || $node instanceof Stmt\ElseIf_
            || $node instanceof Stmt\Else_;
    }

    /**
     * Iteration structures repeat a body statement list.
     */
    private static function isLoop(Node $node): bool
    {
        return $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_;
    }

    /**
     * Exception handling routes execution into guarded or recovery blocks.
     */
    private static function isHandler(Node $node): bool
    {
        return $node instanceof Stmt\TryCatch
            || $node instanceof Stmt\Catch_;
    }

    /**
     * Switch dispatch and its cases hold directly executed statement lists.
     */
    private static function isSwitchPart(Node $node): bool
    {
        return $node instanceof Stmt\Switch_
            || $node instanceof Stmt\Case_;
    }
}
