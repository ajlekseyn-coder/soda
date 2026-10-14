<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use Cosmira\Soda\Analysis\Complexity\StatementLists;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\PrettyPrinter\Standard;

/**
 * Keys guards by their single return, throw, break, or continue statement.
 *
 * @internal
 */
final readonly class GuardExitKey
{
    /**
     * Placeholder for exits that carry no value.
     */
    private const NO_VALUE = '(none)';

    /**
     * Initialize the pretty printer used to compare exit values.
     */
    public function __construct(
        /**
         * Printer rendering exit values for key comparison.
         */
        private Standard $printer,
    ) {}

    /**
     * Return the key when the statement is a single-exit guard, otherwise null.
     */
    public function of(Stmt $statement): ?string
    {
        $exit = $this->exit($statement);
        if ($exit === null) {
            return null;
        }

        $kind = $this->kind($exit);
        if ($kind === null || $this->doesWriteState($exit)) {
            return null;
        }

        return $kind.':'.$this->value($exit);
    }

    /**
     * Return the exit expression when the statement is a guard without alternative branches.
     */
    private function exit(Stmt $statement): Stmt|Expr|null
    {
        if (! $statement instanceof If_ || $statement->else instanceof Else_ || $statement->elseifs !== []) {
            return null;
        }

        if (count($statement->stmts) !== 1 || ! StatementLists::isExit($statement->stmts[0])) {
            return null;
        }

        $only = $statement->stmts[0];

        return $only instanceof Expression ? $only->expr : $only;
    }

    /**
     * Classify the exit by the statement kind it leaves the path with.
     */
    private function kind(Stmt|Expr $exit): ?string
    {
        return match (true) {
            $exit instanceof Return_     => 'return',
            $exit instanceof Expr\Throw_ => 'throw',
            $exit instanceof Continue_   => 'continue',
            $exit instanceof Break_      => 'break',
            default                      => null,
        };
    }

    /**
     * Render the carried value of the exit for comparison.
     */
    private function value(Stmt|Expr $exit): string
    {
        if ($exit instanceof Return_ || $exit instanceof Expr\Throw_) {
            return $exit->expr instanceof Expr ? $this->printer->prettyPrintExpr($exit->expr) : self::NO_VALUE;
        }

        if ($exit instanceof Continue_ || $exit instanceof Break_) {
            return $exit->num instanceof Expr ? $this->printer->prettyPrintExpr($exit->num) : self::NO_VALUE;
        }

        return self::NO_VALUE;
    }

    /**
     * Skip exits whose value expressions assign while returning or throwing.
     */
    private function doesWriteState(Stmt|Expr $exit): bool
    {
        $expression = match (true) {
            $exit instanceof Return_, $exit instanceof Expr\Throw_   => $exit->expr,
            $exit instanceof Break_, $exit instanceof Continue_      => $exit->num,
            default                                                  => null,
        };

        if (! $expression instanceof Expr) {
            return false;
        }

        return StateWriteSearch::hasStateWrite($expression);
    }
}
