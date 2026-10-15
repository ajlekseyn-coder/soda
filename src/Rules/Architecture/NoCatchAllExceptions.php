<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Architecture;

use Cosmira\Soda\Analysis\CallableIdentity;
use Cosmira\Soda\Analysis\Complexity\StatementLists;
use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

final class NoCatchAllExceptions extends Check
{
    /**
     * Preserve declared cleanup contracts that deliberately retain an earlier failure.
     *
     * @param list<string> $contracts Exact qualified Class::method identities; masks and inherited overrides do not match.
     */
    public function __construct(private readonly array $contracts = []) {}

    /**
     * Report each matching PHP construct at its original source position.
     *
     * @return iterable<Violation>
     */
    #[\Override]
    public function checkFile(FileFacts $file): iterable
    {
        $contracts = array_fill_keys(array_map(strtolower(...), $this->contracts), true);
        $nodes = (new NodeFinder)->find($file->nodes, fn (Node $node): bool => $node instanceof Stmt\Catch_ && $this->isCatchAll($node) && ! $this->hasExplicitOutcome($node->stmts));

        foreach ($nodes as $node) {
            $identity = CallableIdentity::enclosingMethod($node);
            $key = $identity !== null ? strtolower($identity) : null;
            if ($key !== null && isset($contracts[$key])) {
                continue;
            }

            yield new Violation(
                rule: $this->id(),
                file: $file->path,
                value: 1,
                threshold: 0,
                line: $node->getStartLine(),
                message: 'A broad catch continues without returning a value or throwing. Make the failure outcome explicit.',
            );
        }
    }

    /**
     * Determine whether catch all applies to the supplied input.
     */
    private function isCatchAll(Node $catch): bool
    {
        $isApplicable = $catch instanceof Stmt\Catch_;
        if (! $isApplicable) {
            return false;
        }

        foreach ($catch->types as $type) {
            $isBroadCatch = in_array($type->getLast(), ['Throwable', 'Exception'], true);
            if ($isBroadCatch) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recognize catch bodies that explicitly return, throw, or otherwise handle failure.
     *
     * @param list<Stmt> $statements Statements in the current catch scope.
     */
    private function hasExplicitOutcome(array $statements): bool
    {
        foreach ($statements as $statement) {
            if ($this->hasBareReturn($statement)) {
                return false;
            }
            $isTermination = $statement instanceof Stmt\Expression && StatementLists::isExit($statement);
            if ($statement instanceof Stmt\Return_ || $isTermination) {
                return true;
            }

            if ($statement instanceof Stmt\If_ && $this->hasOutcomeInEveryBranch($statement)) {
                return true;
            }

            if ($statement instanceof Stmt\TryCatch && $this->hasOutcomeInEveryCatch($statement)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ignore nested function scopes when checking void outcomes.
     */
    private function hasBareReturn(Node $node): bool
    {
        if ($node instanceof Node\FunctionLike) {
            return false;
        }

        if ($node instanceof Stmt\Return_ && ! $node->expr instanceof Expr) {
            return true;
        }

        foreach (get_object_vars($node) as $value) {
            $children = is_array($value) ? $value : [$value];
            foreach ($children as $child) {
                if ($child instanceof Node && $this->hasBareReturn($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Require an explicit outcome in every branch, including else.
     */
    private function hasOutcomeInEveryBranch(Stmt\If_ $statement): bool
    {
        if ($statement->else === null || ! $this->hasExplicitOutcome($statement->stmts)) {
            return false;
        }

        foreach ($statement->elseifs as $branch) {
            if (! $this->hasExplicitOutcome($branch->stmts)) {
                return false;
            }
        }

        return $this->hasExplicitOutcome($statement->else->stmts);
    }

    /**
     * Every normal and recovery path must finish before a following statement.
     */
    private function hasOutcomeInEveryCatch(Stmt\TryCatch $statement): bool
    {
        if ($statement->finally !== null || $this->hasJump($statement) || ! $this->hasExplicitOutcome($statement->stmts)) {
            return false;
        }

        foreach ($statement->catches as $catch) {
            if (! $this->hasExplicitOutcome($catch->stmts)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A jump can bypass a later return or leave the nested recovery block.
     */
    private function hasJump(Node $node): bool
    {
        if ($node instanceof Node\FunctionLike) {
            return false;
        }
        if (in_array($node->getType(), ['Stmt_Break', 'Stmt_Continue', 'Stmt_Goto'], true)) {
            return true;
        }

        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node && $this->hasJump($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Return the stable identifier used in configuration and diagnostics.
     */
    public function id(): string
    {
        return 'no_catch_all_exceptions';
    }

    /**
     * This check uses the resolved AST directly and needs no extra metrics.
     *
     * @return list<string>
     */
    #[\Override]
    public function requiredAnalyses(): array
    {
        return [];
    }
}
