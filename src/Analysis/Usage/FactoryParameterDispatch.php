<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Usage;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
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
     * Keep literal or bounded method names only when dispatched on the declaring receiver.
     */
    private static function factoryPatterns(Stmt\ClassLike $class): array
    {
        $patterns = self::directPatterns($class);
        foreach ($class->getMethods() as $method) {
            $finder = new NodeFinder;
            $nodes = $method->stmts ?? [];
            $assignments = $finder->findInstanceOf($nodes, Assign::class);
            foreach ($finder->findInstanceOf($nodes, Expr\MethodCall::class) as $call) {
                if (! self::isOwnDispatch($call, $method)) {
                    continue;
                }

                $matches = array_filter($assignments, static fn (Assign $assignment): bool => $assignment->var instanceof Expr\Variable
                    && $assignment->var->name === $call->name->name
                    && $assignment->getStartFilePos() < $call->getStartFilePos() && self::isOwnedBy($assignment, $method));
                if (count($matches) !== 1) {
                    continue;
                }

                $assignment = array_shift($matches);
                $pattern = self::pattern($assignment->expr);
                $hasLiteral = str_replace('.*', '', $pattern) !== '';
                if ($hasLiteral) {
                    $patterns[] = '/^'.$pattern.self::PATTERN_END;
                }
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Literal receiver calls establish exact hooks without a computed selector.
     */
    private static function directPatterns(Stmt\ClassLike $class): array
    {
        $patterns = [];
        $finder = new NodeFinder;
        foreach ($class->getMethods() as $method) {
            $nodes = $method->stmts ?? [];
            foreach ($finder->find($nodes, static fn (Node $node): bool => in_array($node->getType(), [
                'Expr_MethodCall', 'Expr_NullsafeMethodCall', 'Expr_StaticCall',
            ], true)) as $call) {
                $name = self::calledHook($call, $class, $method);
                if ($name !== null) {
                    $patterns[] = '/^'.preg_quote($name, '/').self::PATTERN_END;
                }
            }

        }

        return $patterns;
    }

    /**
     * Distinguish this object's dispatch from calls on another collaborator.
     */
    private static function isOwnDispatch(Expr\MethodCall $call, ClassMethod $method): bool
    {
        $isThis = $call->var instanceof Expr\Variable && $call->var->name === 'this';
        $isNamedVariable = $call->name instanceof Expr\Variable && is_string($call->name->name);

        return $isThis && $isNamedVariable && self::isOwnedBy($call, $method);
    }

    /**
     * Each finite loop retains its declaring caller so composition can select the actual body.
     */
    private static function taskDispatch(Stmt\ClassLike $class): array
    {
        if (! $class->name instanceof Identifier) {
            return [];
        }

        $owner = strtolower($class->namespacedName?->toString() ?? $class->name->toString());
        $calls = [];
        foreach ($class->getMethods() as $method) {
            foreach (self::sharedTaskCalls($method) as $call) {
                $calls[] = $call + ['caller' => $owner.'::'.$method->name->toLowerString()];
            }
        }

        return $calls;
    }

    /**
     * A declared instance default can supply an observed task loop before any field writes.
     */
    private static function taskLists(Stmt\ClassLike $class): array
    {
        $lists = [];
        foreach ($class->getProperties() as $property) {
            if ($property->isStatic() || $property->isPublic() || $property->hooks !== []) {
                continue;
            }

            foreach ($property->props as $item) {
                if ($item->default !== null && self::isStableTaskList($item->name->toString(), $class)) {
                    $lists[$item->name->toString()] = self::literalTasks($item->default);
                }
            }
        }

        return $lists;
    }

    /**
     * Element mutation and receiver aliases can invalidate a literal default without replacing the field.
     */
    private static function isStableTaskList(string $field, Stmt\ClassLike $class): bool
    {
        foreach ($class->getMethods() as $method) {
            if (self::doesEscapeTaskReceiver($method)) {
                return false;
            }

            foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Expr\PropertyFetch::class) as $reference) {
                if (self::ownProperty($reference) !== $field || ! self::isSameClassScope($reference, $method)) {
                    continue;
                }

                if (self::variableGuard(self::storageReference($reference), null) === null) {
                    return false;
                }
            }
        }

        return true;
    }

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
    private static function methodDispatch(Stmt\ClassLike $class, ClassMethod $method, array $nodes): array
    {
        if (self::hasOpaqueBindings($method)) {
            return [];
        }

        $dispatches = [];
        foreach ($method->stmts ?? [] as $statement) {
            $assignment = $statement instanceof Stmt\Expression ? $statement->expr : null;
            if (! $assignment instanceof Assign) {
                continue;
            }

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
    private static function ownMethodDispatch(Stmt\ClassLike $class, ClassMethod $method): array
    {
        if (! $class->name instanceof Identifier || self::hasOpaqueBindings($method)) {
            return [];
        }

        $dispatches = [];
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Identifier || ! self::isOwnPositionalCall($call, $method)) {
                continue;
            }

            $assignment = self::ownSelector($call, $method);
            $pattern = self::boundedSelector($assignment?->expr ?? $call->name, $method);
            $guards = $assignment instanceof Assign ? self::selectorGuards($assignment, $call, $method) : [];
            if ($pattern !== null && $guards !== null) {
                $owner = $class->namespacedName?->toLowerString() ?? $class->name->toLowerString();
                $dispatches[] = ['caller' => $owner.'::'.$method->name->toLowerString(), 'property' => self::ownProperty($call->var), 'method' => $pattern, 'arity' => count($call->args), 'guards' => $guards];
            }
        }

        return $dispatches;
    }

    /**
     * One preceding lexical assignment must select a positional call on this receiver.
     */
    private static function ownSelector(Expr\MethodCall $call, ClassMethod $method): ?Assign
    {
        if (! $call->name instanceof Expr\Variable) {
            return null;
        }

        $assignments = (new NodeFinder)->findInstanceOf($method->stmts ?? [], Assign::class);
        $matches = array_filter($assignments, static fn (Assign $node): bool => $node->var instanceof Expr\Variable
            && $node->var->name === $call->name->name && $node->getEndFilePos() < $call->getStartFilePos() && self::isOwnedBy($node, $method));

        return count($matches) === 1 ? array_pop($matches) : null;
    }

    /**
     * Only a positional invocation on this lexical instance establishes its protocol.
     */
    private static function isOwnPositionalCall(Expr\MethodCall $call, ClassMethod $method): bool
    {
        $isThis = ($call->var instanceof Expr\Variable && $call->var->name === self::THIS_VARIABLE) || self::ownProperty($call->var) !== null;

        return $isThis && ! $method->isStatic() && ! $call->isFirstClassCallable() && self::isOwnedBy($call, $method) && self::hasPositionalArguments($call);
    }

    /**
     * An unconditional assignment or an evaluated native guard must dominate the invocation.
     */
    private static function selectorGuards(Assign $assignment, Expr\MethodCall $call, ClassMethod $method): ?array
    {
        $guards = self::bindingGuards($assignment, $method, $call);
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
    private static function doesAssignmentDominate(Assign $assignment, Node $call): bool
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
    private static function calledHook(Node $call, Stmt\ClassLike $class, ClassMethod $method): ?string
    {
        $fields = get_object_vars($call);
        $name = $fields['name'] ?? null;
        $receiver = $fields['var'] ?? null;
        $owner = $fields['class'] ?? null;
        $isThis = $receiver instanceof Expr\Variable && $receiver->name === self::THIS_VARIABLE && ! $method->isStatic();
        $isLateBound = self::isReplaceableClassCall($owner, $class);
        if (! $name instanceof Identifier || ! self::isOwnedBy($call, $method) || ! self::canVaryByReceiver($class, $name->toString(), $isLateBound)) {
            return null;
        }

        return $isThis || $isLateBound ? $name->toLowerString() : null;
    }

    /**
     * Match the actual local factory declaration rather than an unrelated same-named method.
     */
    private static function factoryBinding(Assign $expression, Stmt\ClassLike $class, array $nodes, ClassMethod $method): ?string
    {
        if (! $expression->var instanceof Expr\Variable || ! is_string($expression->var->name)) {
            return null;
        }

        $call = $expression->expr;
        if (! $call instanceof Expr\MethodCall) {
            return null;
        }

        $name = self::localFactoryName($call);
        $factory = $name === null ? null : $class->getMethod($name);

        return $factory instanceof ClassMethod && self::isComputedFactory($factory, $call, $class, $method) ? self::returnedClass($factory, $class, $nodes) : null;
    }

    /**
     * This receiver selects the declaration whose return bound was read.
     */
    private static function localFactoryName(Expr\MethodCall $call): ?string
    {
        $isThis = $call->var instanceof Expr\Variable && $call->var->name === self::THIS_VARIABLE;
        if (! $call->name instanceof Identifier || $call->isFirstClassCallable() || ! $isThis) {
            return null;
        }

        return $call->name->toString();
    }

    /**
     * Open inputs and a virtual computed factory preserve a possible receiver family.
     */
    private static function isComputedFactory(ClassMethod $factory, Expr\MethodCall $call, Stmt\ClassLike $class, ClassMethod $caller): bool
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
                return $statement->expr instanceof Expr\New_ && self::hasOpenConstruction($statement->expr, $factory, $call, $class);
            }
        }

        return false;
    }

    /**
     * A closed factory must construct its provided class parameter directly.
     */
    private static function hasOpenConstruction(Expr\New_ $instance, ClassMethod $factory, Expr\MethodCall $call, Stmt\ClassLike $class): bool
    {
        if (! $instance->class instanceof Expr\Variable) {
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
     * Unknown consumers and local aliases can write this instance's protected task list.
     */
    private static function doesEscapeTaskReceiver(ClassMethod $method): bool
    {
        if (self::hasTaskScopeSnapshot($method)) {
            return true;
        }

        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Expr\Variable::class) as $variable) {
            if ($variable->name !== self::THIS_VARIABLE || ! self::isSameClassScope($variable, $method)) {
                continue;
            }

            $parent = $variable->getAttribute(self::PARENT_ATTRIBUTE);
            $isReceiver = ($parent instanceof Expr\MethodCall || $parent instanceof Expr\PropertyFetch) && $parent->var === $variable;
            if (! $isReceiver) {
                return true;
            }
        }

        return false;
    }

    /**
     * Field writers and pure getters remain scalar source facts until composition selects their bodies.
     */
    private static function fieldFlows(Stmt\ClassLike $class): array
    {
        $flows = [];
        foreach ($class->getMethods() as $method) {
            $statements = $method->stmts ?? [];
            $flow = ['return' => self::returnedField($method), 'writes' => [], 'blocked' => self::hasOpaqueBindings($method) ? ['*' => true] : [], 'guards' => [], 'receiverEscapes' => self::doesEscapeTaskReceiver($method)];
            foreach ((new NodeFinder)->findInstanceOf($statements, Expr\PropertyFetch::class) as $property) {
                self::fieldReference($property, $method, $flow);
            }

            foreach ($method->params as $parameter) {
                if ($parameter->isPromoted()) {
                    $source = self::parameterSource($parameter->var, $method);
                    self::recordFieldWrite($flow, $parameter->var->name, ['source' => $source, 'constructor' => true, 'setter' => $statements === [] && $source !== null, 'prefix' => []]);
                }
            }

            if (array_filter($flow, static fn ($value): bool => (bool) $value) !== []) {
                $flows[$method->name->toLowerString()] = $flow;
            }
        }

        return $flows;
    }

    /**
     * A zero-argument pure getter reads its field without changing the receiver state.
     */
    private static function returnedField(ClassMethod $method): ?string
    {
        if ($method->isStatic() || $method->byRef || $method->params !== []) {
            return null;
        }

        $body = $method->stmts ?? [];

        return count($body) === 1 && $body[0] instanceof Stmt\Return_ ? self::ownProperty($body[0]->expr) : null;
    }

    /**
     * Foreign instances and computed field names have no owned storage identity.
     */
    private static function ownProperty(?Node $property): ?string
    {
        if (! $property instanceof Expr\PropertyFetch || ! $property->var instanceof Expr\Variable || $property->var->name !== self::THIS_VARIABLE) {
            return null;
        }

        return $property->name instanceof Identifier ? $property->name->toString() : null;
    }

    /**
     * Unknown reference calls and nested field writes cannot preserve an earlier receiver binding.
     */
    private static function fieldReference(Expr\PropertyFetch $property, ClassMethod $method, array &$flow): void
    {
        if (! $property->var instanceof Expr\Variable || $property->var->name !== self::THIS_VARIABLE || ! self::isSameClassScope($property, $method)) {
            return;
        }

        $field = self::ownProperty($property) ?? '*';
        $parent = $property->getAttribute(self::PARENT_ATTRIBUTE);
        if ($parent instanceof Assign && $parent->var === $property && self::isOwnedBy($property, $method)) {
            self::recordFieldWrite($flow, $field, self::fieldStore($parent, $method));

            return;
        }

        $guard = self::variableGuard(self::storageReference($property), null);
        if ($guard === null) {
            $flow['blocked'] += [$field => true];
        } elseif ($guard !== '') {
            $flow['guards'] = [...$flow['guards'], $guard];
        }
    }

    /**
     * A nested class owns a different this instance, while nested closures may still write this field.
     */
    private static function isSameClassScope(Node $node, ClassMethod $method): bool
    {
        $owner = $node->getAttribute(self::PARENT_ATTRIBUTE);
        while ($owner instanceof Node && ! $owner instanceof Stmt\ClassLike) {
            $owner = $owner->getAttribute(self::PARENT_ATTRIBUTE);
        }

        return $owner === $method->getAttribute(self::PARENT_ATTRIBUTE);
    }

    /**
     * Public construction can wire an incoming object before any conditional initialization.
     */
    private static function fieldStore(Assign $assignment, ClassMethod $method): array
    {
        $prefix = self::fieldPrefix($assignment, $method);
        $source = $prefix === null ? null : self::fieldSource($assignment->expr, $method);
        $body = $method->stmts ?? [];
        $last = count($body) === 2 ? $body[1] : null;
        $returnsThis = $last instanceof Stmt\Return_ && $last->expr instanceof Expr\Variable && $last->expr->name === self::THIS_VARIABLE;
        $pure = ($body[0] ?? null) === $assignment->getAttribute(self::PARENT_ATTRIBUTE) && (count($body) === 1 || $returnsThis);

        return ['source' => $source, 'constructor' => $method->name->toLowerString() === '__construct', 'setter' => $pure && $source !== null && ! isset($source['getter']), 'prefix' => $prefix ?? []];
    }

    /**
     * Prior wiring must be a plain parameter store; effects cannot establish the input's original state.
     */
    private static function fieldPrefix(Assign $assignment, ClassMethod $method): ?array
    {
        if ($method->isStatic() || $method->byRef) {
            return null;
        }

        $prefix = [];
        foreach ($method->stmts ?? [] as $statement) {
            $expression = $statement instanceof Stmt\Expression ? $statement->expr : null;
            if ($expression === $assignment) {
                return $prefix;
            }

            if (! $expression instanceof Assign) {
                return null;
            }

            $field = self::ownProperty($expression->var);
            $source = self::prefixSource($expression, $method);
            if ($field === null || $source === null || isset($prefix[$field])) {
                return null;
            }

            $prefix[$field] = $source['type'];
        }

        return null;
    }

    /**
     * A preceding plain input store preserves the incoming dependency's identity.
     */
    private static function prefixSource(Assign $assignment, ClassMethod $method): ?array
    {
        return $assignment->expr instanceof Expr\Variable ? self::parameterSource($assignment->expr, $method) : null;
    }

    /**
     * Keep writes grouped by their declared field instead of conflating same-named methods.
     */
    private static function recordFieldWrite(array &$flow, string $field, array $write): void
    {
        $writes = $flow['writes'];
        $writes[$field] = [...($writes[$field] ?? []), $write];
        $flow['writes'] = $writes;
    }

    /**
     * A dependency getter must be called on the unchanged incoming native object.
     */
    private static function fieldSource(Node $value, ClassMethod $method): ?array
    {
        if ($value instanceof Expr\Variable) {
            return self::parameterSource($value, $method);
        }

        if (! $value instanceof Expr\MethodCall || ! $value->var instanceof Expr\Variable || ! $value->name instanceof Identifier) {
            return null;
        }

        $source = self::parameterSource($value->var, $method);

        return $source !== null && $value->args === [] && ! $value->isFirstClassCallable() ? $source + ['getter' => $value->name->toLowerString()] : null;
    }

    /**
     * Only original native parameters establish an open object input.
     */
    private static function parameterSource(Expr\Variable $value, ClassMethod $method): ?array
    {
        if (self::doesParameterEscape($value, $method)) {
            return null;
        }

        foreach ($method->params as $parameter) {
            $bound = self::nativeClass($parameter->type);
            if ($parameter->var->name === $value->name && ! $parameter->byRef && $bound !== null) {
                return ['type' => $bound];
            }
        }

        return null;
    }

    /**
     * Aliases, writes and prior receiver operations can replace an incoming object's stored value.
     */
    private static function doesParameterEscape(Expr\Variable $value, ClassMethod $method): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Expr\Variable::class) as $variable) {
            $parent = $variable->getAttribute(self::PARENT_ATTRIBUTE);
            $escapes = self::doesEscapeInput($variable) || $parent instanceof Expr\MethodCall || $parent instanceof Expr\PropertyFetch;
            if ($variable->name === $value->name && $variable->getStartFilePos() < $value->getStartFilePos() && self::isBindingReference($variable, $method) && $escapes) {
                return true;
            }
        }

        return false;
    }

    /**
     * Named or unpacked arguments do not establish a contiguous positional prefix.
     */
    private static function isBoundCall(Expr\MethodCall $call, Assign $assignment, ClassMethod $method): bool
    {
        if (! $call->name instanceof Identifier || $call->isFirstClassCallable() || $call->getStartFilePos() <= $assignment->getEndFilePos()) {
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
