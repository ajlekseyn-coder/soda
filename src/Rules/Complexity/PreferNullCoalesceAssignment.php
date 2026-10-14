<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\CallLike;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/**
 * Reports fallback assignments that repeat the target on both sides.
 */
final class PreferNullCoalesceAssignment extends Check
{
    /**
     * Declare an AST-only check.
     *
     * @return list<string>
     */
    #[\Override]
    public function requiredAnalyses(): array
    {
        return [];
    }

    /**
     * Return the stable rule identifier.
     */
    #[\Override]
    public function id(): string
    {
        return 'prefer_null_coalesce_assignment';
    }

    /**
     * Report `$target = $target ?? fallback` patterns.
     *
     * @return iterable<Violation>
     */
    #[\Override]
    public function checkFile(FileFacts $facts): iterable
    {
        $printer = new Standard;
        foreach ((new NodeFinder)->findInstanceOf($facts->nodes, Assign::class) as $assign) {
            if (! $assign->expr instanceof Coalesce) {
                continue;
            }

            $target = $printer->prettyPrintExpr($assign->var);
            if ($target !== $printer->prettyPrintExpr($assign->expr->left)) {
                continue;
            }

            if ($this->readsWriteState($assign)) {
                continue;
            }

            yield new Violation(
                rule: $this->id(),
                file: $facts->path,
                value: 1,
                threshold: 0,
                line: $assign->getStartLine(),
                message: sprintf('Assign the fallback with the ??= operator instead of repeating %s on both sides.', $target),
            );
        }
    }

    /**
     * Skip targets whose reevaluation is a call or another assignment.
     */
    private function readsWriteState(Assign $assign): bool
    {
        return (new NodeFinder)->findFirst([$assign->var], static fn ($node): bool => $node instanceof CallLike
            || $node instanceof Assign
            || $node instanceof Expr\AssignRef
            || $node instanceof Expr\AssignOp) instanceof \PhpParser\Node;
    }
}
