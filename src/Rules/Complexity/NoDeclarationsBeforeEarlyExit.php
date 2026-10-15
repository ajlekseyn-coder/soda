<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use Cosmira\Soda\Analysis\Complexity\StatementLists;
use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;
use Cosmira\Soda\Rules\Usage\UselessVariableMutation;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
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

            if ($finder->findFirst([$scope], static fn ($node): bool => $node->getType() === 'Stmt_Goto') !== null) {
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
            foreach ($this->guards($statements) as $guard => $branch) {
                if ($guard <= $index) {
                    continue;
                }

                $firstRead = $index + 1;
                $afterGuard = $guard + 1;
                $usedBeforeGuard = $this->isUsed($statements, $firstRead, $guard, $variable, $finder);
                $between = [...array_slice($statements, $firstRead, $guard - $firstRead), $branch->cond];
                if ($usedBeforeGuard
                    || $this->doesGuardObserveJsonError($statements[$index], $branch, $finder)
                    || $this->doesInputChange($statements[$index], $between, $finder)) {
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
     * A snapshot must stay before writes to the variables used to calculate it.
     */
    private function doesInputChange(Node $declaration, array $between, NodeFinder $finder): bool
    {
        foreach ($finder->findInstanceOf([$declaration], Variable::class) as $input) {
            $name = $input->name;
            if (is_string($name) && $finder->findFirst($between, static fn ($node): bool => UselessVariableMutation::isMutationOf($node, $name)) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Moving JSON processing past its error check would inspect an earlier operation's status.
     */
    private function doesGuardObserveJsonError(Node $declaration, Node $guard, NodeFinder $finder): bool
    {
        $processesJson = $finder->findFirst([$declaration], static fn ($node): bool => $node instanceof Expr\FuncCall
            && $node->name instanceof Name && in_array(strtolower($node->name->toString()), ['json_decode', 'json_encode'], true));
        $readsError = $finder->findFirst([$guard], static fn ($node): bool => $node instanceof Expr\FuncCall
            && $node->name instanceof Name && in_array(strtolower($node->name->toString()), ['json_last_error', 'json_last_error_msg'], true));

        return $processesJson !== null && $readsError !== null;
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
    private function declaredVariable(Node $statement): ?string
    {
        if (! $statement instanceof Stmt\Expression) {
            return null;
        }

        $expression = $statement->expr;
        if (! $expression instanceof Expr\Assign || $this->hasEagerEffect($expression->expr)) {
            return null;
        }

        $target = $expression->var;
        if (! $target instanceof Variable || ! is_string($target->name)) {
            return null;
        }

        return $target->name;
    }

    /**
     * Explicit effects must also run on the exiting path; nested callable bodies are deferred.
     */
    private function hasEagerEffect(Node $node): bool
    {
        if ($node instanceof FunctionLike || $node->getType() === 'Stmt_Class') {
            return false;
        }

        if (str_starts_with($node->getType(), 'Expr_AssignOp_') || in_array($node->getType(), [
            'Expr_Assign', 'Expr_AssignRef', 'Expr_PreInc', 'Expr_PostInc', 'Expr_PreDec', 'Expr_PostDec',
            'Expr_Yield', 'Expr_YieldFrom', 'Expr_Print', 'Expr_Include', 'Expr_Eval', 'Expr_Exit', 'Expr_Throw',
        ], true)) {
            return true;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = get_object_vars($node)[$name];
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node && $this->hasEagerEffect($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Collect guard clauses whose every branch exits the enclosing path.
     *
     * @param list<Stmt> $statements
     *
     * @return array<int, Stmt\If_>
     */
    private function guards(array $statements): array
    {
        $guards = [];
        foreach ($statements as $index => $statement) {
            if ($statement instanceof Stmt\If_ && $this->isGuard($statement)) {
                $guards[$index] = $statement;
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

        $branches = [...array_map(static fn ($elseif): array => $elseif->stmts, $if->elseifs)];
        if ($if->else !== null) {
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
