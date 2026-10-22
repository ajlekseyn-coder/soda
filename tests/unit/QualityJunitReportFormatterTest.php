<?php

declare(strict_types=1);

namespace Cosmira\Soda\Reporting;

use Cosmira\Soda\Config\RuleCatalog;
use PHPUnit\Framework\TestCase;

final class QualityJunitReportFormatterTest extends TestCase
{
    public function testFormatsPassingRunAsEmptyJunitSuite(): void
    {
        $xml = (new QualityJunitReportFormatter(new RuleMetadata(RuleCatalog::definitions())))->format(new QualityResult([]));

        $this->assertSame(
            str_replace("\r\n", "\n", <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
            <testsuite name="soda quality" tests="0" failures="0" errors="0">
            </testsuite>
            </testsuites>

            XML),
            $xml,
        );
    }

    public function testFormatsViolationAsEscapedFailedJunitCase(): void
    {
        $violation = new Violation(
            rule: 'max_effective_class_length',
            file: '/path/to/Flow.php',
            value: 260,
            threshold: 250,
            method: 'handle',
            class: 'Flow',
            line: 12,
            message: "It's <long> & [gone]",
        );

        $xml = (new QualityJunitReportFormatter(new RuleMetadata(RuleCatalog::definitions())))->format(new QualityResult([$violation]));
        $advice = RuleCatalog::definitions()['max_effective_class_length']['advice'];

        $this->assertSame(
            str_replace("\r\n", "\n", <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
            <testsuite name="soda quality" tests="1" failures="1" errors="0">
            <testcase name="/path/to/Flow.php:12 max_effective_class_length" file="/path/to/Flow.php" line="12">
            <failure type="max_effective_class_length" message="It&apos;s &lt;long&gt; &amp; [gone]">handle
            {$advice}</failure>
            </testcase>
            </testsuite>
            </testsuites>

            XML),
            $xml,
        );
    }

    public function testFormatsThresholdFindingWithoutMessage(): void
    {
        $violation = new Violation(rule: 'max_effective_class_length', file: '/path/to/Report.php', value: 260, threshold: 250);

        $xml = (new QualityJunitReportFormatter(new RuleMetadata(RuleCatalog::definitions())))->format(new QualityResult([$violation]));
        $advice = RuleCatalog::definitions()['max_effective_class_length']['advice'];

        $this->assertSame(
            str_replace("\r\n", "\n", <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
            <testsuite name="soda quality" tests="1" failures="1" errors="0">
            <testcase name="/path/to/Report.php max_effective_class_length" file="/path/to/Report.php">
            <failure type="max_effective_class_length" message="Source lines including traits: 260 (max 250)">{$advice}</failure>
            </testcase>
            </testsuite>
            </testsuites>

            XML),
            $xml,
        );
    }
}
