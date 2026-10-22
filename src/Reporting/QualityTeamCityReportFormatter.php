<?php

declare(strict_types=1);

namespace Cosmira\Soda\Reporting;

/**
 * Formats the public `soda quality --report-type=teamcity` service messages.
 */
final readonly class QualityTeamCityReportFormatter
{
    /**
     * Groups every quality finding under one TeamCity test suite.
     */
    private const SUITE_NAME = 'soda quality';

    /**
     * Bind the metadata used to enrich every reported rule identifier.
     *
     * @param RuleMetadata $ruleMetadata Supplies labels and policy metadata for reported rule identifiers.
     */
    public function __construct(
        private RuleMetadata $ruleMetadata,
    ) {}

    /**
     * Serialize the analysis result into TeamCity service message lines.
     *
     * @return list<string>
     */
    public function format(QualityResult $result): array
    {
        $lines = ["##teamcity[testSuiteStarted name='".self::SUITE_NAME."']"];

        foreach ($result->violations as $violation) {
            $lines = [...$lines, ...$this->violationMessages($violation)];
        }

        $lines[] = "##teamcity[testSuiteFinished name='".self::SUITE_NAME."']";

        return $lines;
    }

    /**
     * Report one finding as a failed test so it appears in the TeamCity Tests tab.
     *
     * @return list<string>
     */
    private function violationMessages(Violation $violation): array
    {
        $name = $this->escape($this->testName($violation));

        return [
            "##teamcity[testStarted name='{$name}']",
            sprintf(
                "##teamcity[testFailed name='%s' message='%s' details='%s']",
                $name,
                $this->escape($this->finding($violation)),
                $this->escape($this->details($violation)),
            ),
            "##teamcity[testFinished name='{$name}']",
        ];
    }

    /**
     * Locate the finding by source position and stable rule identifier.
     */
    private function testName(Violation $violation): string
    {
        $line = $violation->line !== null ? ':'.$violation->line : '';

        return $violation->file.$line.' '.$violation->rule;
    }

    /**
     * Summarize the finding with its message or the measured limit.
     */
    private function finding(Violation $violation): string
    {
        return $violation->message ?? sprintf(
            '%s %d (%s %d)',
            $this->ruleMetadata->label($violation->rule),
            $violation->value,
            $this->ruleMetadata->comparison($violation->rule),
            $violation->threshold,
        );
    }

    /**
     * Combine the reported scope with the remediation advice.
     */
    private function details(Violation $violation): string
    {
        $lines = array_filter(
            [$violation->method ?? $violation->class, $this->ruleMetadata->advice($violation->rule)],
            static fn (?string $line): bool => $line !== null && $line !== '',
        );

        return implode("\n", $lines);
    }

    /**
     * Escape text for embedding into a TeamCity service message.
     */
    private function escape(string $text): string
    {
        return str_replace(
            ['|', "'", "\r", "\n", '[', ']'],
            ['||', "|'", '|r', '|n', '|[', '|]'],
            $text,
        );
    }
}
