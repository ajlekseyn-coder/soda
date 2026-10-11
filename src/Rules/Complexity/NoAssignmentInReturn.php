<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Reporting\Violation;
use Cosmira\Soda\Rules\Check;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

/**
 * Rejects assignments evaluated inside explicit returns and implicit arrow-function returns.
 */
final class NoAssignmentInReturn extends Check
{
    /**
     * Identify this return-expression policy in configuration and reports.
     */
    public function id(): string
    {
        return 'no_assignment_in_return';
    }

    /**
     * Inspect parsed expressions without requesting additional metric collectors.
     *
     * @return list<string>
     */
    public function requiredAnalyses(): array
    {
        return [];
    }

    /**
     * Report each return expression once while respecting nested callable boundaries.
     */
    public function checkFile(FileFacts $file): iterable
    {
        $finder = new NodeFinder;
        $owners = [...$finder->findInstanceOf($file->nodes, Return_::class), ...$finder->findInstanceOf($file->nodes, ArrowFunction::class)];
        foreach ($owners as $owner) {
            if ($owner->expr === null || ! AssignmentSyntax::hasAssignment($owner->expr)) {
                continue;
            }

            yield new Violation(
                rule: $this->id(), file: $file->path, value: 1, threshold: 0,
                line: $owner->getStartLine(),
                message: 'Assignment inside a return expression is forbidden. Make the state change explicit before returning, preserving evaluation order and reference semantics.',
            );
        }
    }
}
