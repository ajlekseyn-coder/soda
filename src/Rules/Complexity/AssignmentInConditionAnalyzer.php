<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;

/**
 * Finds assignments hidden inside control-flow conditions.
 *
 * @phpstan-type AssignmentCondition array{line: positive-int}
 *
 * @internal
 */
final readonly class AssignmentInConditionAnalyzer
{
    /**
     * Locates assignments embedded in condition expressions across the tree.
     */
    private NodeFinder $finder;

    /**
     * Prepare the node finder used for every subsequent analysis.
     */
    public function __construct()
    {
        $this->finder = new NodeFinder;
    }

    /**
     * Locate assignments embedded in control-flow conditions throughout the syntax tree.
     *
     * @param Node[] $nodes
     *
     * @return list<AssignmentCondition>
     */
    public function analyze(array $nodes): array
    {
        $lines = [];

        foreach ($this->conditionExpressions($nodes) as $condition) {
            foreach ($this->assignmentsIn($condition) as $assignment) {
                $line = $assignment->getStartLine();
                if ($line > 0) {
                    $lines[$line] = ['line' => $line];
                }
            }
        }

        ksort($lines, SORT_NUMERIC);

        return array_values($lines);
    }

    /**
     * Collect the expressions used as control-flow conditions.
     *
     * @param Node[] $nodes
     *
     * @return list<Node\Expr>
     */
    private function conditionExpressions(array $nodes): array
    {
        $conditions = [];

        foreach ($this->finder->find($nodes, $this->isConditionOwner(...)) as $node) {
            array_push($conditions, ...$this->conditionsFrom($node));
        }

        return $conditions;
    }

    /**
     * Determine whether condition owner applies to the supplied input.
     */
    private function isConditionOwner(Node $node): bool
    {
        if ($this->hasSingleCondition($node)) {
            return true;
        }

        if ($node instanceof For_) {
            return true;
        }

        return $node instanceof Ternary;
    }

    /**
     * Check the supplied input for single condition.
     */
    private function hasSingleCondition(Node $node): bool
    {
        return $node instanceof If_
            || $node instanceof ElseIf_
            || $node instanceof While_
            || $node instanceof Do_;
    }

    /**
     * Extract the condition expressions belonging to one control-flow node.
     *
     * @return list<Node\Expr>
     */
    private function conditionsFrom(Node $node): array
    {
        $hasCondition = $node instanceof If_ || $node instanceof ElseIf_ || $node instanceof While_ || $node instanceof Do_;
        if ($hasCondition) {
            return [$node->cond];
        }

        if ($node instanceof For_) {
            return $node->cond;
        }

        if ($node instanceof Ternary) {
            return [$node->cond];
        }

        return [];
    }

    /**
     * Find assignments nested within a condition expression.
     *
     * @return list<Assign|AssignOp>
     */
    private function assignmentsIn(Node $condition): array
    {
        if ($condition instanceof Node\FunctionLike || $condition instanceof Node\Stmt\ClassLike) {
            return [];
        }

        $assignments = $condition instanceof Assign || $condition instanceof AssignOp ? [$condition] : [];
        $properties = get_object_vars($condition);
        foreach ($condition->getSubNodeNames() as $name) {
            $value = $properties[$name];
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    array_push($assignments, ...$this->assignmentsIn($child));
                }
            }
        }

        return $assignments;
    }
}
