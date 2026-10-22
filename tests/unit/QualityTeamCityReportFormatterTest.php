<?php

declare(strict_types=1);

namespace Cosmira\Soda\Reporting;

use Cosmira\Soda\Config\RuleCatalog;
use PHPUnit\Framework\TestCase;

final class QualityTeamCityReportFormatterTest extends TestCase
{
    public function testFormatsPassingRunAsEmptyTeamCitySuite(): void
    {
        $lines = (new QualityTeamCityReportFormatter(new RuleMetadata(RuleCatalog::definitions())))->format(new QualityResult([]));

        $this->assertSame([
            "##teamcity[testSuiteStarted name='soda quality']",
            "##teamcity[testSuiteFinished name='soda quality']",
        ], $lines);
    }

    public function testFormatsViolationAsEscapedFailedTeamCityTest(): void
    {
        $violation = new Violation(
            rule: 'max_effective_class_length',
            file: '/path/to/Flow.php',
            value: 260,
            threshold: 250,
            method: 'handle',
            class: 'Flow',
            line: 12,
            message: "It's [long].",
        );

        $lines = (new QualityTeamCityReportFormatter(new RuleMetadata(RuleCatalog::definitions())))->format(new QualityResult([$violation]));
        $name = '/path/to/Flow.php:12 max_effective_class_length';
        $advice = RuleCatalog::definitions()['max_effective_class_length']['advice'];

        $this->assertSame([
            "##teamcity[testSuiteStarted name='soda quality']",
            "##teamcity[testStarted name='{$name}']",
            "##teamcity[testFailed name='{$name}' message='It|'s |[long|].' details='handle|n{$advice}']",
            "##teamcity[testFinished name='{$name}']",
            "##teamcity[testSuiteFinished name='soda quality']",
        ], $lines);
    }

    public function testFormatsThresholdFindingWithoutMessage(): void
    {
        $violation = new Violation(rule: 'max_effective_class_length', file: '/path/to/Report.php', value: 260, threshold: 250);

        $lines = (new QualityTeamCityReportFormatter(new RuleMetadata(RuleCatalog::definitions())))->format(new QualityResult([$violation]));
        $name = '/path/to/Report.php max_effective_class_length';
        $advice = RuleCatalog::definitions()['max_effective_class_length']['advice'];

        $this->assertSame([
            "##teamcity[testSuiteStarted name='soda quality']",
            "##teamcity[testStarted name='{$name}']",
            "##teamcity[testFailed name='{$name}' message='Source lines including traits: 260 (max 250)' details='{$advice}']",
            "##teamcity[testFinished name='{$name}']",
            "##teamcity[testSuiteFinished name='soda quality']",
        ], $lines);
    }
}
