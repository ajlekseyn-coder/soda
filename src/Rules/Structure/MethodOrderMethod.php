<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Structure;

/**
 * @internal
 */
final readonly class MethodOrderMethod
{
    /**
     * Capture a method declaration and its calls for declaration-order checks.
     *
     * @param list<string> $calls
     * @param string       $name           Original declaration name retained for diagnostics.
     * @param string       $normalizedName Case-normalized method name used for local call resolution.
     * @param int          $line           One-based source line used to locate the declaration or finding.
     */
    public function __construct(
        /**
         * Original method declaration name retained for diagnostic display.
         */
        public string $name,
        /**
         * Normalized method name used to match call targets.
         */
        public string $normalizedName,
        /**
         * Source line of the method declaration.
         */
        public int $line,
        /**
         * Method call targets in their observed source order.
         */
        public array $calls,
    ) {}
}
