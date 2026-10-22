<?php

declare(strict_types=1);

namespace Cosmira\Soda\Reporting;

/**
 * Formats the public `soda quality --report-type=json` payload.
 */
final readonly class QualityJsonReportFormatter
{
    /**
     * Bind the metadata used to enrich every reported rule identifier.
     *
     * @param RuleMetadata $ruleMetadata Supplies labels and policy metadata for reported rule identifiers.
     */
    public function __construct(
        private RuleMetadata $ruleMetadata,
    ) {}

    /**
     * Serialize the analysis result into the versioned JSON diagnostic report.
     *
     * @return array{schema_version: 4, passed: bool, violations: list<array<string, mixed>>}
     */
    public function format(QualityResult $result): array
    {
        $metadata = $this->ruleMetadata;

        return [
            'schema_version' => 4,
            'passed'         => $result->isPassing(),
            'violations'     => $result->violations->map(function (Violation $violation) use ($metadata): array {
                return [
                    ...$violation->toArray(),
                    'recommendation' => $metadata->advice($violation->rule),
                ];
            })->all(),
        ];
    }
}
