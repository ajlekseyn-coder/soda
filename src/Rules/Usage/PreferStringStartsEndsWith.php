<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Usage;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

/**
 * Reports substr comparisons that ask the prefix or suffix question.
 */
final class PreferStringStartsEndsWith extends Check
{
    private const array REPLACEMENTS = [
        'substr'    => ['starts' => 'str_starts_with', 'ends' => 'str_ends_with'],
        'mb_substr' => ['starts' => 'mb_str_starts_with', 'ends' => 'mb_str_ends_with'],
    ];

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
        return 'prefer_string_starts_ends_with';
    }

    /**
     * Report comparisons of substr results with string literals.
     *
     * @return iterable<Violation>
     */
    #[\Override]
    public function checkFile(FileFacts $facts): iterable
    {
        $comparisons = (new NodeFinder)->find($facts->nodes, static fn ($node): bool => $node instanceof BinaryOp\Identical
            || $node instanceof BinaryOp\NotIdentical
            || $node instanceof BinaryOp\Equal
            || $node instanceof BinaryOp\NotEqual);
        foreach ($comparisons as $comparison) {
            assert($comparison instanceof BinaryOp);
            [$call, $literal] = $this->substrSide($comparison);
            if (!$call instanceof \PhpParser\Node\Expr\FuncCall || !$literal instanceof \PhpParser\Node\Scalar\String_) {
                continue;
            }

            $question = $this->question($call, $literal);
            if ($question === null) {
                continue;
            }

            $name = $this->substrName($call);
            yield new Violation(
                rule: $this->id(),
                file: $facts->path,
                value: 1,
                threshold: 0,
                line: $comparison->getStartLine(),
                message: sprintf(
                    'Use %s() instead of comparing %s() with a literal; the answer reads as a question about the %s of the string.',
                    self::REPLACEMENTS[$name][$question],
                    $name,
                    $question === 'starts' ? 'prefix' : 'suffix',
                ),
            );
        }
    }

    /**
     * Accept one side holding a substr call and the other a string literal.
     *
     * @return array{FuncCall|null, String_|null}
     */
    private function substrSide(BinaryOp $comparison): array
    {
        foreach ([$comparison->left, $comparison->right] as $side) {
            $other = $comparison->left === $side ? $comparison->right : $comparison->left;
            if ($side instanceof FuncCall && $other instanceof String_ && ! $side->isFirstClassCallable()
                && isset(self::REPLACEMENTS[$this->substrName($side)])) {
                return [$side, $other];
            }
        }

        return [null, null];
    }

    /**
     * Return the lowercase function name when the call targets a plain name.
     */
    private function substrName(FuncCall $call): string
    {
        return $call->name instanceof Name ? $call->name->toLowerString() : '';
    }

    /**
     * Classify the call as a prefix or suffix question when the offsets match the literal.
     */
    private function question(FuncCall $call, String_ $literal): ?string
    {
        $args = $call->getArgs();
        $offset = $args[1]->value;
        if ($offset instanceof Expr\UnaryMinus && $offset->expr instanceof LNumber) {
            $offset = -$offset->expr->value;
        } elseif ($offset instanceof LNumber) {
            $offset = $offset->value;
        } else {
            return null;
        }

        $length = null;
        if (isset($args[2]) && $args[2]->value instanceof LNumber) {
            $length = $args[2]->value->value;
        }

        $counted = $this->substrName($call) === 'mb_substr' ? mb_strlen($literal->value) : strlen($literal->value);
        if ($offset === 0) {
            return $length !== null && $length === $counted ? 'starts' : null;
        }

        if ($offset < 0) {
            return $length === null || ($length === $counted && $length <= -$offset) ? 'ends' : null;
        }

        return null;
    }
}
