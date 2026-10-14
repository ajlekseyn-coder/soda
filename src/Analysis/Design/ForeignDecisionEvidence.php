<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Design;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Records compound questions about one exactly typed foreign receiver.
 *
 * @internal
 */
trait ForeignDecisionEvidence
{
    /**
     * Preserve preceding single-assignment aliases and keep each consumer independent.
     */
    private function decisions(Stmt\ClassMethod|Stmt\Function_ $callable, string $identity): array
    {
        $assignments = $this->assignedVariables($callable->stmts ?? []);
        $types = array_diff_key($this->parameterTypes($callable), $assignments);
        $rows = [];
        foreach ($callable->stmts ?? [] as $statement) {
            $types = $this->decisionAliases($statement, $types, $assignments);
            foreach ($this->bodyNodes([$statement]) as $node) {
                $expression = $this->decisionExpression($node);
                if ($expression === null) {
                    continue;
                }

                $row = $this->foreignDecision($expression, $types, $identity);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Detect local reassignment and mutation without attributing nested scope writes to the caller.
     */
    private function assignedVariables(array $statements): array
    {
        $assignments = [];
        foreach ($this->bodyNodes($statements) as $node) {
            $isAssignment = in_array($node->getType(), ['Expr_Assign', 'Expr_AssignRef', 'Expr_PreInc', 'Expr_PostInc', 'Expr_PreDec', 'Expr_PostDec'], true)
                || $node instanceof Expr\AssignOp;
            if (! $isAssignment) {
                continue;
            }

            $parts = get_object_vars($node);
            $target = $parts['var'];
            if ($target instanceof Expr\Variable && is_string($target->name)) {
                $assignments[$target->name] = ($assignments[$target->name] ?? 0) + 1;
            }
        }

        return $assignments;
    }

    /**
     * Resolve a direct alias only when its sole assignment precedes the decision.
     */
    private function decisionAliases(Stmt $statement, array $types, array $assignments): array
    {
        $assignment = $this->localAssignment($statement);
        $value = $assignment['value'] ?? null;
        if (! $value instanceof Expr\Variable || ! is_string($value->name)) {
            return $types;
        }

        $target = $assignment['receiver'];
        $source = $types[$value->name] ?? null;
        if ($source !== null && ($assignments[$target] ?? 0) === 1) {
            $types[$target] = $source;
        }

        return $types;
    }

    /**
     * Record only supported compound expressions reading one known foreign receiver.
     */
    private function foreignDecision(Expr $expression, array $types, string $identity): ?array
    {
        $receivers = [];
        $accesses = [];
        $key = $this->foreignExpression($expression, $types, $receivers, $accesses);
        if ($key === null || count($receivers) !== 1 || ! $this->isCompound($expression)) {
            return null;
        }

        return ['name' => $identity, 'line' => $expression->getStartLine(), 'type' => reset($receivers), 'key' => hash('sha256', serialize($key)), 'accesses' => $accesses];
    }

    /**
     * Inspect decision positions and predicate results rather than arbitrary calculations.
     */
    private function decisionExpression(Node $node): ?Expr
    {
        $parts = get_object_vars($node);
        $condition = in_array($node->getType(), ['Stmt_If', 'Stmt_ElseIf', 'Stmt_While', 'Stmt_Do', 'Expr_Ternary'], true);
        $expression = $condition ? $parts['cond'] : null;
        if ($node->getType() === 'Stmt_Return') {
            $expression = $parts['expr'];
        }

        return $expression instanceof Expr ? $expression : null;
    }

    /**
     * Encode a closed expression vocabulary while preserving operand order and scalar types.
     */
    private function foreignExpression(Expr $expression, array $types, array &$receivers, array &$accesses): ?array
    {
        if ($expression instanceof Expr\PropertyFetch || $expression instanceof Expr\MethodCall) {
            return $this->foreignLeaf($expression, $types, $receivers, $accesses);
        }

        $literal = $this->foreignLiteral($expression);
        if ($literal !== null) {
            return $literal;
        }

        return $this->foreignComposite($expression, $types, $receivers, $accesses);
    }

    /**
     * Retain literals and exact named constants without evaluating application code.
     */
    private function foreignLiteral(Expr $expression): ?array
    {
        $parts = get_object_vars($expression);
        $kind = $expression->getType();
        if (in_array($kind, ['Scalar_Int', 'Scalar_Float', 'Scalar_String'], true)) {
            return [$expression->getType(), $parts['value']];
        }

        if ($expression instanceof Expr\ConstFetch) {
            $name = strtolower($expression->name->toString());

            return in_array($name, ['true', 'false', 'null'], true) ? ['literal', $name] : null;
        }

        return $expression instanceof Expr\ClassConstFetch ? $this->foreignConstant($expression) : null;
    }

    /**
     * Relative constant bindings and dynamic names are outside the fingerprint contract.
     */
    private function foreignConstant(Expr\ClassConstFetch $expression): ?array
    {
        if (! $expression->class instanceof Node\Name || ! $expression->name instanceof Node\Identifier) {
            return null;
        }

        $owner = strtolower($expression->class->toString());

        return in_array($owner, ['self', 'static', 'parent'], true) ? null : ['constant', $owner, $expression->name->toString()];
    }

    /**
     * Allow negation and boolean/strict comparison operators, leaving computed locals unknown.
     */
    private function foreignComposite(Expr $expression, array $types, array &$receivers, array &$accesses): ?array
    {
        if ($expression instanceof Expr\BooleanNot) {
            $child = $this->foreignExpression($expression->expr, $types, $receivers, $accesses);

            return $child === null ? null : ['not', $child];
        }

        $supported = in_array($expression->getType(), [
            'Expr_BinaryOp_BooleanAnd', 'Expr_BinaryOp_BooleanOr', 'Expr_BinaryOp_Identical', 'Expr_BinaryOp_NotIdentical',
            'Expr_BinaryOp_Greater', 'Expr_BinaryOp_GreaterOrEqual', 'Expr_BinaryOp_Smaller', 'Expr_BinaryOp_SmallerOrEqual',
        ], true);
        if (! $supported || ! $expression instanceof Expr\BinaryOp) {
            return null;
        }

        $left = $this->foreignExpression($expression->left, $types, $receivers, $accesses);
        $right = $this->foreignExpression($expression->right, $types, $receivers, $accesses);

        return $left === null || $right === null ? null : [$expression->getType(), $left, $right];
    }

    /**
     * Only zero-argument reads of exact typed parameters become project-resolution candidates.
     */
    private function foreignLeaf(Expr\PropertyFetch|Expr\MethodCall $expression, array $types, array &$receivers, array &$accesses): ?array
    {
        $named = $expression->var instanceof Expr\Variable && is_string($expression->var->name) && $expression->name instanceof Node\Identifier;
        if (! $named) {
            return null;
        }

        $receiver = $expression->var->name;
        $hasArguments = $expression instanceof Expr\MethodCall && $expression->args !== [];
        if (! isset($types[$receiver]) || $hasArguments) {
            return null;
        }

        $receivers[$receiver] = $types[$receiver];
        $kind = $expression instanceof Expr\MethodCall ? 'method' : 'property';
        $name = $kind === 'method' ? $expression->name->toLowerString() : $expression->name->toString();
        $access = [$kind, $name];
        $accesses[] = $access;

        return ['owner', ...$access];
    }

    /**
     * Require a boolean combination within the supported question.
     */
    private function isCompound(Expr $expression): bool
    {
        $kind = $expression->getType();
        if (in_array($kind, ['Expr_BinaryOp_BooleanAnd', 'Expr_BinaryOp_BooleanOr'], true)) {
            return true;
        }

        return $expression instanceof Expr\BooleanNot && $this->isCompound($expression->expr);
    }
}
