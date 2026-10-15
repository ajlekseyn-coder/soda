<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Usage;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Collects observed positional calls on locally constructed bounded receiver families.
 *
 * @internal
 */
trait FactoryParameterDispatch
{
    use FactoryReceiverBinding, FactorySignatures;

    /**
     * A top-level factory binding dominates later calls, including calls in conditional branches.
     */
    private static function parameterDispatch(Stmt\ClassLike $class, array $nodes): array
    {
        $dispatches = [];
        foreach ($class->getMethods() as $method) {
            array_push($dispatches, ...self::methodDispatch($class, $method, $nodes));
        }

        return $dispatches;
    }

    /**
     * Each receiver binding belongs to one lexical method body.
     */
    private static function methodDispatch(Stmt\ClassLike $class, Stmt\ClassMethod $method, array $nodes): array
    {
        if (self::hasOpaqueBindings($method)) {
            return [];
        }

        $dispatches = [];
        foreach ($method->stmts ?? [] as $statement) {
            $assignment = $statement instanceof Stmt\Expression ? $statement->expr : null;
            $bound = self::factoryBinding($assignment, $class, $nodes, $method);
            $guards = $bound === null ? null : self::bindingGuards($assignment, $method);
            if ($guards === null) {
                continue;
            }

            foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Expr\MethodCall::class) as $call) {
                if (self::isBoundCall($call, $assignment, $method)) {
                    $dispatches[] = ['type' => $bound, 'method' => $call->name->toLowerString(), 'arity' => count($call->args), 'guards' => $guards];
                }
            }
        }

        return $dispatches;
    }

    /**
     * Match the actual local factory declaration rather than an unrelated same-named method.
     */
    private static function factoryBinding(?Expr $expression, Stmt\ClassLike $class, array $nodes, Stmt\ClassMethod $method): ?string
    {
        if (! $expression instanceof Expr\Assign || ! $expression->var instanceof Expr\Variable || ! is_string($expression->var->name)) {
            return null;
        }

        $call = $expression->expr;
        if (! $call instanceof Expr\MethodCall) {
            return null;
        }

        $name = self::localFactoryName($call);
        $factory = $name === null ? null : $class->getMethod($name);

        return $factory !== null && self::isComputedFactory($factory, $call, $class, $method) ? self::returnedClass($factory, $class, $nodes) : null;
    }

    /**
     * This receiver selects the declaration whose return bound was read.
     */
    private static function localFactoryName(Expr\MethodCall $call): ?string
    {
        $isThis = $call->var instanceof Expr\Variable && $call->var->name === 'this';
        if (! $call->name instanceof Node\Identifier || $call->isFirstClassCallable() || ! $isThis) {
            return null;
        }

        return $call->name->toString();
    }

    /**
     * Open inputs and a virtual computed factory preserve a possible receiver family.
     */
    private static function isComputedFactory(Stmt\ClassMethod $factory, Expr\MethodCall $call, Stmt\ClassLike $class, Stmt\ClassMethod $caller): bool
    {
        if ($call->args === []) {
            return false;
        }
        foreach ($call->getArgs() as $argument) {
            if (! self::isIncomingArgument($argument, $caller)) {
                return false;
            }
        }

        foreach ($factory->stmts ?? [] as $statement) {
            if ($statement instanceof Stmt\Return_) {
                return self::hasOpenConstruction($statement->expr, $factory, $call, $class);
            }
        }

        return false;
    }

    /**
     * A closed factory must construct its provided class parameter directly.
     */
    private static function hasOpenConstruction(?Expr $instance, Stmt\ClassMethod $factory, Expr\MethodCall $call, Stmt\ClassLike $class): bool
    {
        if (! $instance instanceof Expr\New_ || ! $instance->class instanceof Expr\Variable) {
            return false;
        }

        $virtual = $class instanceof Stmt\Class_ && ! $class->isFinal() && ! $factory->isPrivate() && ! $factory->isFinal();
        if ($virtual) {
            return true;
        }
        $parameters = array_column(array_column($factory->params, 'var'), 'name');
        $position = array_search($instance->class->name, $parameters, true);

        return count($factory->stmts ?? []) === 1 && $position !== false && isset($call->args[$position]);
    }

    /**
     * Named or unpacked arguments do not establish a contiguous positional prefix.
     */
    private static function isBoundCall(Expr\MethodCall $call, Expr\Assign $assignment, Stmt\ClassMethod $method): bool
    {
        if (! $call->name instanceof Node\Identifier || $call->isFirstClassCallable() || $call->getStartFilePos() <= $assignment->getEndFilePos()) {
            return false;
        }

        $same = $call->var instanceof Expr\Variable && $assignment->var instanceof Expr\Variable && $call->var->name === $assignment->var->name;
        $arguments = $call->getArgs();
        $named = array_filter(array_column($arguments, 'name'));
        $unpacked = in_array(true, array_column($arguments, 'unpack'), true);

        return self::isOwnedBy($call, $method) && $same && $named === [] && ! $unpacked;
    }
}
