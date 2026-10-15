<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Usage;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Rejects receiver rebinding and records native guard fallback dependencies.
 *
 * @internal
 */
trait FactoryReceiverBinding
{
    use NativeBindingGuards;

    /**
     * Lexical owners and captures are linked through parser parent attributes.
     */
    private const PARENT_ATTRIBUTE = 'parent';

    /**
     * Escaping receivers may be rebound by reference; native guard fallbacks retain their names.
     */
    private static function bindingGuards(Expr\Assign $assignment, Stmt\ClassMethod $method, ?Node $invocation = null): ?array
    {
        if ($method->isStatic() || ! $assignment->var instanceof Expr\Variable) {
            return null;
        }

        $guards = [];
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Expr\Variable::class) as $variable) {
            if ($variable->name !== $assignment->var->name || ! self::isBindingReference($variable, $method)) {
                continue;
            }

            $guard = self::variableGuard($variable, $assignment, $invocation);
            if ($guard === null) {
                return null;
            }
            if ($guard !== '') {
                $guards[] = $guard;
            }
        }

        return array_values(array_unique($guards));
    }

    /**
     * Nested locals do not overwrite the outer binding; reference captures can.
     */
    private static function isBindingReference(Expr\Variable $variable, Stmt\ClassMethod $method): bool
    {
        $parent = $variable->getAttribute(self::PARENT_ATTRIBUTE);

        return self::isOwnedBy($variable, $method) || ($parent instanceof Expr\ClosureUse && $parent->byRef && self::doesCaptureOuterBinding($parent, $method));
    }

    /**
     * A capture chain reaches this method only through explicit by-reference closure uses.
     */
    private static function doesCaptureOuterBinding(Expr\ClosureUse $use, Stmt\ClassMethod $method): bool
    {
        $closure = $use->getAttribute(self::PARENT_ATTRIBUTE);
        if (self::isOwnedBy($closure, $method)) {
            return true;
        }
        $owner = $closure->getAttribute(self::PARENT_ATTRIBUTE);
        while ($owner instanceof Node && ! $owner instanceof Node\FunctionLike) {
            $owner = $owner->getAttribute(self::PARENT_ATTRIBUTE);
        }
        if (! $owner instanceof Expr\Closure) {
            return false;
        }
        foreach ($owner->uses as $capture) {
            if ($capture->byRef && $capture->var->name === $use->var->name) {
                return self::doesCaptureOuterBinding($capture, $method);
            }
        }

        return false;
    }

    /**
     * Only the original assignment may write this binding anywhere in the owning method.
     */
    private static function isBindingWrite(Node $variable, ?Expr\Assign $assignment): bool
    {
        $parent = $variable->getAttribute(self::PARENT_ATTRIBUTE);
        if ($parent === $assignment) {
            return false;
        }

        if ($parent instanceof Expr\AssignRef || $parent instanceof Expr\AssignOp || $parent instanceof Expr\Assign) {
            return $parent instanceof Expr\AssignRef || $parent->var === $variable;
        }

        return match ($parent?->getType()) {
            'Stmt_Foreach', 'Stmt_Catch', 'Stmt_Unset', 'Stmt_Global', 'StaticVar',
            'Expr_PreInc', 'Expr_PostInc', 'Expr_PreDec', 'Expr_PostDec', 'ArrayItem' => true,
            'ClosureUse', 'Arg'                                                       => $parent->byRef,
            default                                                                   => false,
        };
    }

    /**
     * A nested callable owns independent local variables and receiver bindings.
     */
    private static function isOwnedBy(Node $node, Stmt\ClassMethod $method): bool
    {
        $owner = $node->getAttribute(self::PARENT_ATTRIBUTE);
        while ($owner instanceof Node && ! $owner instanceof Node\FunctionLike) {
            $owner = $owner->getAttribute(self::PARENT_ATTRIBUTE);
        }

        return $owner === $method;
    }

    /**
     * Local aliases and overwritten caller inputs do not establish an open class selection.
     */
    private static function isIncomingArgument(Node\Arg $argument, Stmt\ClassMethod $method): bool
    {
        $value = $argument->value;
        if ($argument->name !== null || $argument->unpack || ! $value instanceof Expr\Variable) {
            return false;
        }
        $parameters = array_column(array_column($method->params, 'var'), 'name');
        if (! in_array($value->name, $parameters, true)) {
            return false;
        }

        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Expr\Variable::class) as $variable) {
            if ($variable->name === $value->name && $variable->getStartFilePos() < $value->getStartFilePos() && self::isBindingReference($variable, $method)) {
                if (self::doesEscapeInput($variable)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Passing an input to unknown code can replace it through a declared reference parameter.
     */
    private static function doesEscapeInput(Expr\Variable $variable): bool
    {
        return self::isBindingWrite($variable, null) || $variable->getAttribute(self::PARENT_ATTRIBUTE) instanceof Node\Arg;
    }

    /**
     * Only the body of the condition containing the assignment inherits its selected name.
     */
    private static function isInsideCondition(Node $node, Node $condition): bool
    {
        $fields = get_object_vars($condition);
        $child = $node;
        for ($owner = $node->getAttribute(self::PARENT_ATTRIBUTE); $owner instanceof Node; $owner = $owner->getAttribute(self::PARENT_ATTRIBUTE)) {
            if ($owner === $condition) {
                return in_array($child, $fields['stmts'] ?? [], true);
            }
            $child = $owner;
        }

        return false;
    }
}
