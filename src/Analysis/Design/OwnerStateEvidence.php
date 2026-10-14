<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Design;

use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;

/**
 * Captures declared state and rejects unresolved composition.
 *
 * @internal
 */
trait OwnerStateEvidence
{
    /**
     * Method name of the PHP constructor protocol hook.
     */
    private const CONSTRUCTOR = '__construct';

    /**
     * Keep declared state contracts beside the independent method evidence.
     */
    private function type(Stmt\Class_ $class, string $name): array
    {
        $state = $this->ownerState($class);
        $methods = [];
        $eligible = ! $class->isAbstract() && ! $class->extends instanceof Name && $class->implements === [] && $class->getTraitUses() === [];
        foreach ($class->getMethods() as $method) {
            $methodName = $method->name->toLowerString();
            if ($methodName === self::CONSTRUCTOR) {
                continue;
            }

            $eligible = $eligible && ! str_starts_with($methodName, '__');
            $methods[$methodName] = $this->method($method, count($methods), $state['properties']);
        }

        $constructor = $class->getMethod(self::CONSTRUCTOR);
        $empty = ($constructor?->stmts ?? []) === [] && ($constructor?->params ?? []) === [];

        return [
            'name'             => $name, 'line' => $class->getStartLine(), 'eligible' => $eligible && $state['eligible'], 'final' => $class->isFinal(),
            'numericFields'    => $state['numericFields'],
            'construction'     => $this->construction($class, $name),
            'constructorEmpty' => $empty, 'properties' => $state['properties'], 'propertyContracts' => $state['contracts'], 'methods' => $methods,
        ];
    }

    /**
     * Exact local construction excludes substitution, inherited signatures and composed constructors.
     */
    private function construction(Stmt\Class_ $class, string $name): ?array
    {
        $constructor = $class->getMethod(self::CONSTRUCTOR);
        $isDirect = $class->isFinal() && $class->extends === null && $class->getTraitUses() === [];
        $isPublic = $constructor !== null && $constructor->isPublic();
        if (! $isDirect || ! $isPublic || $constructor === null) {
            return null;
        }

        $dependencies = [];
        foreach ($constructor->params as $parameter) {
            $dependency = $this->constructionParameter($parameter, $name);
            if ($dependency !== null) {
                $dependencies[] = $dependency;
            }
        }

        return $dependencies;
    }

    /**
     * Optional, polymorphic and reference inputs do not force one concrete object to exist first.
     */
    private function constructionParameter(Param $parameter, string $owner): ?array
    {
        $isRequired = $parameter->default === null && ! $parameter->variadic && ! $parameter->byRef;
        if (! $isRequired || ! $parameter->type instanceof Name) {
            return null;
        }

        if (! $parameter->var instanceof Expr\Variable || ! is_string($parameter->var->name)) {
            return null;
        }

        $target = $parameter->type->isSpecialClassName() ? strtolower($owner) : $parameter->type->toLowerString();

        return ['target' => $target, 'parameter' => $parameter->var->name, 'line' => $parameter->getStartLine()];
    }

    /**
     * Retain field types and defaults so identical bodies over different state do not merge.
     */
    private function ownerState(Stmt\Class_ $class): array
    {
        $properties = [];
        $contracts = [];
        $eligible = true;
        foreach ($class->getProperties() as $property) {
            $eligible = $eligible && ! $property->isStatic() && $property->hooks === [];
            foreach ($property->props as $item) {
                $name = $item->name->toString();
                $variables = [];
                $properties[$name] = $property->isPublic();
                $contracts[$name] = $this->syntax([$property->type, $item->default, $property->flags], $variables);
            }
        }

        foreach ($class->getMethod(self::CONSTRUCTOR)?->params ?? [] as $parameter) {
            if ($parameter->flags === 0 || ! $parameter->var instanceof Expr\Variable || ! is_string($parameter->var->name)) {
                continue;
            }

            $variables = [];
            $properties[$parameter->var->name] = ($parameter->flags & Stmt\Class_::MODIFIER_PUBLIC) !== 0;
            $contracts[$parameter->var->name] = $this->syntax([$parameter->type, $parameter->default, $parameter->flags], $variables);
            $eligible = $eligible && $parameter->hooks === [];
        }

        return ['properties' => $properties, 'contracts' => $contracts, 'eligible' => $eligible, 'numericFields' => $this->numericFields($class)];
    }

    /**
     * Keep mutable numeric storage separate from the general state contracts.
     */
    private function numericFields(Stmt\Class_ $class): array
    {
        if ($class->isReadonly()) {
            return [];
        }

        $fields = $this->promotedNumericFields($class->getMethod(self::CONSTRUCTOR)?->params ?? []);
        foreach ($class->getProperties() as $property) {
            $numeric = $this->numericType($property->type);
            if ($property->isReadonly() || $numeric === null) {
                continue;
            }

            foreach ($property->props as $item) {
                $fields[$item->name->toString()] = true;
            }
        }

        return $fields;
    }

    /**
     * Recognize promoted fields without treating ordinary constructor inputs as storage.
     */
    private function promotedNumericFields(array $parameters): array
    {
        $fields = [];
        foreach ($parameters as $parameter) {
            $mutable = $parameter->flags !== 0 && ($parameter->flags & Stmt\Class_::MODIFIER_READONLY) === 0;
            $numeric = $this->numericType($parameter->type);
            $named = $parameter->var instanceof Expr\Variable && is_string($parameter->var->name);
            if ($mutable && $named && $numeric !== null) {
                $fields[$parameter->var->name] = true;
            }
        }

        return $fields;
    }
}
