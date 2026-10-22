<?php

declare(strict_types=1);

namespace Cosmira\Soda\Reporting;

/**
 * Formats the public `soda quality --report-type=junit` XML report.
 */
final readonly class QualityJunitReportFormatter
{
    /**
     * Groups every quality finding under one JUnit test suite.
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
     * Serialize the analysis result into a JUnit XML report.
     */
    public function format(QualityResult $result): string
    {
        $cases = $result->violations->map(fn (Violation $violation): string => $this->testCase($violation))->all();
        $count = (string) count($cases);

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            ."<testsuites>\n"
            ."<testsuite name=\"{$this->escape(self::SUITE_NAME)}\" tests=\"{$count}\" failures=\"{$count}\" errors=\"0\">\n"
            .implode('', $cases)
            ."</testsuite>\n"
            ."</testsuites>\n";
    }

    /**
     * Report one finding as a failed JUnit test case.
     */
    private function testCase(Violation $violation): string
    {
        return sprintf(
            "<testcase name=\"%s\" file=\"%s\"%s>\n"
            ."<failure type=\"%s\" message=\"%s\">%s</failure>\n"
            ."</testcase>\n",
            $this->escape($this->testName($violation)),
            $this->escape($violation->file),
            $violation->line !== null ? ' line="'.$violation->line.'"' : '',
            $this->escape($violation->rule),
            $this->escape($this->finding($violation)),
            $this->escape($this->details($violation)),
        );
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
     * Escape text for embedding into JUnit XML attributes and text nodes.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }
}
