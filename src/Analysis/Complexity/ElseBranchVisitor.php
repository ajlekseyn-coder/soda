<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Complexity;

use Cosmira\Soda\Analysis\FactVisitor;
use PhpParser\Node;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\If_;

/**
 * Collects alternatives that follow a proven unconditional exit.
 *
 * @phpstan-type ElseBranch array{line: int, kind: 'else'|'elseif'}
 *
 * @internal
 */
final class ElseBranchVisitor extends FactVisitor
{
    /**
     * Source locations of else branches found during traversal.
     *
     * @var list<ElseBranch>
     */
    private array $occurrences = [];

    /**
     * Collect facts before traversing the children of this syntax node.
     */
    #[\Override]
    protected function doEnterNode(Node $node): void
    {
        if (! $node instanceof Else_ && ! $node instanceof ElseIf_) {
            return;
        }

        $parent = $node->getAttribute('parent');
        if (! $parent instanceof If_) {
            return;
        }

        foreach ([$parent, ...$parent->elseifs] as $branch) {
            if ($branch === $node) {
                break;
            }

            if (! StatementLists::doesEndWithExit($branch->stmts)) {
                return;
            }
        }

        $this->occurrences[] = ['line' => $node->getStartLine(), 'kind' => $node instanceof ElseIf_ ? 'elseif' : 'else'];
    }

    /**
     * Return recorded else branches with their source locations.
     *
     * @return list<ElseBranch>
     */
    public function occurrences(): array
    {
        return $this->occurrences;
    }
}
