<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * @internal
 */
final class ConditionRecorder
{
    /**
     * Boolean-condition measurements indexed by callable name.
     *
     * @var array<string, list<array{line: int, count: int}>>
     */
    private array $conditionsByMethod = [];

    /**
     * Start a separate condition list for this callable.
     */
    public function openMethod(string $method): void
    {
        $this->conditionsByMethod[$method] = [];
    }

    /**
     * Record the operand count and source line of a condition in its callable.
     */
    public function record(Node $node, ?string $method): void
    {
        if ($method === null) {
            return;
        }

        $cond = ConditionExtractor::extract($node);
        if ($cond instanceof Expr) {
            $count = $this->operandCount($cond);
            if ($count > 0) {
                $key = $method;
                $list = $this->conditionsByMethod[$key] ?? [];
                $list[] = [
                    'line'  => $cond->getStartLine(),
                    'count' => $count,
                ];
                $this->conditionsByMethod[$key] = $list;
            }
        }
    }

    /**
     * Boolean chains sum their operands; negations and other expressions remain one operand.
     */
    private function operandCount(Expr $expression): int
    {
        return match (true) {
            $expression instanceof Expr\BinaryOp\LogicalAnd,
            $expression instanceof Expr\BinaryOp\LogicalOr,
            $expression instanceof Expr\BinaryOp\LogicalXor,
            $expression instanceof Expr\BinaryOp\BooleanAnd,
            $expression instanceof Expr\BinaryOp\BooleanOr => $this->operandCount($expression->left) + $this->operandCount($expression->right),
            default                                        => 1,
        };
    }

    /**
     * Return the recorded boolean-condition locations and operand counts.
     *
     * @psalm-return array<string, list<array{line: int, count: int}>>
     */
    public function conditionsByMethod(): array
    {
        return $this->conditionsByMethod;
    }
}
