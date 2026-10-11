<?php

declare(strict_types=1);

namespace Cosmira\Soda\Reporting;

/**
 * Serialize research evidence separately from the quality gate report.
 */
final readonly class ResearchJsonReportFormatter
{
    /**
     * Preserve the research payload, including partial paths and coverage limits.
     *
     * @return array{schema_version: 1, research: object}
     */
    public function format(QualityResult $result): array
    {
        return [
            'schema_version' => 1,
            'research'       => (object) $result->research,
        ];
    }
}
