<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Structure;

use Illuminate\Support\Str;

use function str_contains;

/**
 * @internal
 */
final class TellDontAskBranchMatcher
{
    /**
     * Return current class name for the supplied analysis context.
     */
    public static function currentClassName(?string $methodName): ?string
    {
        $isClassMethod = $methodName !== null && str_contains($methodName, '::');

        return $isClassMethod
            ? Str::before($methodName, '::')
            : null;
    }

    /**
     * Match a queried receiver to a subsequent command while excluding calls on the owner itself.
     *
     * @param list<array{receiver: string, method: string, result?: string}>   $questions
     * @param array{receiver: string, method: string, arguments: list<string>} $command
     *
     * @return array{receiver: string, method: string, result?: string}|null
     */
    public static function firstMatch(array $questions, array $command): ?array
    {
        if (in_array($command['receiver'], ['$this', 'self', 'static'], true)) {
            return null;
        }

        foreach ($questions as $question) {
            if ($question['receiver'] !== $command['receiver']) {
                continue;
            }

            if ($question['method'] === $command['method']) {
                continue;
            }

            if (isset($question['result']) && in_array($question['result'], $command['arguments'], true)) {
                continue;
            }

            return $question;
        }

        return null;
    }
}
