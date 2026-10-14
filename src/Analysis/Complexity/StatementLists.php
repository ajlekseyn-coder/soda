<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Collects control-structure statement lists without crossing closure boundaries.
 */
final class StatementLists
{
    /**
     * List every non-empty control-structure body nested inside the given subtree.
     *
     * @return list<list<Stmt>>
     */
    public function within(Node $root): array
    {
        $bodies = [];
        foreach ((new NodeFinder)->find($root, ControlBodies::isControlBody(...)) as $node) {
            $statements = ControlBodies::statements($node);
            if ($statements !== []) {
                $bodies[] = $statements;
            }
        }

        return $bodies;
    }

    /**
     * Report whether the last statement ends the enclosing path.
     *
     * @param list<Stmt> $statements
     */
    public static function doesEndWithExit(array $statements): bool
    {
        $last = $statements === [] ? null : $statements[count($statements) - 1];

        return $last !== null && self::isExit($last);
    }

    /**
     * Report whether the statement terminates return, throw, break, or continue paths.
     */
    public static function isExit(Node $node): bool
    {
        if ($node instanceof Stmt\Expression) {
            return $node->expr instanceof Expr\Throw_;
        }

        return $node instanceof Stmt\Return_
            || $node instanceof Stmt\Break_
            || $node instanceof Stmt\Continue_;
    }
}
