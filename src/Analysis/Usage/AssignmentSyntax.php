<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Usage;

use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Recognizes PHP assignments without attributing nested callable bodies to an outer expression.
 *
 * @internal
 */
final class AssignmentSyntax
{
    /**
     * Include ordinary, reference and compound assignments, excluding increments and decrements.
     *
     * @phpstan-assert-if-true Expr\Assign|Expr\AssignRef|Expr\AssignOp $node
     */
    public static function isAssignment(Node $node): bool
    {
        return $node instanceof Expr\Assign || $node instanceof Expr\AssignRef || $node instanceof Expr\AssignOp;
    }

    /**
     * Find assignments evaluated by an expression without entering deferred callable bodies.
     */
    public static function hasAssignment(Node $node): bool
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return false;
        }

        if (self::isAssignment($node)) {
            return true;
        }

        $properties = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $key) {
            $value = $properties[$key];
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node && self::hasAssignment($child)) {
                    return true;
                }
            }
        }

        return false;
    }
}
