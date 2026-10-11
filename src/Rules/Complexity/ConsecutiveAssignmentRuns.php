<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Measures consecutive initial assignments in the direct body of one callable.
 *
 * @internal
 */
final class ConsecutiveAssignmentRuns
{
    /**
     * Collect maximal runs without counting input bindings or previously encountered locals.
     *
     * @return list<list<array{name: string, line: int}>>
     */
    public function collect(Node\FunctionLike $callable): array
    {
        $seen = $this->inputs($callable);
        $collector = new LocalVariableCollector;
        $runs = [];
        $run = [];
        foreach ($callable->getStmts() ?? [] as $statement) {
            if ($statement instanceof Stmt\Nop) {
                continue;
            }

            $binding = $this->initialBinding($statement, $seen);
            if ($binding !== null) {
                $run[] = $binding;
            }

            if ($binding === null && $run !== []) {
                $runs[] = $run;
                $run = [];
            }

            $seen += $collector->collect($statement);
        }

        if ($run !== []) {
            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * Reserve parameters and explicit closure captures before scanning the body.
     *
     * @return array<string, true>
     */
    private function inputs(Node\FunctionLike $node): array
    {
        $names = [];
        foreach ($node->getParams() as $parameter) {
            if ($parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                $names[$parameter->var->name] = true;
            }
        }

        if ($node instanceof Expr\Closure) {
            foreach ($node->uses as $use) {
                if (is_string($use->var->name)) {
                    $names[$use->var->name] = true;
                }
            }
        }

        return $names;
    }

    /**
     * Accept only a simple first local binding whose value is not another assignment.
     *
     * @param array<string, true> $seen Previously encountered variable names.
     *
     * @return array{name: string, line: int}|null
     */
    private function initialBinding(Node $node, array $seen): ?array
    {
        $assignment = $node instanceof Stmt\Expression ? $node->expr : null;
        if (! $assignment instanceof Expr\Assign || ! $assignment->var instanceof Expr\Variable) {
            return null;
        }

        $name = $assignment->var->name;
        if (! is_string($name) || isset($seen[$name]) || AssignmentSyntax::isAssignment($assignment->expr)) {
            return null;
        }

        return ['name' => $name, 'line' => $assignment->getStartLine()];
    }
}
