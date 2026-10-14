<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Design;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;

/**
 * Records repeated arithmetic passed back to the same foreign receiver.
 *
 * @internal
 */
trait ForeignUpdateEvidence
{
    use ForeignUpdateBindings;

    /**
     * Collect standalone updates with the current stable receiver bindings.
     */
    private function updates(Stmt\ClassMethod|Stmt\Function_ $callable, string $identity): array
    {
        $context = $this->updateBindings($callable);
        $context['name'] = $identity;
        $rows = [];
        foreach ($callable->stmts ?? [] as $statement) {
            $context = $this->updateAlias($statement, $context);
            array_push($rows, ...$this->statementUpdates($statement, $context));
        }

        return $rows;
    }

    /**
     * Keep nested lexical bodies separate from the consuming method or function.
     */
    private function statementUpdates(Stmt $statement, array $context): array
    {
        $rows = [];
        foreach ($this->bodyNodes([$statement]) as $node) {
            if (! $node instanceof Stmt\Expression) {
                continue;
            }

            $call = $this->directCall($node);
            if ($call === null) {
                continue;
            }

            $row = $this->foreignUpdate($call, $context);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Record only a direct one-argument update with arithmetic over its own receiver.
     */
    private function foreignUpdate(array $invocation, array $context): ?array
    {
        $call = $invocation['call'];
        if (count($call->args) !== 1) {
            return null;
        }

        $bindings = $context['bindings'];
        $binding = $bindings[$invocation['receiver']] ?? null;
        $argument = $call->args[0];
        if ($binding === null || ! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack) {
            return null;
        }

        $evidence = ['getters' => [], 'inputs' => [], 'calculation' => false];
        $context['receiver'] = $binding['identity'];
        $key = $this->updateExpression($argument->value, $context, $evidence);
        $substantive = $evidence['calculation'] && $evidence['getters'] !== [];
        if ($key === null || ! $substantive) {
            return null;
        }

        return [
            'name'       => $context['name'], 'line' => $call->getStartLine(), 'type' => $binding['type'],
            'setter'     => $call->name->toLowerString(), 'argument' => $argument->name?->toString(),
            'getters'    => array_values(array_unique($evidence['getters'])), 'key' => hash('sha256', serialize($key)),
            'expression' => (new Standard)->prettyPrintExpr($argument->value),
        ];
    }

    /**
     * Encode numeric operations, preserving order, input sharing, contracts, and literals.
     */
    private function updateExpression(Expr $expression, array $context, array &$evidence): ?array
    {
        if ($expression instanceof Expr\MethodCall) {
            return $this->updateRead($expression, $context, $evidence);
        }

        $leaf = $this->numericUpdateLeaf($expression, $context['numeric'], $evidence);
        if ($leaf !== null) {
            return $leaf;
        }

        return $this->updateCalculation($expression, $context, $evidence);
    }

    /**
     * Preserve numeric literals and the identity of repeated scalar inputs.
     */
    private function numericUpdateLeaf(Expr $expression, array $numeric, array &$evidence): ?array
    {
        $kind = $expression->getType();
        if (in_array($kind, ['Scalar_Int', 'Scalar_Float'], true)) {
            return $this->foreignLiteral($expression);
        }

        if (! $expression instanceof Expr\Variable || ! is_string($expression->name) || ! isset($numeric[$expression->name])) {
            return null;
        }

        $inputs = $evidence['inputs'];
        $inputs[$expression->name] ??= count($inputs);
        $evidence['inputs'] = $inputs;

        return ['input', $inputs[$expression->name], $numeric[$expression->name]];
    }

    /**
     * Preserve operator and operand order without algebraic simplification.
     */
    private function updateCalculation(Expr $expression, array $context, array &$evidence): ?array
    {
        if ($expression instanceof Expr\UnaryMinus || $expression instanceof Expr\UnaryPlus) {
            $evidence['calculation'] = true;
            $child = $this->updateExpression($expression->expr, $context, $evidence);

            return $child === null ? null : [$expression->getType(), $child];
        }

        $supported = in_array($expression->getType(), [
            'Expr_BinaryOp_Plus', 'Expr_BinaryOp_Minus', 'Expr_BinaryOp_Mul',
            'Expr_BinaryOp_Div', 'Expr_BinaryOp_Mod', 'Expr_BinaryOp_Pow',
        ], true);
        if (! $supported || ! $expression instanceof Expr\BinaryOp) {
            return null;
        }

        $evidence['calculation'] = true;
        $left = $this->updateExpression($expression->left, $context, $evidence);
        $right = $this->updateExpression($expression->right, $context, $evidence);

        return $left === null || $right === null ? null : [$expression->getType(), $left, $right];
    }

    /**
     * Require a zero-argument getter on the same proven receiver or direct alias.
     */
    private function updateRead(Expr\MethodCall $call, array $context, array &$evidence): ?array
    {
        if (! $call->var instanceof Expr\Variable || ! is_string($call->var->name)
            || ! $call->name instanceof Node\Identifier || $call->args !== []) {
            return null;
        }

        $bindings = $context['bindings'];
        $binding = $bindings[$call->var->name] ?? null;
        if ($binding === null || $binding['identity'] !== $context['receiver']) {
            return null;
        }

        $getters = $evidence['getters'];
        $getters[] = $call->name->toLowerString();
        $evidence['getters'] = $getters;

        return ['state'];
    }
}
