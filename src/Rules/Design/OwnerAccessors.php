<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Design;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Proves direct getters and state-only configuration methods.
 *
 * @internal
 */
trait OwnerAccessors
{
    /**
     * Prove a numeric setter writes exactly one input to one field, optionally returning this.
     */
    private function setter(Stmt\ClassMethod $method): ?array
    {
        $input = $this->numericSetterInput($method);
        $statements = $method->stmts ?? [];
        $first = $statements[0] ?? null;
        $direct = $first instanceof Stmt\Expression && $first->expr instanceof Expr\Assign;
        if ($input === null || ! $direct || ! $this->hasValidSetterReturn($statements)) {
            return null;
        }

        $field = $this->setterField($first->expr, $input);
        if ($field === null) {
            return null;
        }

        return ['field' => $field, 'parameter' => $input];
    }

    /**
     * Require a public instance setter with one non-reference numeric input.
     */
    private function numericSetterInput(Stmt\ClassMethod $method): ?string
    {
        $publicInstance = $method->isPublic() && ! $method->isStatic() && ! $method->byRef;
        $parameters = $this->configurationParameters($method);
        if (! $publicInstance || $parameters === null || count($parameters) !== 1) {
            return null;
        }

        return $this->numericType($method->params[0]->type) === null ? null : $parameters[0];
    }

    /**
     * Preserve only a direct write of the setter's sole input.
     */
    private function setterField(Expr\Assign $assignment, string $input): ?string
    {
        $value = $assignment->expr;
        if (! $value instanceof Expr\Variable || $value->name !== $input) {
            return null;
        }

        return $this->ownField($assignment->var);
    }

    /**
     * Accept a single assignment or the same assignment followed by returning this.
     */
    private function hasValidSetterReturn(array $statements): bool
    {
        if (count($statements) === 1) {
            return true;
        }

        $second = $statements[1] ?? null;

        return count($statements) === 2 && $second instanceof Stmt\Return_
            && $second->expr instanceof Expr\Variable && $second->expr->name === 'this';
    }

    /**
     * Keep arithmetic evidence limited to explicitly numeric scalar contracts.
     */
    private function numericType(?Node $type): ?string
    {
        if ($type instanceof Node\UnionType) {
            $parts = [];
            foreach ($type->types as $member) {
                $numeric = $this->numericType($member);
                if ($numeric === null) {
                    return null;
                }

                $parts[] = $numeric;
            }

            sort($parts);

            return implode('|', $parts);
        }

        $name = $type instanceof Node\Identifier ? strtolower($type->toString()) : null;

        return in_array($name, ['int', 'float'], true) ? $name : null;
    }

    /**
     * Prove a getter is a public direct read with no arguments or additional effects.
     */
    private function getter(Stmt\ClassMethod $method): ?string
    {
        $simple = $method->isPublic() && ! $method->isStatic() && ! $method->byRef && $method->params === [];
        $statement = $method->stmts[0] ?? null;
        $singleReturn = count($method->stmts ?? []) === 1 && $statement instanceof Stmt\Return_;
        if (! $simple || ! $singleReturn || ! $statement->expr instanceof Expr) {
            return null;
        }

        return $this->ownField($statement->expr);
    }

    /**
     * Require a complete body of direct input-to-state assignments.
     */
    private function isConfiguration(Stmt\ClassMethod $method): bool
    {
        $void = $method->returnType instanceof Node\Identifier && strtolower($method->returnType->toString()) === 'void';
        $publicInstance = $method->isPublic() && ! $method->isStatic() && ! $method->byRef;
        if (! $void || ! $publicInstance || ($method->stmts ?? []) === []) {
            return false;
        }

        $parameters = $this->configurationParameters($method);
        if ($parameters === null) {
            return false;
        }

        foreach ($method->stmts as $statement) {
            if (! $this->isInputAssignment($statement, $parameters)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Exclude reference and variadic inputs from a state-only setup contract.
     */
    private function configurationParameters(Stmt\ClassMethod $method): ?array
    {
        $parameters = [];
        foreach ($method->params as $parameter) {
            $named = $parameter->var instanceof Expr\Variable && is_string($parameter->var->name);
            if (! $named || $parameter->byRef || $parameter->variadic) {
                return null;
            }

            $parameters[] = $parameter->var->name;
        }

        return $parameters;
    }

    /**
     * Recognize one direct assignment from a method input to the owner's declared state.
     */
    private function isInputAssignment(Stmt $statement, array $parameters): bool
    {
        if (! $statement instanceof Stmt\Expression || ! $statement->expr instanceof Expr\Assign) {
            return false;
        }

        $assignment = $statement->expr;
        $field = $this->ownField($assignment->var);

        if ($field === null) {
            return false;
        }

        if ($assignment->expr instanceof Expr\Variable) {
            return in_array($assignment->expr->name, $parameters, true);
        }

        return $this->isSimpleConfigurationValue($assignment->expr, 'this');
    }
}
