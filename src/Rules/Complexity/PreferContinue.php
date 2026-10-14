<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use Cosmira\Soda\Analysis\Complexity\StatementLists;
use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Else_;
use PhpParser\NodeFinder;

/**
 * Reports loop conditions that wrap the remainder of the iteration body.
 */
final class PreferContinue extends Check
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
        return 'prefer_continue';
    }

    /**
     * Report trailing if statements whose negated guard can end the iteration early.
     *
     * @return iterable<Violation>
     */
    #[\Override]
    public function checkFile(FileFacts $facts): iterable
    {
        $loops = (new NodeFinder)->find($facts->nodes, static fn ($node): bool => $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_);
        foreach ($loops as $loop) {
            $statements = $this->body($loop);
            $last = $statements === [] ? null : $statements[count($statements) - 1];
            if (! $last instanceof Stmt\If_) {
                continue;
            }

            if ($last->else instanceof Else_ || $last->elseifs !== []) {
                continue;
            }

            if (StatementLists::doesEndWithExit($last->stmts)) {
                continue;
            }

            yield new Violation(
                rule: $this->id(),
                file: $facts->path,
                value: 1,
                threshold: 0,
                line: $last->getStartLine(),
                message: sprintf(
                    'Negate the condition at line %d and continue; keep the remaining iteration path unindented.',
                    $last->getStartLine(),
                ),
            );
        }
    }

    /**
     * Return the statement list of a known loop body.
     *
     * @return list<Stmt>
     */
    private function body(Node $loop): array
    {
        return match (true) {
            $loop instanceof Stmt\For_,
            $loop instanceof Stmt\Foreach_,
            $loop instanceof Stmt\While_,
            $loop instanceof Stmt\Do_ => $loop->stmts,
            default                   => [],
        };
    }
}
