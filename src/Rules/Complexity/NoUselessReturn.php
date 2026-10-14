<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

final class NoUselessReturn extends Check
{
    /**
     * Inspect only the final direct statement; nested exits can change control flow.
     *
     * @return iterable<Violation>
     */
    #[\Override]
    public function checkFile(FileFacts $file): iterable
    {
        $callables = (new NodeFinder)->find($file->nodes, static fn (Node $node): bool => $node instanceof FunctionLike && ! $node instanceof ArrowFunction);

        foreach ($callables as $callable) {
            assert($callable instanceof FunctionLike);
            $statements = $callable->getStmts();
            if ($statements === null || $statements === [] || $callable->returnsByRef()) {
                continue;
            }

            $last = $statements[array_key_last($statements)];
            if (! $last instanceof Return_ || $last->expr !== null) {
                continue;
            }

            yield new Violation(
                rule: $this->id(), file: $file->path, value: 1, threshold: 0,
                line: $last->getStartLine(),
                message: 'Remove the trailing bare return; reaching the end of this callable already completes it.',
            );
        }
    }

    /**
     * Return the stable identifier used in configuration and diagnostics.
     */
    public function id(): string
    {
        return 'no_useless_return';
    }

    /**
     * Callable bodies are available in the AST without metric collection.
     *
     * @return list<string>
     */
    #[\Override]
    public function requiredAnalyses(): array
    {
        return [];
    }
}
