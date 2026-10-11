<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Usage;

use Cosmira\Soda\Rules\Complexity\AssignmentSyntax;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Extracts direct binding writes without treating array offsets or object state as rebinding.
 *
 * @internal
 */
final class ParameterWriteTargets
{
    /**
     * Select write targets from assignment, increment, unset, loop and catch syntax.
     *
     * @return list<Node>
     */
    public static function from(Node $node): array
    {
        if (AssignmentSyntax::isAssignment($node)) {
            return [$node->var];
        }

        if ($node instanceof Expr\PreInc || $node instanceof Expr\PostInc || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec) {
            return [$node->var];
        }

        return self::declarationBindings($node);
    }

    /**
     * Select bindings introduced by statements rather than assignment expressions.
     *
     * @return list<Node>
     */
    private static function declarationBindings(Node $node): array
    {
        return match (true) {
            $node instanceof Stmt\Unset_, $node instanceof Stmt\Global_ => $node->vars,
            $node instanceof Stmt\Static_                               => array_map(fn (Node\StaticVar $variable): Expr\Variable => $variable->var, $node->vars),
            $node instanceof Stmt\Foreach_                              => array_values(array_filter([$node->keyVar, $node->valueVar])),
            $node instanceof Stmt\Catch_                                => $node->var instanceof Expr\Variable ? [$node->var] : [],
            default                                                     => [],
        };
    }

    /**
     * Flatten destructuring targets and retain variable lines for diagnostics.
     *
     * @return array<string, int>
     */
    public static function bindings(Node $node): array
    {
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            return [$node->name => $node->getStartLine()];
        }

        if (! $node instanceof Expr\List_ && ! $node instanceof Expr\Array_) {
            return [];
        }

        $bindings = [];
        foreach ($node->items as $item) {
            if ($item !== null) {
                $bindings += self::bindings($item->value);
            }
        }

        return $bindings;
    }
}
