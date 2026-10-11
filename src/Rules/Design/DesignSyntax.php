<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Design;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Shares lexical traversal and structural encoding for design evidence.
 *
 * @internal
 */
trait DesignSyntax
{
    /**
     * Walk only the current lexical body, leaving nested scopes independent.
     *
     * @param list<Node> $nodes
     *
     * @return iterable<Node>
     */
    private function bodyNodes(array $nodes): iterable
    {
        foreach ($nodes as $node) {
            yield $node;
            if ($node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike) {
                continue;
            }

            yield from $this->bodyNodes($this->children($node));
        }
    }

    /**
     * Return syntax children without traversing attributes or parent pointers.
     */
    private function children(Node $node): array
    {
        $children = [];
        $properties = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $key) {
            $value = $properties[$key];
            $values = is_array($value) ? $value : [$value];
            array_push($children, ...array_filter($values, fn (mixed $child): bool => $child instanceof Node));
        }

        return $children;
    }

    /**
     * Encode structural syntax and variable roles without comments or source positions.
     */
    private function syntax(mixed $value, array &$variables): mixed
    {
        if ($value instanceof Expr\Variable && is_string($value->name)) {
            $variables[$value->name] ??= count($variables);

            return ['variable', $variables[$value->name]];
        }

        if ($value instanceof Node) {
            $parts = [$value->getType()];
            $properties = get_object_vars($value);
            foreach ($value->getSubNodeNames() as $key) {
                $parts[$key] = $this->syntax($properties[$key], $variables);
            }

            return $parts;
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $child) {
                $parts[$key] = $this->syntax($child, $variables);
            }

            return $parts;
        }

        return $value;
    }

    /**
     * Resolve only exact named parameter types; unions and dynamic types stay unknown.
     */
    private function parameterTypes(Node\FunctionLike $callable): array
    {
        $types = [];
        foreach ($callable->getParams() as $parameter) {
            if ($parameter->type instanceof Node\Name && $parameter->var instanceof Expr\Variable && is_string($parameter->var->name) && ! $parameter->byRef) {
                $types[$parameter->var->name] = strtolower($parameter->type->toString());
            }
        }

        return $types;
    }

    /**
     * Resolve a direct property access on this instance.
     */
    private function ownField(Expr $expression): ?string
    {
        $isOwn = $expression instanceof Expr\PropertyFetch && $expression->var instanceof Expr\Variable;
        if (! $isOwn || $expression->var->name !== 'this' || ! $expression->name instanceof Node\Identifier) {
            return null;
        }

        return $expression->name->toString();
    }
}
