<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * Recognizes visible owner updates separately from hidden writes in return expressions.
 *
 * @internal
 */
final class ReturnAssignmentSyntax
{
    /**
     * Direct owner writes have a named target and no further evaluated assignment in the value.
     */
    public static function isOwnerWrite(Expr $expression): bool
    {
        return $expression instanceof Expr\Assign && self::isOwnerProperty($expression->var)
            && ! AssignmentSyntax::hasAssignment($expression->expr);
    }

    /**
     * Another named class's static storage is not state of the returning owner.
     */
    private static function isOwnerProperty(Expr $target): bool
    {
        $isOwnerStatic = $target instanceof Expr\StaticPropertyFetch && $target->class instanceof Name
            && $target->class->isSpecialClassName();
        $isOwnerInstance = $target instanceof Expr\PropertyFetch;

        return ($isOwnerStatic || $isOwnerInstance) && self::isStableTarget($target);
    }

    /**
     * Callback branches may update named state; arbitrary assignment-bearing expressions may not.
     */
    public static function isOwnerStateExpression(Expr $expression): bool
    {
        if (! AssignmentSyntax::hasAssignment($expression) || self::isOwnerWrite($expression)) {
            return true;
        }

        return match (true) {
            $expression instanceof Expr\Match_  => self::isOwnerStateMatch($expression),
            $expression instanceof Expr\Ternary => self::isOwnerStateTernary($expression),
            default                             => false,
        };
    }

    /**
     * Match selectors and arm conditions cannot contain hidden writes.
     */
    private static function isOwnerStateMatch(Expr\Match_ $expression): bool
    {
        if (AssignmentSyntax::hasAssignment($expression->cond)) {
            return false;
        }

        foreach ($expression->arms as $arm) {
            foreach ($arm->conds ?? [] as $condition) {
                if (AssignmentSyntax::hasAssignment($condition)) {
                    return false;
                }
            }

            if (! self::isOwnerStateExpression($arm->body)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ternary decisions may select state updates without hiding a write in their condition.
     */
    private static function isOwnerStateTernary(Expr\Ternary $expression): bool
    {
        if (AssignmentSyntax::hasAssignment($expression->cond)) {
            return false;
        }

        $branches = array_filter([$expression->if, $expression->else]);
        foreach ($branches as $branch) {
            if (! self::isOwnerStateExpression($branch)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dynamic access and other receivers can hide work while locating the cache.
     */
    public static function isStableTarget(Expr $target): bool
    {
        if ($target instanceof Expr\Variable) {
            return is_string($target->name);
        }

        if ($target instanceof Expr\PropertyFetch) {
            return $target->name instanceof Identifier && $target->var instanceof Expr\Variable && $target->var->name === 'this';
        }

        return $target instanceof Expr\StaticPropertyFetch && $target->class instanceof Name && $target->name instanceof Node\VarLikeIdentifier;
    }
}
