<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use Cosmira\Soda\Analysis\Complexity\StatementLists;
use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Else_;
use PhpParser\NodeFinder;

/**
 * Reports declarations that only feed code placed behind an early-exit guard.
 */
final class NoDeclarationsBeforeEarlyExit extends Check
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
        return 'no_declarations_before_early_exit';
    }

    /**
     * Inspect callable bodies and their nested control structures.
     *
     * @return iterable<Violation>
     */
    #[\Override]
    public function checkFile(FileFacts $facts): iterable
    {
        $finder = new NodeFinder;
        $lists = new StatementLists;
        foreach ($finder->findInstanceOf($facts->nodes, FunctionLike::class) as $scope) {
            $statements = $scope->getStmts();
            if ($statements === null || $statements === []) {
                continue;
            }

            foreach ([$statements, ...$lists->within($scope)] as $list) {
                yield from $this->declarationsBehindGuards($list, $facts, $finder);
            }
        }
    }

    /**
     * Report declarations whose only readers live after a guard clause.
     *
     * @param list<Stmt> $statements
     *
     * @return iterable<Violation>
     */
    private function declarationsBehindGuards(array $statements, FileFacts $facts, NodeFinder $finder): iterable
    {
        $last = count($statements) - 1;
        foreach ($this->declarations($statements) as $index => $variable) {
            foreach ($this->guards($statements) as $guard) {
                if ($guard <= $index) {
                    continue;
                }

                $firstRead = $index + 1;
                $afterGuard = $guard + 1;
                $usedBeforeGuard = $this->isUsed($statements, $firstRead, $guard, $variable, $finder);
                if ($usedBeforeGuard) {
                    continue;
                }

                $usedAfterGuard = $this->isUsed($statements, $afterGuard, $last, $variable, $finder);
                if (! $usedAfterGuard) {
                    continue;
                }

                yield new Violation(
                    rule: $this->id(),
                    file: $facts->path,
                    value: 1,
                    threshold: 0,
                    line: $statements[$index]->getStartLine(),
                    message: sprintf(
                        'Declare $%s after the guard at line %d; the early-exit path never reads it.',
                        $variable,
                        $statements[$guard]->getStartLine(),
                    ),
                );
                break;
            }
        }
    }

    /**
     * Map statement indexes to the plain variables they declare.
     *
     * @param list<Stmt> $statements
     *
     * @return array<int, string>
     */
    private function declarations(array $statements): array
    {
        $declared = [];
        foreach ($statements as $index => $statement) {
            $variable = $this->declaredVariable($statement);
            if ($variable !== null) {
                $declared[$index] = $variable;
            }
        }

        return $declared;
    }

    /**
     * Accept statement-level assignments to plain local variables.
     */
    private function declaredVariable(Stmt $statement): ?string
    {
        if (! $statement instanceof Stmt\Expression) {
            return null;
        }

        $expression = $statement->expr;
        if (! $expression instanceof Expr\Assign && ! $expression instanceof Expr\AssignRef) {
            return null;
        }

        $target = $expression->var;
        if (! $target instanceof Variable || ! is_string($target->name)) {
            return null;
        }

        return $target->name;
    }

    /**
     * Collect guard clauses whose every branch exits the enclosing path.
     *
     * @param list<Stmt> $statements
     *
     * @return list<int>
     */
    private function guards(array $statements): array
    {
        $guards = [];
        foreach ($statements as $index => $statement) {
            if ($statement instanceof Stmt\If_ && $this->isGuard($statement)) {
                $guards[] = $index;
            }
        }

        return $guards;
    }

    /**
     * Require the guarded branch to exit while the remaining branches, if any, do the same.
     */
    private function isGuard(Stmt\If_ $if): bool
    {
        if (! StatementLists::doesEndWithExit($if->stmts)) {
            return false;
        }

        $branches = [...array_map(static fn (Stmt\ElseIf_ $elseif): array => $elseif->stmts, $if->elseifs)];
        if ($if->else instanceof Else_) {
            $branches[] = $if->else->stmts;
        }

        foreach ($branches as $branch) {
            if (! StatementLists::doesEndWithExit($branch)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Search statement subtrees for reads of the named variable.
     *
     * @param list<Stmt> $statements
     */
    private function isUsed(array $statements, int $from, int $to, string $variable, NodeFinder $finder): bool
    {
        for ($index = $from; $index <= $to; $index++) {
            $uses = $finder->find([$statements[$index]], static fn ($node): bool => $node instanceof Variable
                && is_string($node->name)
                && $node->name === $variable);
            if ($uses !== []) {
                return true;
            }
        }

        return false;
    }
}
