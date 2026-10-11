<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Structure;

use function array_key_last;
use function array_pop;

/**
 * @internal
 */
final class TellDontAskScopes
{
    /**
     * Nested variable-to-query alias maps used when resolving conditions.
     *
     * @psalm-var list<array<string, list<array{receiver: string, method: string}>>>
     */
    private array $scopes = [];

    /**
     * Reset using the current analysis state.
     */
    public function reset(): void
    {
        $this->scopes = [[]];
    }

    /**
     * Clear using the current analysis state.
     */
    public function clear(): void
    {
        $this->scopes = [];
    }

    /**
     * Check the supplied input for scopes.
     */
    public function hasScopes(): bool
    {
        return $this->scopes !== [];
    }

    /**
     * Push scope using the current analysis state.
     */
    public function pushScope(): void
    {
        if ($this->scopes !== []) {
            $this->scopes[] = [];
        }
    }

    /**
     * Pop scope using the current analysis state.
     */
    public function popScope(): void
    {
        if ($this->scopes !== []) {
            array_pop($this->scopes);
        }
    }

    /**
     * Return the active query-alias scopes from outermost to innermost.
     *
     * @return list<array<string, list<array{receiver: string, method: string}>>>
     */
    public function all(): array
    {
        return $this->scopes;
    }

    /**
     * Associate a variable with query results in the active alias scope.
     *
     * @param list<array{receiver: string, method: string}> $questions
     */
    public function record(string $variable, array $questions): void
    {
        $scopeIndex = array_key_last($this->scopes);

        if ($scopeIndex === null) {
            return;
        }

        $top = $this->scopes[$scopeIndex];

        if ($questions === []) {
            unset($top[$variable]);
            $this->scopes[$scopeIndex] = $top;

            return;
        }

        $top[$variable] = $questions;
        $this->scopes[$scopeIndex] = $top;
    }
}
