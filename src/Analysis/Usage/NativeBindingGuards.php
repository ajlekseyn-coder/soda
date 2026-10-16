<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Usage;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Proves native guards and excludes opaque execution around observed receiver bindings.
 *
 * @internal
 */
trait NativeBindingGuards
{
    /**
     * Native existence checks short-circuit their fallback for every declared target.
     */
    private const EXISTENCE_FUNCTION = 'method_exists';

    /**
     * NameResolver records the namespaced function that may shadow a native fallback.
     */
    private const NAMESPACED_NAME = 'namespacedName';

    /**
     * A native method_exists guard cannot rebind its object argument.
     */
    private static function variableGuard(Node $variable, ?Expr\Assign $assignment, ?Node $invocation = null): ?string
    {
        $fallback = $invocation === null ? null : self::skippedExistenceFallback($variable, $invocation);
        if ($fallback !== null) {
            return $fallback;
        }

        if (self::isBindingWrite($variable, $assignment)) {
            return null;
        }

        $argument = $variable->getAttribute(self::PARENT_ATTRIBUTE);
        if (! $argument instanceof Node\Arg) {
            return '';
        }

        $call = $argument->getAttribute(self::PARENT_ATTRIBUTE);
        if (! $call instanceof Expr\FuncCall || ! $call->name instanceof Name || $call->name->toLowerString() !== self::EXISTENCE_FUNCTION) {
            return null;
        }

        return $call->name->getAttribute(self::NAMESPACED_NAME)?->toLowerString() ?? '';
    }

    /**
     * A declared target exists before the OR fallback can receive or rebind its selector.
     */
    private static function skippedExistenceFallback(Node $reference, Node $invocation): ?string
    {
        if (! $invocation instanceof Expr\MethodCall) {
            return null;
        }
        $child = $reference;
        for ($owner = $reference->getAttribute(self::PARENT_ATTRIBUTE); $owner instanceof Node; $owner = $owner->getAttribute(self::PARENT_ATTRIBUTE)) {
            if ($owner instanceof Expr\BinaryOp\BooleanOr && $owner->right === $child) {
                $guard = self::matchingExistenceFallback($owner, $invocation);
                if ($guard !== null) {
                    return $guard->name->getAttribute(self::NAMESPACED_NAME)?->toLowerString() ?? '';
                }
            }
            $child = $owner;
        }

        return null;
    }

    /**
     * The observed call must belong to the true body of this exact native OR condition.
     */
    private static function matchingExistenceFallback(Expr\BinaryOp\BooleanOr $expression, Expr\MethodCall $invocation): ?Expr\FuncCall
    {
        $condition = $expression->getAttribute(self::PARENT_ATTRIBUTE);
        if (! $condition instanceof Stmt\If_ || $condition->cond !== $expression || ! self::isInsideCondition($invocation, $condition)) {
            return null;
        }
        $guard = $expression->left;

        return $guard instanceof Expr\FuncCall && self::isPlainExistenceGuard($guard) && self::isExistenceTarget($guard, $invocation) ? $guard : null;
    }

    /**
     * Two positional native arguments keep the receiver and method-name slots unambiguous.
     */
    private static function isPlainExistenceGuard(Expr\FuncCall $guard): bool
    {
        if (! $guard->name instanceof Name || $guard->name->toLowerString() !== self::EXISTENCE_FUNCTION || count($guard->args) !== 2) {
            return false;
        }
        foreach ($guard->getArgs() as $argument) {
            if (! $argument instanceof Node\Arg || $argument->name !== null || $argument->unpack || $argument->byRef) {
                return false;
            }
        }

        return true;
    }

    /**
     * The native check must describe the same lexical receiver and selector as the observed call.
     */
    private static function isExistenceTarget(Expr\FuncCall $guard, Expr\MethodCall $invocation): bool
    {
        $selector = $guard->args[1]->value;
        if (! $selector instanceof Expr\Variable || ! $invocation->name instanceof Expr\Variable || $selector->name !== $invocation->name->name) {
            return false;
        }
        $receiver = $guard->args[0]->value;
        $field = self::ownProperty($receiver);
        if ($field !== null) {
            return $field === self::ownProperty($invocation->var);
        }

        return $receiver instanceof Expr\Variable && $receiver->name === self::THIS_VARIABLE
            && $invocation->var instanceof Expr\Variable && $invocation->var->name === self::THIS_VARIABLE;
    }

