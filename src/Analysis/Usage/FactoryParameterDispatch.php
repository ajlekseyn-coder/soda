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
     * PHP reserves this variable name for the current instance.
     */
    private const THIS_VARIABLE = 'this';

    /**
     * A top-level factory binding dominates later calls, including calls in conditional branches.
     */
    private static function parameterDispatch(Stmt\ClassLike $class, array $nodes): array
    {
        $dispatches = [];
        foreach ($class->getMethods() as $method) {
            array_push($dispatches, ...self::methodDispatch($class, $method, $nodes), ...self::ownMethodDispatch($class, $method));
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
     * Computed calls on this instance can require protected trait implementation slots.
     */
    private static function ownMethodDispatch(Stmt\ClassLike $class, Stmt\ClassMethod $method): array
    {
        if ($class->name === null || self::hasOpaqueBindings($method)) {
            return [];
        }

        $dispatches = [];
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Expr || ! self::isOwnPositionalCall($call, $method)) {
                continue;
            }
            $assignment = self::ownSelector($call, $method);
            $pattern = self::boundedSelector($assignment?->expr ?? $call->name, $method);
            $guards = $assignment === null ? [] : self::selectorGuards($assignment, $call, $method);
            if ($pattern !== null && $guards !== null) {
                $owner = $class->namespacedName?->toLowerString() ?? $class->name->toLowerString();
                $dispatches[] = ['caller' => $owner.'::'.$method->name->toLowerString(), 'method' => $pattern, 'arity' => count($call->args), 'guards' => $guards];
            }
        }

        return $dispatches;
    }

    /**
     * One preceding lexical assignment must select a positional call on this receiver.
     */
    private static function ownSelector(Expr\MethodCall $call, Stmt\ClassMethod $method): ?Expr\Assign
    {
        if (! $call->name instanceof Expr\Variable) {
            return null;
        }

        $assignments = (new NodeFinder)->findInstanceOf($method->stmts ?? [], Expr\Assign::class);
        $matches = array_filter($assignments, static fn (Expr\Assign $node): bool => $node->var instanceof Expr\Variable
            && $node->var->name === $call->name->name && $node->getEndFilePos() < $call->getStartFilePos() && self::isOwnedBy($node, $method));

        return count($matches) === 1 ? array_pop($matches) : null;
    }

    /**
     * Only a positional invocation on this lexical instance establishes its protocol.
     */
    private static function isOwnPositionalCall(Expr\MethodCall $call, Stmt\ClassMethod $method): bool
    {
        $isThis = $call->var instanceof Expr\Variable && $call->var->name === self::THIS_VARIABLE && ! $method->isStatic();

        return $isThis && ! $call->isFirstClassCallable() && self::isOwnedBy($call, $method) && self::hasPositionalArguments($call);
    }

    /**
     * An unconditional assignment or an evaluated native guard must dominate the invocation.
     */
    private static function selectorGuards(Expr\Assign $assignment, Expr\MethodCall $call, Stmt\ClassMethod $method): ?array
    {
        $guards = self::bindingGuards($assignment, $method);
        if ($guards === null) {
            return null;
        }
        if (self::doesAssignmentDominate($assignment, $call)) {
            return $guards;
        }
        $guard = self::assignmentGuard($assignment);
        if ($guard === null || ! self::isInsideCondition($call, $guard['condition'])) {
            return null;
        }

        return [...$guards, $guard['shadow']];
    }

    /**
     * An earlier sibling assignment dominates calls in the same statement list or its later branches.
     */
    private static function doesAssignmentDominate(Expr\Assign $assignment, Node $call): bool
    {
        $statement = $assignment->getAttribute(self::PARENT_ATTRIBUTE);
        $block = $statement instanceof Stmt\Expression ? $statement->getAttribute(self::PARENT_ATTRIBUTE) : null;
        if (! $block instanceof Node) {
            return false;
        }
        $statements = get_object_vars($block)['stmts'] ?? [];

        return in_array($statement, $statements, true) && self::isInsideCondition($call, $block);
    }

    /**
     * An observed virtual call preserves the factory's replaceable implementation.
     */
    private static function calledHook(Node $call, Stmt\ClassLike $class, Stmt\ClassMethod $method): ?string
    {
        $fields = get_object_vars($call);
        $name = $fields['name'] ?? null;
        $receiver = $fields['var'] ?? null;
        $owner = $fields['class'] ?? null;
        $isThis = $receiver instanceof Expr\Variable && $receiver->name === self::THIS_VARIABLE && ! $method->isStatic();
        $isLateBound = self::isReplaceableClassCall($owner, $class);
        if (! $name instanceof Node\Identifier || ! self::isOwnedBy($call, $method) || ! self::canVaryByReceiver($class, $name->toString(), $isLateBound)) {
            return null;
        }

        return $isThis || $isLateBound ? $name->toLowerString() : null;
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
        $isThis = $call->var instanceof Expr\Variable && $call->var->name === self::THIS_VARIABLE;
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

        return self::isOwnedBy($call, $method) && $same && self::hasPositionalArguments($call);
    }

    /**
     * Named and unpacked arguments cannot establish a contiguous positional prefix.
     */
    private static function hasPositionalArguments(Expr\MethodCall $call): bool
    {
        return array_filter(array_column($call->getArgs(), 'name')) === [] && ! in_array(true, array_column($call->getArgs(), 'unpack'), true);
    }
}
