<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Complexity;

use Cosmira\Soda\Analysis\ConditionExtractor;
use Cosmira\Soda\Analysis\FactVisitor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpToken;

/**
 * Collects mixed logical families whose precedence is not made explicit in source.
 *
 * @phpstan-type ComplexCondition array{line: int, reason: 'mixed_logic'}
 */
final class ComplexControlConditionVisitor extends FactVisitor
{
    /**
     * Source locations of conditions with ungrouped logical families.
     *
     * @var list<ComplexCondition>
     */
    private array $occurrences = [];

    /**
     * Significant token immediately before each expression start.
     *
     * @var array<int, string>
     */
    private array $before = [];

    /**
     * Significant token immediately after each expression end.
     *
     * @var array<int, string>
     */
    private array $after = [];

    /**
     * Parentheses are source syntax; the parser's AST omits them.
     */
    public function __construct(string $source = '')
    {
        $previous = null;
        foreach (PhpToken::tokenize($source) as $token) {
            if ($token->isIgnorable()) {
                continue;
            }

            if ($previous !== null) {
                $this->before[$token->pos] = $previous->text;
                $this->after[$previous->pos + strlen($previous->text) - 1] = $token->text;
            }

            $previous = $token;
        }
    }

    /**
     * Visit callable bodies independently of their enclosing predicate.
     */
    #[\Override]
    protected function doEnterNode(Node $node): void
    {
        $conditions = $node instanceof Node\Stmt\For_ ? $node->cond : [ConditionExtractor::extract($node)];
        foreach ($conditions as $condition) {
            if ($condition instanceof Expr && $this->hasUngroupedLogic($condition)) {
                $this->occurrences[] = ['line' => $condition->getStartLine(), 'reason' => 'mixed_logic'];
            }
        }
    }

    /**
     * Return recorded conditions with their source locations.
     *
     * @return list<ComplexCondition>
     */
    public function occurrences(): array
    {
        return $this->occurrences;
    }

    /**
     * Calls and other value expressions are atoms, regardless of their call depth.
     */
    private function hasUngroupedLogic(Expr $node): bool
    {
        $subject = ConditionValueGrammar::unwrap($node);
        $operator = $this->logicalOperator($subject);
        if ($operator === null || ! $subject instanceof Expr\BinaryOp) {
            return false;
        }

        foreach ([$subject->left, $subject->right] as $child) {
            $value = ConditionValueGrammar::unwrap($child);
            $childOperator = $this->logicalOperator($value);
            if ($childOperator !== null && $childOperator !== $operator && $this->isUngrouped($child)) {
                return true;
            }

            if ($this->hasUngroupedLogic($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Missing source positions cannot prove the absence of explicit grouping.
     */
    private function isUngrouped(Expr $node): bool
    {
        $before = $this->before[$node->getStartFilePos()] ?? null;
        $after = $this->after[$node->getEndFilePos()] ?? null;

        if ($before === null || $after === null || ($before === '(' && $after === ')')) {
            return false;
        }

        if (ConditionValueGrammar::unwrap($node) === $node) {
            return true;
        }

        $child = get_object_vars($node)['expr'] ?? null;

        return $child instanceof Expr && $this->isUngrouped($child);
    }

    /**
     * Word and symbolic forms share a logical family after PHP parses precedence.
     */
    private function logicalOperator(Expr $node): ?string
    {
        return match ($node->getType()) {
            'Expr_BinaryOp_BooleanAnd', 'Expr_BinaryOp_LogicalAnd' => 'and',
            'Expr_BinaryOp_BooleanOr', 'Expr_BinaryOp_LogicalOr'   => 'or',
            'Expr_BinaryOp_LogicalXor'                             => 'xor',
            default                                                => null,
        };
    }
}
