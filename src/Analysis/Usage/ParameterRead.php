<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Usage;

use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Recognizes direct parameter reads and snapshots of the owning variable table.
 */
final readonly class ParameterRead
{
    /**
     * Compare lexical variable names without treating dynamic access as a proved read.
     */
    public static function isVariable(Node $node, Node\Param $parameter): bool
    {
        return $node instanceof Expr\Variable && $parameter->var instanceof Expr\Variable && $node->name === $parameter->var->name;
    }

    /**
     * Includes and symbol-table reads can consume inputs without variable expressions.
     */
    public static function isScopeSnapshot(Expr $node, Node\Param $parameter): bool
    {
        if (self::scope($node) !== self::scope($parameter)) {
            return false;
        }

        return $node instanceof Expr\Include_ || $node instanceof Expr\Eval_ || self::isCallSnapshot($node, $parameter);
    }

    /**
     * Only invoked native names can read the current callable's argument or variable table.
     */
    private static function isCallSnapshot(Expr $node, Node\Param $parameter): bool
    {
        if (! $node instanceof Expr\FuncCall || ! $node->name instanceof Node\Name || $node->isFirstClassCallable()) {
            return false;
        }

        return match (strtolower($node->name->toString())) {
            'get_defined_vars', 'func_get_args' => true,
            'func_get_arg'                      => self::doesReadArgument($node, $parameter),
            'compact'                           => self::doesCompactRead($node, $parameter),
            default                             => false,
        };
    }

    /**
     * A literal argument index reads only that parameter; a computed index may select any input.
     */
    private static function doesReadArgument(Expr\FuncCall $call, Node\Param $parameter): bool
    {
        $position = $call->getArgs()[0]->value ?? null;
        $scope = self::scope($parameter);
        if (! $position instanceof Node\Scalar\Int_ || ! $scope instanceof Node\FunctionLike) {
            return true;
        }

        return ($scope->getParams()[$position->value] ?? null) === $parameter;
    }

    /**
     * Each compact argument names locals directly or through a nested array.
     */
    private static function doesCompactRead(Expr\FuncCall $call, Node\Param $parameter): bool
    {
        foreach ($call->getArgs() as $argument) {
            if (self::canNameParameter($argument->value, $parameter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compact accepts nested name arrays; a computed name may select any local input.
     */
    private static function canNameParameter(Expr $expression, Node\Param $parameter): bool
    {
        if ($expression instanceof Node\Scalar\String_) {
            return $parameter->var instanceof Expr\Variable && $expression->value === $parameter->var->name;
        }

        if ($expression instanceof Expr\Array_) {
            foreach ($expression->items as $item) {
                if ($item !== null && self::canNameParameter($item->value, $parameter)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Nested callables have independent local variable tables.
     */
    private static function scope(Node $node): ?Node
    {
        $current = $node;
        do {
            $current = $current->getAttribute('parent');
        } while ($current instanceof Node && ! $current instanceof Node\FunctionLike);

        return $current instanceof Node ? $current : null;
    }
}
