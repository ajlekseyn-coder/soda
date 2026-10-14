<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Representation;

use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Reduces native value syntax into bounded scalar facts for RepresentationFacts.
 *
 * The owner supplies methodCall, staticCall, functionCall, allocation and arguments
 * through RepresentationCallSyntax. All methods share the owner's callable budget;
 * this Concern retains neither parser nodes nor mutable state.
 *
 * @internal
 */
trait RepresentationValueSyntax
{
    /**
     * Canonical native name of PHP's null literal value.
     */
    private const string NATIVE_NULL = 'null';

    /**
     * Normalize native contracts after name resolution; leave late static binding unknown.
     */
    private static function nativeType(Node|string|null $type, ?string $class): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof Node\NullableType) {
            return '?'.self::nativeType($type->type, $class);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            return implode($type instanceof Node\UnionType ? '|' : '&', array_map(fn (Node $part): string => self::nativeType($part, $class) ?? '', $type->types));
        }

        $name = strtolower((string) $type);

        return $name === 'self' ? $class : ltrim($name, '\\');
    }

    /**
     * Reduce leaf syntax to scalar facts before dispatching composite expressions.
     */
    private static function expression(Expr $node, ?string $class, int &$remaining): array
    {
        $line = $node->getStartLine();
        $remaining--;
        if ($remaining < 0) {
            return self::unknown('fact budget exceeded', $line);
        }

        return match ($node->getType()) {
            'Expr_Variable'                               => self::variable($node),
            'Scalar_String', 'Scalar_Int', 'Scalar_Float' => self::literal($node),
            'Expr_ConstFetch'                             => self::constant($node),
            'Expr_PropertyFetch'                          => self::property($node, $class, $remaining),
            default                                       => self::composite($node, $class, $remaining),
        };
    }

    /**
     * Preserve scalar literal values and canonical primitive types.
     */
    private static function literal(Node\Scalar\String_|Node\Scalar\Int_|Node\Scalar\Float_ $node): array
    {
        $type = match ($node->getType()) {
            'Scalar_String' => 'string', 'Scalar_Int' => 'int', default => 'float'
        };

        return ['op' => 'literal', 'value' => $node->value, 'type' => $type, 'line' => $node->getStartLine()];
    }

    /**
     * Dynamic variable names cannot establish a stable scalar binding.
     */
    private static function variable(Expr\Variable $node): array
    {
        return is_string($node->name)
            ? ['op' => 'variable', 'name' => $node->name, 'line' => $node->getStartLine()]
            : self::unknown('dynamic variable', $node->getStartLine());
    }

    /**
     * Only PHP's built-in constants have intrinsic literal contracts.
     */
    private static function constant(Expr\ConstFetch $node): array
    {
        $name = strtolower($node->name->toString());
        if (! in_array($name, [self::NATIVE_NULL, 'true', 'false'], true)) {
            return self::unknown($node->getType(), $node->getStartLine());
        }

        return ['op' => 'literal', 'value' => $name === self::NATIVE_NULL ? null : $name === 'true', 'type' => $name === self::NATIVE_NULL ? self::NATIVE_NULL : 'bool', 'line' => $node->getStartLine()];
    }

    /**
     * Preserve only a statically named instance field access.
     */
    private static function property(Expr\PropertyFetch $node, ?string $class, int &$remaining): array
    {
        if (! $node->name instanceof Node\Identifier) {
            return self::unknown('dynamic property', $node->getStartLine());
        }

        return ['op' => 'property', 'receiver' => self::expression($node->var, $class, $remaining), 'name' => $node->name->toString(), 'line' => $node->getStartLine()];
    }

    /**
     * Distinguish calls and allocation from value-producing operations.
     */
    private static function composite(Expr $node, ?string $class, int &$remaining): array
    {
        return match ($node->getType()) {
            'Expr_MethodCall' => self::methodCall($node, $class, $remaining),
            'Expr_StaticCall' => self::staticCall($node, $class, $remaining),
            'Expr_FuncCall'   => self::functionCall($node, $class, $remaining),
            'Expr_New'        => self::allocation($node, $class, $remaining),
            default           => self::operation($node, $class, $remaining),
        };
    }

    /**
     * Preserve operators and casts even when the eventual semantic result is unknown.
     */
    private static function operation(Expr $node, ?string $class, int &$remaining): array
    {
        $line = $node->getStartLine();
        if ($node instanceof Expr\BinaryOp) {
            return ['op' => 'binary', 'operator' => $node->getOperatorSigil(), 'left' => self::expression($node->left, $class, $remaining),
                'right'  => self::expression($node->right, $class, $remaining), 'line' => $line];
        }

        if ($node instanceof Expr\Cast) {
            $types = ['Expr_Cast_String' => 'string', 'Expr_Cast_Int' => 'int', 'Expr_Cast_Double' => 'float',
                'Expr_Cast_Bool'         => 'bool', 'Expr_Cast_Array' => 'array', 'Expr_Cast_Object' => 'object', 'Expr_Cast_Unset' => self::NATIVE_NULL];

            return ['op' => 'cast', 'type' => $types[$node->getType()] ?? 'unknown', 'value' => self::expression($node->expr, $class, $remaining), 'line' => $line];
        }

        if ($node instanceof Expr\Array_) {
            return self::arrayExpression($node, $class, $remaining);
        }

        return self::unknown($node->getType(), $line);
    }

    /**
     * Keep bounded array items and explicitly reject unpacking or references.
     */
    private static function arrayExpression(Expr\Array_ $node, ?string $class, int &$remaining): array
    {
        $items = [];
        foreach ($node->items as $item) {
            if ($item === null || $item->unpack || $item->byRef) {
                return self::unknown('unsupported array item', $node->getStartLine());
            }

            $items[] = ['key' => $item->key === null ? null : self::expression($item->key, $class, $remaining),
                'value'       => self::expression($item->value, $class, $remaining)];
            if ($remaining < 0) {
                break;
            }
        }

        return ['op' => 'array', 'items' => $items, 'line' => $node->getStartLine()];
    }

    /**
     * Store the precise syntax gap without holding the original expression.
     */
    private static function unknown(string $reason, int $line): array
    {
        return ['op' => 'unknown', 'reason' => $reason, 'line' => $line];
    }
}
