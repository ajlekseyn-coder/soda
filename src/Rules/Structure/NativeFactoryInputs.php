<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Structure;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Observes actual calls before local effects can replace an incoming native receiver.
 *
 * @internal
 */
trait NativeFactoryInputs
{
    /**
     * Only the first statement can observe the original input without a flow interpreter.
     */
    private static function incomingFactoryCalls(Stmt\ClassMethod $method): array
    {
        $observed = self::firstFactoryCall($method);
        if ($observed === null || ! self::areFactoryArgumentsSafe($observed['call']->args, $observed['receiver'])) {
            return [];
        }

        $receiver = self::nativeFactoryReceiver($method, $observed['receiver']);

        return $receiver === null ? [] : [['receiver' => $receiver, 'method' => $observed['method'], 'arity' => count($observed['call']->args)]];
    }

    /**
     * A result assignment may store the value without overwriting the receiver itself.
     *
     * @return array{call: Expr\MethodCall, receiver: string, method: string}|null
     */
    private static function firstFactoryCall(Stmt\ClassMethod $method): ?array
    {
        $first = $method->stmts[0] ?? null;
        $expression = $first instanceof Stmt\Return_ || $first instanceof Stmt\Expression ? $first->expr : null;
        $assignment = $expression instanceof Expr\Assign ? $expression : null;
        $call = $assignment?->expr ?? $expression;
        if (! $call instanceof Expr\MethodCall || $call->isFirstClassCallable()) {
            return null;
        }

        $isLiteral = $call->name instanceof Node\Identifier && $call->var instanceof Expr\Variable;
        if (! $isLiteral || ! is_string($call->var->name)) {
            return null;
        }

        if (! self::isFactoryResult($assignment, $call->var->name)) {
            return null;
        }

        return ['call' => $call, 'receiver' => $call->var->name, 'method' => strtolower($call->name->toString())];
    }

    /**
     * Complex assignment targets can evaluate code before the call.
     */
    private static function isFactoryResult(?Expr\Assign $assignment, string $receiver): bool
    {
        if ($assignment === null) {
            return true;
        }

        return $assignment->var instanceof Expr\Variable && is_string($assignment->var->name)
            && $assignment->var->name !== $receiver;
    }

    /**
     * References, variadics and relative type names do not identify an original object input.
     */
    private static function nativeFactoryReceiver(Stmt\ClassMethod $method, string $receiver): ?string
    {
        foreach ($method->params as $parameter) {
            $hasBinding = $parameter->byRef || $parameter->variadic;
            $isReceiver = $parameter->var instanceof Expr\Variable && $parameter->var->name === $receiver;
            if ($hasBinding || ! $isReceiver || ! $parameter->type instanceof Name) {
                continue;
            }

            $type = strtolower($parameter->type->toString());

            return in_array($type, ['self', 'static', 'parent'], true) ? null : $type;
        }

        return null;
    }

    /**
     * Argument evaluation must not expose or dynamically replace the local receiver binding.
     */
    private static function areFactoryArgumentsSafe(array $arguments, string $receiver): bool
    {
        foreach ($arguments as $argument) {
            if ($argument->name !== null || $argument->unpack || $argument->byRef) {
                return false;
            }
        }

        $unsafe = (new NodeFinder)->findFirst($arguments, static function (Node $node) use ($receiver): bool {
            if ($node instanceof Expr\Variable) {
                return ! is_string($node->name) || $node->name === $receiver;
            }

            return $node instanceof Expr\FuncCall || $node instanceof Expr\Eval_ || $node instanceof Expr\Include_
                || $node instanceof Expr\Assign || $node instanceof Expr\AssignRef || $node instanceof Node\FunctionLike;
        });

        return $unsafe === null;
    }
}
