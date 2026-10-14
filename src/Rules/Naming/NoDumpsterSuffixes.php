<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Naming;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;

/**
 * Rejects type names ending in generic role suffixes instead of naming what the type does.
 *
 * "Manager", "Helper", "Util" and friends name a job category, not a thing. Every such
 * type hides a real noun behind a word that says nothing: an `OrderHelper` is a
 * `Pricer`, an `Exporter`, a `Validator` — the name should say which.
 *
 * @example Register in soda.php:
 *
 *   new NoDumpsterSuffixes(extraSuffixes: ['Evidence', 'Probe']);
 */
final class NoDumpsterSuffixes extends Check
{
    /**
     * Generic role suffixes rejected by default, merged with extraSuffixes.
     */
    private const array SUFFIXES = [
        'Manager', 'Helper', 'Util', 'Utils', 'Processor', 'Service', 'Handler', 'Wrapper',
    ];

    /**
     * Merge extra suffixes with the built-in vocabulary.
     *
     * @param string[] $extraSuffixes Additional rejected suffixes, matched at word boundaries.
     */
    public function __construct(
        private readonly array $extraSuffixes = [],
    ) {}

    /**
     * Return the stable identifier used in diagnostics.
     */
    #[\Override]
    public function id(): string
    {
        return 'no_dumpster_suffixes';
    }

    /**
     * Request resolved class, trait and interface names before this rule runs.
     *
     * @return list<string>
     */
    #[\Override]
    public function requiredAnalyses(): array
    {
        return ['naming'];
    }

    /**
     * Report each declared type whose name ends in a generic role suffix.
     *
     * @return iterable<Violation>
     */
    #[\Override]
    public function checkFile(FileFacts $file): iterable
    {
        foreach ($file->metrics['naming']['types'] ?? [] as $type) {
            $suffix = $this->fillerSuffix($type['name']);
            if ($suffix === null) {
                continue;
            }

            yield new Violation(
                rule: $this->id(), file: $file->path, value: 1, threshold: 0,
                class: $type['name'], line: $type['line'],
                message: sprintf('%s ends in the generic suffix "%s"; name what the type does instead.', $type['name'], $suffix),
            );
        }
    }

    /**
     * Return the first configured suffix terminating the name, or null when the name stands.
     */
    private function fillerSuffix(string $name): ?string
    {
        foreach (array_merge(self::SUFFIXES, $this->extraSuffixes) as $suffix) {
            if (str_ends_with(strtolower($name), strtolower($suffix))) {
                return $suffix;
            }
        }

        return null;
    }
}
