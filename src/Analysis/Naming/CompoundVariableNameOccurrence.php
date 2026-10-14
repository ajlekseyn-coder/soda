<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Naming;

final readonly class CompoundVariableNameOccurrence
{
    /**
     * Capture a compound variable identifier with its words and source context.
     *
     * @param list<non-empty-string> $words
     * @param string                 $name   Original declaration name retained for diagnostics.
     * @param int                    $line   One-based source line used to locate the declaration or finding.
     * @param ?string                $class  Declaring class reported with the finding, when available.
     * @param ?string                $method Declaring method reported with the finding, when available.
     */
    public function __construct(
        /**
         * Original variable identifier retained for naming diagnostics.
         */
        public string $name,
        /**
         * Identifier words used to measure compound-name length.
         */
        public array $words,
        /**
         * Source line of the variable identifier occurrence.
         */
        public int $line,
        /**
         * Declaring class context, when the variable appears inside a class.
         */
        public ?string $class,
        /**
         * Enclosing method context, when the variable appears inside a method.
         */
        public ?string $method,
    ) {}

    /**
     * Returns the number compared with the configured word limit.
     */
    public function wordCount(): int
    {
        return count($this->words);
    }
}
