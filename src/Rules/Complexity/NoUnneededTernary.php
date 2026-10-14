<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/**
 * Reports full ternaries selecting opposite boolean literals without guessing condition types.
 */
final class NoUnneededTernary extends Check
{
    /**
     * Identify the boolean-result simplification policy.
     */
    public function id(): string
    {
        return 'no_unneeded_ternary';
    }

    /**
     * Inspect parsed expressions without requesting type or metric collectors.
     *
     * @return list<string>
     */
    public function requiredAnalyses(): array
    {
        return [];
    }

    /**
     * Report opposite literal results with advice that preserves boolean conversion.
     */
    public function checkFile(FileFacts $file): iterable
    {
        foreach ((new NodeFinder)->findInstanceOf($file->nodes, Expr\Ternary::class) as $node) {
            $positive = $this->tryBooleanLiteral($node->if);
            $negative = $this->tryBooleanLiteral($node->else);
            if ($positive === null || $negative === null || $positive === $negative) {
                continue;
            }

            $replacement = $positive ? '(bool) (condition)' : '! (condition)';
            yield new Violation(
                rule: $this->id(), file: $file->path, value: 1, threshold: 0,
                line: $node->getStartLine(),
                message: 'Unneeded boolean ternary. Use '.$replacement.'; remove the cast only when the condition is known to return bool. Evaluate the condition once.',
            );
        }
    }

    /**
     * Accept PHP boolean literals only, including case-insensitive spellings.
     */
    private function tryBooleanLiteral(?Expr $node): ?bool
    {
        if (! $node instanceof Expr\ConstFetch) {
            return null;
        }

        return match (strtolower($node->name->toString())) {
            'true'  => true,
            'false' => false,
            default => null,
        };
    }
}
