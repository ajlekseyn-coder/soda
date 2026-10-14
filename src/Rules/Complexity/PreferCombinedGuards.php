<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use Cosmira\Soda\Analysis\Complexity\StatementLists;
use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/**
 * Reports consecutive guard clauses that exit with the same statement.
 */
final class PreferCombinedGuards extends Check
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
        return 'prefer_combined_guards';
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
            if ($statements === []) {
                continue;
            }

            foreach ([$statements, ...$lists->within($scope)] as $list) {
                yield from $this->combinablePairs($list, $facts);
            }
        }
    }

    /**
     * Report each pair of adjacent guards with an identical single exit.
     *
     * @param list<Stmt> $statements
     *
     * @return iterable<Violation>
     */
    private function combinablePairs(array $statements, FileFacts $facts): iterable
    {
        $printer = new Standard;
        for ($index = 0; $index < count($statements) - 1; $index++) {
            $left = $this->exitKey($statements[$index], $printer);
            $right = $this->exitKey($statements[$index + 1], $printer);
            if ($left === null || $left !== $right) {
                continue;
            }

            yield new Violation(
                rule: $this->id(),
                file: $facts->path,
                value: 1,
                threshold: 0,
                line: $statements[$index + 1]->getStartLine(),
                message: sprintf(
                    'Combine the consecutive guards at lines %d and %d with ||; condition evaluation order is preserved.',
                    $statements[$index]->getStartLine(),
                    $statements[$index + 1]->getStartLine(),
                ),
            );
        }
    }

    /**
     * Key guards by their single return, throw, break, or continue statement.
     *
     * @return non-empty-string|null
     */
    private function exitKey(Stmt $statement, Standard $printer): ?string
    {
        if (! $statement instanceof Stmt\If_ || $statement->else instanceof \PhpParser\Node\Stmt\Else_ || $statement->elseifs !== []) {
            return null;
        }

        if (count($statement->stmts) !== 1 || ! StatementLists::isExit($statement->stmts[0])) {
            return null;
        }

        $exit = $statement->stmts[0] instanceof Stmt\Expression ? $statement->stmts[0]->expr : $statement->stmts[0];
        $kind = match (true) {
            $exit instanceof Stmt\Return_   => 'return',
            $exit instanceof Expr\Throw_    => 'throw',
            $exit instanceof Stmt\Continue_ => 'continue',
            $exit instanceof Stmt\Break_    => 'break',
            default                         => null,
        };
        if ($kind === null) {
            return null;
        }

        $value = match ($kind) {
            'return', 'throw' => $exit->expr === null ? '(none)' : $printer->prettyPrintExpr($exit->expr),
            default           => $exit->num === null ? '(none)' : $printer->prettyPrintExpr($exit->num),
        };

        if ($this->writesState($exit)) {
            return null;
        }

        return $kind.':'.$value;
    }

    /**
     * Skip exits whose value expressions assign while returning or throwing.
     */
    private function writesState(Stmt|Expr $exit): bool
    {
        $expression = match (true) {
            $exit instanceof Stmt\Return_, $exit instanceof Expr\Throw_   => $exit->expr,
            $exit instanceof Stmt\Break_, $exit instanceof Stmt\Continue_ => $exit->num,
            default                                                       => null,
        };

        if (!$expression instanceof \PhpParser\Node\Expr) {
            return false;
        }

        return (new NodeFinder)->findFirst([$expression], static fn (Node $node): bool => $node instanceof Expr\Assign
            || $node instanceof Expr\AssignRef
            || $node instanceof Expr\AssignOp) instanceof Node;
    }
}
