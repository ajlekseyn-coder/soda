<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Defines simple values and single-query predicates independently of boolean composition.
 */
final class ConditionValueGrammar
{
    /**
     * A single call or language predicate may consume simple values without nesting work.
     */
    public static function isPredicateValue(Node $node): bool
    {
        $subject = $node instanceof Expr ? self::unwrap($node) : $node;

        if ($subject instanceof Expr\Instanceof_) {
            return self::isPredicateValue($subject->expr) && self::isSimple($subject->class);
        }

        if ($subject instanceof Expr\BinaryOp\Coalesce) {
            return self::isPredicateValue($subject->left) && self::isPredicateValue($subject->right);
        }

        $isPredicate = $subject instanceof Expr\CallLike || in_array($subject->getType(), ['Expr_Isset', 'Expr_Empty'], true);
        if ($isPredicate) {
            return self::hasSimpleChildren($subject);
        }

        return self::isSimple($subject);
    }

    /**
     * Assignments have their own policy; casts and negation do not nest a computation.
     */
    public static function unwrap(Expr $node): Expr
    {
        $current = $node;
        while (true) {
            if ($current instanceof Expr\BooleanNot || $current instanceof Expr\Cast) {
                $current = $current->expr;

                continue;
            }

            if ($current instanceof Expr\Assign || $current instanceof Expr\AssignRef || $current instanceof Expr\AssignOp) {
                $current = $current->expr;

                continue;
            }

            return $current;
        }
    }

    /**
     * Accept data access and literal values; creating a callable does not execute its body.
     */
    private static function isSimple(Node $node): bool
    {
        if ($node instanceof Node\FunctionLike) {
            return true;
        }

        $isValue = $node instanceof Node\Scalar || in_array($node->getType(), [
            'Name', 'Name_FullyQualified', 'Name_Relative', 'Identifier', 'VarLikeIdentifier', 'Arg', 'VariadicPlaceholder',
            'Scalar_String', 'Scalar_Int', 'Scalar_Float', 'Scalar_InterpolatedString', 'InterpolatedStringPart',
            'Expr_Clone', 'Expr_Variable', 'Expr_ConstFetch', 'Expr_ClassConstFetch', 'Expr_PropertyFetch', 'Expr_NullsafePropertyFetch',
            'Expr_Cast_Int', 'Expr_Cast_String', 'Expr_Cast_Double', 'Expr_Cast_Bool', 'Expr_Cast_Array', 'Expr_Cast_Object',
            'Expr_Assign', 'Expr_AssignRef', 'Expr_BinaryOp_Coalesce', 'Expr_StaticPropertyFetch', 'Expr_ArrayDimFetch', 'Expr_Array', 'ArrayItem', 'Expr_UnaryMinus', 'Expr_UnaryPlus',
        ], true);

        return $isValue && self::hasSimpleChildren($node);
    }

    /**
     * Inspect syntax children only, never parent attributes or captured callback bodies.
     */
    private static function hasSimpleChildren(Node $node): bool
    {
        $properties = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $name) {
            $value = $properties[$name];
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node && ! self::isSimple($child)) {
                    return false;
                }
            }
        }

        return true;
    }
}