    /**
     * Native method_exists evaluates the assignment without changing its selected name.
     */
    private static function assignmentGuard(Expr\Assign $assignment): ?array
    {
        $argument = $assignment->getAttribute(self::PARENT_ATTRIBUTE);
        $call = $argument instanceof Node\Arg ? $argument->getAttribute(self::PARENT_ATTRIBUTE) : null;
        if (! $call instanceof Expr\FuncCall || ! $call->name instanceof Name || $call->name->toLowerString() !== self::EXISTENCE_FUNCTION) {
            return null;
        }
        $condition = $call->getAttribute(self::PARENT_ATTRIBUTE);
        $fields = $condition instanceof Node ? get_object_vars($condition) : [];
        if (($fields['cond'] ?? null) !== $call) {
            return null;
        }

        return ['condition' => $condition, 'shadow' => $call->name->getAttribute(self::NAMESPACED_NAME)?->toLowerString() ?? ''];
    }

    /**
     * Unknown local symbol-table writes can replace a receiver without naming its binding.
     */
    private static function hasOpaqueBindings(Stmt\ClassMethod $method): bool
    {
        if (self::hasBindingJump($method)) {
            return true;
        }
        $unsafe = (new NodeFinder)->find($method->stmts ?? [], static fn (Node $node): bool => $node instanceof Expr\Include_
            || $node instanceof Expr\Eval_ || ($node instanceof Expr\Variable && ! is_string($node->name))
            || ($node instanceof Expr\FuncCall && $node->name instanceof Name && $node->name->getLast() === 'extract'));
        foreach ($unsafe as $node) {
            if (self::isOwnedBy($node, $method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Do not guess named bindings, expressions, duplicate positions or reference effects.
     */
    private static function argument(Node\Arg $argument): ?string
    {
        $isVariable = $argument->value instanceof Expr\Variable && is_string($argument->value->name);

        return $argument->name === null && ! $argument->unpack && ! $argument->byRef && $isVariable
            ? $argument->value->name : null;
    }

    /**
     * Nonlocal jumps can bypass an earlier lexical assignment.
     */
    private static function hasBindingJump(Stmt\ClassMethod $method): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Stmt\Goto_::class) as $jump) {
            if (self::isOwnedBy($jump, $method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A finite registration must occur directly after a supported normally continuing prefix.
     */
    private static function doesTaskPrefixReach(Node $loop, Stmt\ClassMethod $method): bool
    {
        foreach ($method->stmts ?? [] as $statement) {
            if ($statement === $loop) {
                return true;
            }
            if (! self::isPlainTaskPrefix($statement, $method)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Calls may return normally; native exits, constant branches and other control flow stay unsupported.
     */
    private static function isPlainTaskPrefix(Node $statement, Stmt\ClassMethod $method): bool
    {
        if ($statement instanceof Stmt\If_) {
            return $statement->else === null && $statement->elseifs === [] && $statement->cond instanceof Expr\FuncCall && self::hasNormalTaskOperands($statement->cond, $method);
        }
        if (! $statement instanceof Stmt\Expression) {
            return false;
        }
        $expression = $statement->expr;
        if ($expression instanceof Expr\Assign && $expression->var instanceof Expr\Variable) {
            $expression = $expression->expr;
        }

        return self::hasNormalTaskOperands($expression, $method);
    }

    /**
     * Immediate terminal operands and indirect callable invocation invalidate normal continuation.
     */
    private static function hasNormalTaskOperands(Node $expression, Stmt\ClassMethod $method): bool
    {
        if (! $expression instanceof Expr\Variable && ! $expression instanceof Expr\MethodCall && ! $expression instanceof Expr\FuncCall) {
            return false;
        }
        if (self::hasEagerTaskExit($expression, $method)) {
            return false;
        }
        foreach ((new NodeFinder)->findInstanceOf($expression, Expr\FuncCall::class) as $call) {
            if (self::isOwnedBy($call, $method) && ! $call->name instanceof Name) {
                return false;
            }
        }

        return true;
    }

    /**
     * A variable-table snapshot can expose this receiver without a direct variable argument.
     */
    private static function hasTaskScopeSnapshot(Stmt\ClassMethod $method): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Expr\FuncCall::class) as $call) {
            if (! $call->name instanceof Name || $call->isFirstClassCallable() || ! self::isSameClassScope($call, $method)) {
                continue;
            }
            if (strtolower($call->name->getLast()) === 'compact') {
                return true;
            }
        }

        return false;
    }
}
