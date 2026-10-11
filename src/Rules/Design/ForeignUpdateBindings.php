<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Design;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Tracks stable inputs and receiver aliases for external state arithmetic.
 *
 * @internal
 */
trait ForeignUpdateBindings
{
    /**
     * Keep numeric inputs and owner identities separate from mutable local bindings.
     */
    private function updateBindings(Stmt\ClassMethod|Stmt\Function_ $callable): array
    {
        $assignments = $this->updateAssignments($callable->stmts ?? []);
        $bindings = [];
        foreach (array_diff_key($this->parameterTypes($callable), $assignments) as $name => $type) {
            $bindings[$name] = ['type' => $type, 'identity' => $name];
        }

        return ['bindings' => $bindings, 'numeric' => $this->numericUpdateInputs($callable->params, $assignments), 'assignments' => $assignments];
    }

    /**
     * Accept explicitly numeric, non-reference, stable parameters only.
     */
    private function numericUpdateInputs(array $parameters, array $assignments): array
    {
        $numeric = [];
        foreach ($parameters as $parameter) {
            $name = $parameter->var instanceof Expr\Variable ? $parameter->var->name : null;
            $type = $this->numericType($parameter->type);
            if (! is_string($name) || $type === null) {
                continue;
            }

            $stable = ! $parameter->byRef && ! $parameter->variadic && ! isset($assignments[$name]);
            if ($stable) {
                $numeric[$name] = $type;
            }
        }

        return $numeric;
    }

    /**
     * Establish an alias only when its sole assignment precedes the update.
     */
    private function updateAlias(Stmt $statement, array $context): array
    {
        $alias = $this->localAssignment($statement);
        $value = $alias['value'] ?? null;
        if (! $value instanceof Expr\Variable || ! is_string($value->name)) {
            return $context;
        }

        $bindings = $context['bindings'];
        $assignments = $context['assignments'];
        $source = $bindings[$value->name] ?? null;
        $target = $alias['receiver'];
        if ($source !== null && ($assignments[$target] ?? 0) === 1) {
            $bindings[$target] = $source;
            $context['bindings'] = $bindings;
        }

        return $context;
    }

    /**
     * Include implicit assignments and escapes that invalidate stable parameter identities.
     */
    private function updateAssignments(array $statements): array
    {
        $assignments = $this->assignedVariables($statements);
        foreach ($this->bodyNodes($statements) as $node) {
            foreach ($this->updateBindingTargets($node) as $target) {
                if ($target instanceof Node) {
                    $assignments = $this->invalidateUpdateTarget($target, $assignments);
                }
            }
        }

        return $assignments;
    }

    /**
     * Record every variable contained in a destructured or reference target.
     */
    private function invalidateUpdateTarget(Node $target, array $assignments): array
    {
        foreach ($this->bodyNodes([$target]) as $part) {
            if ($part instanceof Expr\Variable && is_string($part->name)) {
                $assignments[$part->name] = 2;
            }
        }

        return $assignments;
    }

    /**
     * Exclude reference escapes and implicit loop/catch bindings from receiver evidence.
     */
    private function updateBindingTargets(Node $node): array
    {
        $directArgument = $node instanceof Node\Arg && $node->value instanceof Expr\Variable;
        if ($directArgument) {
            return [$node->value];
        }

        return match (true) {
            $node instanceof Expr\AssignRef => [$node->var, $node->expr],
            $node instanceof Stmt\Foreach_  => [$node->valueVar, $node->keyVar],
            $node instanceof Stmt\Catch_    => [$node->var],
            $node instanceof Stmt\Unset_    => $node->vars,
            $node instanceof Expr\Closure   => $this->updateReferenceCaptures($node),
            default                         => $this->updateDestructuring($node),
        };
    }

    /**
     * A closure may rebind captured variables even though its body is a separate scope.
     */
    private function updateReferenceCaptures(Expr\Closure $closure): array
    {
        $targets = [];
        foreach ($closure->uses as $use) {
            if ($use->byRef) {
                $targets[] = $use->var;
            }
        }

        return $targets;
    }

    /**
     * Destructuring assigns local bindings without a direct variable assignment node.
     */
    private function updateDestructuring(Node $node): array
    {
        if (! $node instanceof Expr\Assign) {
            return [];
        }

        $destructured = $node->var instanceof Expr\List_ || $node->var instanceof Expr\Array_;

        return $destructured ? [$node->var] : [];
    }
}
