<?php

declare(strict_types=1);

namespace Cosmira\Soda\Reporting;

/**
 * Presentation metadata for quality rules (severity, display label).
 */
final readonly class RuleMetadata
{
    /**
     * Severity label for rules whose violation fails the quality gate.
     */
    public const string SEVERITY_ERROR = 'error';

    /**
     * Severity label for findings reported without failing the run.
     */
    public const string SEVERITY_WARNING = 'warning';

    /**
     * Comparison mode where the measured value must not exceed the threshold.
     */
    public const string COMPARISON_MAX = 'max';

    /**
     * Comparison mode where the measured value must reach the threshold.
     */
    public const string COMPARISON_MIN = 'min';

    /**
     * Retain presentation metadata indexed by stable rule identifiers.
     *
     * @param array $rules Presentation metadata indexed by stable rule identifier.
     *
     * @psalm-param array<string, array{severity: 'error'|'warning', label: string, advice: non-empty-string, comparison?: 'min'|'max'}> $rules
     */
    public function __construct(
        /**
         * Rule presentation metadata used for labels, severity, advice, and comparisons.
         */
        private array $rules,
    ) {}

    /**
     * Return the configured severity, using warning for an unknown rule.
     *
     * @return 'error'|'warning'
     */
    public function severity(string $rule): string
    {
        $row = $this->rules[$rule] ?? [];

        return $row['severity'] ?? self::SEVERITY_WARNING;
    }

    /**
     * Return the human-readable label for the requested rule.
     */
    public function label(string $rule): string
    {
        $row = $this->rules[$rule] ?? [];

        return $row['label'] ?? 'Unknown:';
    }

    /**
     * Return the comparison direction used to interpret this limit.
     */
    public function comparison(string $rule): string
    {
        $row = $this->rules[$rule] ?? [];

        return $row['comparison'] ?? self::COMPARISON_MAX;
    }

    /**
     * Return the remediation advice for the requested rule.
     */
    public function advice(string $rule): ?string
    {
        $row = $this->rules[$rule] ?? [];

        return $row['advice'] ?? null;
    }

    /**
     * Return the identifiers with registered presentation metadata.
     *
     * @return list<string>
     */
    public function ruleKeys(): array
    {
        return array_keys($this->rules);
    }
}
