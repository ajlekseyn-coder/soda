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

        if ($parent instanceof Stmt\Foreach_) {
            return self::isIterationWrite($variable, $parent);
        }

        return match ($parent?->getType()) {
            'Stmt_Catch', 'Stmt_Unset', 'Stmt_Global', 'StaticVar',
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

    /**
     * A by-value foreach with one direct invocation shares the same positional arguments.
     */
    private static function sharedTaskCalls(Stmt\ClassMethod $method): array
    {
        if ($method->isStatic() || self::hasOpaqueBindings($method)) {
            return [];
        }
        $calls = [];
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Stmt\Foreach_::class) as $loop) {
            $call = self::sharedTaskCall($loop, $method);
            if ($call === null) {
                continue;
            }
            $names = self::literalTasks($loop->expr);
            $property = self::ownProperty($loop->expr);
            if ($names !== [] || $property !== null) {
                $calls[] = ['names' => $names, 'property' => $property, 'arity' => count($call->args)];
            }
        }

        return $calls;
    }

    /**
     * Selector writes, keys, nested callables and argument transformations are outside this protocol.
     */
    private static function sharedTaskCall(Stmt\Foreach_ $loop, Stmt\ClassMethod $method): ?Expr\MethodCall
    {
        $selector = self::taskIterationName($loop, $method);
        if ($selector === null) {
            return null;
        }
        $body = $loop->stmts;
        $statement = count($body) === 1 ? reset($body) : null;
        $call = $statement instanceof Stmt\Expression ? $statement->expr : null;
        if (! $call instanceof Expr\MethodCall || ! $call->name instanceof Expr\Variable || ! self::isOwnDispatch($call, $method) || $call->isFirstClassCallable()) {
            return null;
        }
        $arguments = array_map(self::argument(...), $call->getArgs());

        return $call->name->name === $selector && self::hasDistinctTaskArguments($arguments, $method) ? $call : null;
    }

    /**
     * Only a lexical value binding represents each literal task name.
     */
    private static function taskIterationName(Stmt\Foreach_ $loop, Stmt\ClassMethod $method): ?string
    {
        if ($loop->byRef || $loop->keyVar !== null || ! $loop->valueVar instanceof Expr\Variable || ! self::doesTaskPrefixReach($loop, $method)) {
            return null;
        }

        return is_string($loop->valueVar->name) ? $loop->valueVar->name : null;
    }

    /**
     * Duplicate values or local reference/value aliases do not prove distinct required argument slots.
     */
    private static function hasDistinctTaskArguments(array $arguments, Stmt\ClassMethod $method): bool
    {
        if (in_array(null, $arguments, true) || count(array_unique($arguments)) !== count($arguments)) {
            return false;
        }
        $finder = new NodeFinder;
        $aliases = [...$finder->findInstanceOf($method->stmts ?? [], Expr\Assign::class), ...$finder->findInstanceOf($method->stmts ?? [], Expr\AssignRef::class)];
        foreach ($aliases as $alias) {
            if (! self::isOwnedBy($alias, $method) || ! $alias->var instanceof Expr\Variable || ! $alias->expr instanceof Expr\Variable) {
                continue;
            }
            if (in_array($alias->var->name, $arguments, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reference iteration exposes the source elements even when its expression is only read.
     */
    private static function isIterationWrite(Node $reference, Stmt\Foreach_ $loop): bool
    {
        return in_array($reference, [$loop->keyVar, $loop->valueVar], true) || ($loop->byRef && $loop->expr === $reference);
    }
}
