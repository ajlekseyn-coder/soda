<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Representation;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Records exact call syntax and declared object storage for RepresentationFacts.
 *
 * The owner supplies expression, nativeType and unknown through
 * RepresentationValueSyntax. Argument expressions consume the same callable
 * budget as the enclosing statement. Object field contracts and implicit promotion
 * describe the storage an allocation can establish; this Concern has no persistent state.
 *
 * @internal
 */
trait RepresentationCallSyntax
{
    /**
     * Operation name marking resolved call syntax in the scalar IR.
     */
    private const string CALL = 'call';

    /**
     * Retain exact method names and receiver expressions; dynamic invocation remains unknown.
     */
    private static function methodCall(Expr\MethodCall $node, ?string $class, int &$remaining): array
    {
        if (! $node->name instanceof Node\Identifier || $node->isFirstClassCallable()) {
            return self::unknown($node->getType(), $node->getStartLine());
        }

        return ['op' => self::CALL, 'receiver' => self::expression($node->var, $class, $remaining), 'class' => null, 'name' => $node->name->toLowerString(),
            'kind'   => 'method', 'args' => self::arguments($node->args, $class, $remaining), 'line' => $node->getStartLine()];
    }

    /**
     * Preserve exact static call syntax without inventing dynamic class resolution.
     */
    private static function staticCall(Expr\StaticCall $node, ?string $class, int &$remaining): array
    {
        if (! $node->class instanceof Name || ! $node->name instanceof Node\Identifier || $node->isFirstClassCallable()) {
            return self::unknown($node->getType(), $node->getStartLine());
        }

        return ['op' => self::CALL, 'receiver' => null, 'class' => self::nativeType($node->class, $class), 'name' => $node->name->toLowerString(),
            'kind'   => 'static', 'args' => self::arguments($node->args, $class, $remaining), 'line' => $node->getStartLine()];
    }

    /**
     * Resolve local namespace functions while keeping explicit global names exact.
     */
    private static function functionCall(Expr\FuncCall $node, ?string $class, int &$remaining): array
    {
        if (! $node->name instanceof Name || $node->isFirstClassCallable()) {
            return self::unknown($node->getType(), $node->getStartLine());
        }

        $name = $node->name->getAttribute('namespacedName') ?? $node->name;

        return ['op' => self::CALL, 'receiver' => null, 'class' => null, 'name' => strtolower($name->toString()),
            'kind'   => 'function', 'args' => self::arguments($node->args, $class, $remaining), 'line' => $node->getStartLine()];
    }

    /**
     * Preserve named allocation and argument binding; anonymous or dynamic classes are unknown.
     */
    private static function allocation(Expr\New_ $node, ?string $class, int &$remaining): array
    {
        if (! $node->class instanceof Name) {
            return self::unknown($node->getType(), $node->getStartLine());
        }

        return ['op' => 'new', 'class' => self::nativeType($node->class, $class), 'args' => self::arguments($node->args, $class, $remaining), 'line' => $node->getStartLine()];
    }

    /**
     * Preserve named and unpacked arguments under the caller budget.
     *
     * @param list<Node\Arg> $arguments
     */
    private static function arguments(array $arguments, ?string $class, int &$remaining): array
    {
        $args = [];
        foreach ($arguments as $argument) {
            $args[] = ['name' => $argument->name?->toString(), 'value' => self::expression($argument->value, $class, $remaining), 'unpack' => $argument->unpack];
            if ($remaining < 0) {
                break;
            }
        }

        return $args;
    }

    /**
     * Record declared fields and method identities without inferring storage semantics.
     */
    private static function type(Stmt\Class_ $type): array
    {
        $name = $type->namespacedName?->toString() ?? $type->name?->toString() ?? '';
        $id = strtolower($name);
        $properties = [];
        $methods = [];
        $eligible = ! $type->extends instanceof Name && $type->getTraitUses() === [];
        foreach ($type->getProperties() as $property) {
            $eligible = $eligible && self::hasSupportedStorage($property);
            foreach ($property->props as $item) {
                $properties[$item->name->toString()] = self::nativeType($property->type, $id);
            }
        }

        foreach ($type->getMethods() as $method) {
            $methodName = $method->name->toLowerString();
            $methods[$methodName] = $id.'::'.$methodName;
            if ($methodName !== '__construct' && str_starts_with($methodName, '__')) {
                $eligible = false;
            }
        }

        $constructor = $type->getMethod('__construct');
        $properties += self::promotedProperties($constructor, $id, $eligible);

        return ['name'    => $name, 'final' => $type->isFinal(), 'readonly' => $type->isReadonly(),
            'eligible'    => $eligible, 'properties' => $properties,
            'constructor' => $methods['__construct'] ?? null, 'methods' => $methods];
    }

    /**
     * Static storage, hooks and initializers require facts absent from this narrow IR.
     */
    private static function hasSupportedStorage(Stmt\Property $property): bool
    {
        if ($property->isStatic() || $property->hooks !== []) {
            return false;
        }

        foreach ($property->props as $item) {
            if ($item->default !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Promotion contributes instance field contracts; hooks make storage unresolved.
     */
    private static function promotedProperties(?Stmt\ClassMethod $constructor, string $class, bool &$eligible): array
    {
        $properties = [];
        foreach ($constructor?->params ?? [] as $parameter) {
            if ($parameter->hooks !== []) {
                $eligible = false;
            }

            $parameterName = self::parameterName($parameter);
            if ($parameter->flags !== 0 && $parameterName !== null) {
                $properties[$parameterName] = self::nativeType($parameter->type, $class);
            }
        }

        return $properties;
    }

    /**
     * Model PHP promotion before the explicit constructor body runs.
     */
    private static function promotion(Node\Param $parameter): array
    {
        $line = $parameter->getStartLine();
        $name = self::parameterName($parameter);

        return ['op' => 'assign', 'target' => ['op' => 'property', 'receiver' => ['op' => 'variable', 'name' => 'this', 'line' => $line],
            'name'                                  => $name, 'line' => $line], 'value' => ['op' => 'variable', 'name' => $name, 'line' => $line], 'line' => $line];
    }

    /**
     * Resolve a declared parameter name shared by signature and constructor promotion facts.
     */
    private static function parameterName(Node\Param $parameter): ?string
    {
        if (! $parameter->var instanceof Expr\Variable || ! is_string($parameter->var->name)) {
            return null;
        }

        return $parameter->var->name;
    }
}
