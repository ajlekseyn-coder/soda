<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Rules\Documentation\NoVacuousDocblocks;
use Cosmira\Soda\Tests\CheckFixture;
use PHPUnit\Framework\TestCase;

final class NoVacuousDocblocksTest extends TestCase
{
    public function testReportsDescriptionsBuiltOnlyFromFillerWords(): void
    {
        $violations = CheckFixture::run([$this->rule()], [$this->fixture() => []]);

        $this->assertSame(
            ['$signature', 'SEPARATOR_WIDTH', '__construct()'],
            $violations->map(static fn ($item): string => (string) preg_match('/PHPDoc on (.+?) adds/', (string) $item->message, $m) ? $m[1] : '')->all(),
        );
    }

    public function testAllowsDescriptionsCarryingContentBeyondTheName(): void
    {
        $violations = CheckFixture::run([$this->rule()], [$this->fixture() => []]);

        $reported = $violations->map(static fn ($item): string => (string) $item->message)->implode(' | ');
        $this->assertStringNotContainsString('$maximumAttempts', $reported);
        $this->assertStringNotContainsString('render()', $reported);
        $this->assertStringNotContainsString('$mounted', $reported);
    }

    public function testExtraFillerVocabularyJoinsTheBuiltInOne(): void
    {
        $rule = new NoVacuousDocblocks(extraFiller: ['present', 'collected', 'grouped', 'originating', 'results', 'file']);

        $violations = CheckFixture::run([$rule], [$this->fixture() => []]);

        $this->assertCount(4, $violations);
        $this->assertStringContainsString('render()', (string) $violations->last()->message);
    }

    /**
     * Build the rule under test with its default filler vocabulary.
     */
    private function rule(): NoVacuousDocblocks
    {
        return new NoVacuousDocblocks;
    }

    /**
     * Build one source file holding documented members with filler and meaningful descriptions.
     */
    private function fixture(): string
    {
        $path = sys_get_temp_dir().'/soda-vacuous-docblocks-'.uniqid().'.php';
        file_put_contents($path, <<<'PHP'
<?php
namespace App\Support;

final class Report
{
    /** Stores signature for this analysis instance. */
    private array $signature;

    /** Maximum attempts allowed before the job is abandoned. */
    private int $maximumAttempts;

    /** Defines separator width used by this policy. */
    private const int SEPARATOR_WIDTH = 60;

    /** Initialize the configured values and collaborators for this instance. */
    public function __construct(private readonly array $mounted) {}

    /** Present the collected results grouped by their originating file. */
    public function render(): string { return ''; }
}
PHP);

        return $path;
    }
}
