<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use Cosmira\Soda\Analysis\Usage\AssignmentSyntax;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;

/**
 * Detects observable writes and reevaluation inside an expression subtree.
 *
 * @internal
 */
final readonly class StateWriteSearch
{
    /**
     * Parser ownership links delimit local bindings and ordinary fields.
     */
    private const PARENT = 'parent';

    /**
     * Report whether the subtree assigns, by value or by reference.
     */
    public static function hasStateWrite(Node $node): bool
    {
        return self::hasMatch($node, static fn (Node $inner): bool => AssignmentSyntax::isAssignment($inner));
    }

    /**
     * Skipped writes and reevaluated targets must have no observable accessors.
     */
    public static function hasStateWriteOrCall(Node $node): bool
    {
        return self::hasStateWrite($node) || self::hasMatch($node, static fn (Node $inner): bool => $inner instanceof Expr\CallLike
            || in_array($inner->getType(), ['Expr_PreInc', 'Expr_PostInc', 'Expr_PreDec', 'Expr_PostDec'], true)
            || ($inner instanceof Expr\PropertyFetch && ($inner !== $node || ! self::isPlainProperty($inner)))
            || ($inner instanceof Expr\ArrayDimFetch && ! self::isInertArrayTarget($inner)));
    }

    /**
     * Literal selectors and inert fallbacks still require a separate native array binding proof.
     */
    private static function isInertArrayTarget(Expr\ArrayDimFetch $target): bool
    {
        if (! $target->var instanceof Expr\Variable || ! is_string($target->var->name) || ! self::isLiteralArrayKey($target->dim)) {
            return false;
        }
        $assignment = $target->getAttribute(self::PARENT);

        return $assignment instanceof Expr\Assign && $assignment->expr instanceof Expr\BinaryOp\Coalesce
            && self::isPureValue($assignment->expr->right);
    }

    /**
     * Literal string and integer keys have neither conversion effects nor changing selectors.
     */
    private static function isLiteralArrayKey(?Node $key): bool
    {
        return $key instanceof Node\Scalar\String_ || $key instanceof Node\Scalar\Int_;
    }

    /**
     * Calls, magic reads, suspension and reference payloads stay outside this bounded proof.
     */
    private static function isPureValue(Node $value): bool
    {
        if ($value instanceof Expr\Variable) {
            return is_string($value->name);
        }
        if ($value instanceof Node\Scalar\String_ || $value instanceof Node\Scalar\Int_) {
            return true;
        }
        if ($value instanceof Expr\ConstFetch) {
            return in_array($value->name->toLowerString(), ['true', 'false', 'null'], true);
        }
        if (! $value instanceof Expr\Array_) {
            return false;
        }

        return self::isPureArray($value);
    }

    /**
     * Literal arrays must preserve both their keys and values without reference effects.
     */
    private static function isPureArray(Expr\Array_ $value): bool
    {
        foreach ($value->items as $item) {
            if ($item === null || $item->byRef || $item->unpack || ! self::isPureValue($item->value)) {
                return false;
            }
            if ($item->key !== null && ! self::isPureValue($item->key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Only an owned field with no hooks or possible magic setter proves an ordinary write.
     */
    private static function isPlainProperty(Expr\PropertyFetch $target): bool
    {
        $name = $target->name;
        if (! $name instanceof Identifier) {
            return false;
        }

        $class = self::propertyOwner($target);
        if ($class === null || ! self::hasPlainWrites($class)) {
            return false;
        }

        foreach ($class->getProperties() as $property) {
            if ($property->isStatic() || $property->isReadonly() || $property->hooks !== []) {
                continue;
            }

            foreach ($property->props as $item) {
                if ($item->name->toString() === $name->toString()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Rebound closures and unrelated receivers do not inherit the declaring method's property proof.
     */
    private static function propertyOwner(Expr\PropertyFetch $target): ?Class_
    {
        if (! $target->var instanceof Expr\Variable || $target->var->name !== 'this') {
            return null;
        }

        $scope = $target->getAttribute(self::PARENT);
        while ($scope instanceof Node && ! $scope instanceof FunctionLike) {
            $scope = $scope->getAttribute(self::PARENT);
        }

        $class = $scope instanceof Node ? $scope->getAttribute(self::PARENT) : null;
        if (! $class instanceof Class_) {
            return null;
        }

        foreach ($class->getMethods() as $method) {
            if ($method === $scope) {
                return $method->isStatic() ? null : $class;
            }
        }

        return null;
    }

    /**
     * Parent classes, traits and subclasses can introduce accessors outside this declaration.
     */
    private static function hasPlainWrites(Class_ $class): bool
    {
        if (! $class->isFinal() || $class->isReadonly() || $class->extends !== null || $class->getTraitUses() !== []) {
            return false;
        }

        foreach (['__get', '__set', '__isset'] as $method) {
            if ($class->getMethod($method) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Keep the original subtree search, including nested callable syntax.
     */
    private static function hasMatch(Node $node, callable $predicate): bool
    {
        if ($predicate($node)) {
            return true;
        }
        $properties = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $name) {
            $value = $properties[$name];
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node && self::hasMatch($child, $predicate)) {
                    return true;
                }
            }
        }

        return false;
    }
}
