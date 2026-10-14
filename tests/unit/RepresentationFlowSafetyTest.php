<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\FactCollector;
use Cosmira\Soda\Analysis\ProjectFacts;
use Cosmira\Soda\Analysis\Representation\RepresentationFlow;
use PHPUnit\Framework\TestCase;

final class RepresentationFlowSafetyTest extends TestCase
{
    public function testReusingInterpreterResetsDeclarationsOriginsAndBudgets(): void
    {
        $project = new ProjectFacts;
        foreach (glob(__DIR__.'/../fixtures/representation/text/*.php') as $file) {
            $project->add((new FactCollector)->collect($file, ['representationFlow']));
        }

        $flow = new RepresentationFlow(12, 2000, 100);
        $first = $flow->analyze($project);
        $this->assertNotEmpty($first['candidates']);
        $this->assertSame($first, $flow->analyze($project));
        $empty = $flow->analyze(new ProjectFacts);
        $this->assertSame([], $empty['candidates']);
        $this->assertSame([], $empty['partial']);
        $this->assertSame(0, $empty['coverage']['operations']);
        $this->assertSame(0, $empty['coverage']['callables']);
    }

    public function testDuplicateTypesAcrossFilesQuarantineEveryClassBody(): void
    {
        $report = $this->report([
            'final readonly class Text { public function __construct(private string $text) {} public function value(): string { return $this->text; } }',
            'final readonly class Text { public function __construct(private string $text) {} public function roundtrip(Text $input): Text { return new Text(trim($input->value())); } } function run(Text $input): Text { return new Text(trim($input->value())); }',
        ]);
        $this->assertSame([], $report['candidates']);
        $this->assertStringContainsString('Ambiguous duplicate type declaration.', (string) json_encode($report['partial']));
        $this->assertTrue($report['coverage']['incomplete']);
    }

    public function testPrivateMethodCannotEstablishAnExternalExtractionPath(): void
    {
        $report = $this->report([
            'final readonly class Text { public function __construct(private string $text) {} private function value(): string { return $this->text; } } function run(Text $input): Text { return new Text(trim($input->value())); }',
        ]);
        $this->assertSame([], $report['candidates']);
        $this->assertStringContainsString('Method visibility is not accessible', (string) json_encode($report['partial']));
    }

    public function testPrivateMethodRemainsAccessibleWithinItsDeclaringClass(): void
    {
        $report = $this->report([
            'final readonly class Text { public function __construct(private string $text) {} private function value(): string { return $this->text; } public function normalize(): Text { return new Text(trim($this->value())); } }',
        ]);
        $this->assertNotEmpty($report['candidates']);
    }

    public function testStrictNativeParameterMismatchStopsAnImpossibleCall(): void
    {
        $report = $this->report([
            'final readonly class Text { public function __construct(private string $text) {} public function value(): string { return $this->text; } } function normalize(int $value): string { return trim($value); } function run(Text $input): Text { return new Text(normalize($input->value())); }',
        ]);
        $this->assertSame([], $report['candidates']);
        $this->assertStringContainsString('Argument representation does not satisfy', (string) json_encode($report['partial']));
    }

    public function testDifferentExactClassCannotSatisfyATypedObjectParameter(): void
    {
        $report = $this->report([
            'final readonly class Text { public function __construct(private string $text) {} public function value(): string { return $this->text; } } final readonly class Other { public function __construct(private string $text) {} public function value(): string { return $this->text; } } function normalize(Text $input): string { return trim($input->value()); } function run(Other $input): Other { return new Other(normalize($input)); }',
        ]);
        $this->assertSame([], $report['candidates']);
        $this->assertStringContainsString('Argument representation does not satisfy', (string) json_encode($report['partial']));
    }

    public function testUnresolvedConstructorOperationStopsFurtherInitialization(): void
    {
        $report = $this->report([
            'final class Text { private string $text; public function __construct(string $text) { validate($text); $this->text = $text; } public function value(): string { return $this->text; } } function run(Text $input): Text { return new Text(trim($input->value())); }',
        ]);
        $this->assertSame([], $report['candidates']);
        $this->assertNotEmpty($report['partial']);
        $this->assertStringContainsString('validate', (string) json_encode($report['partial']));
    }

    public function testInvalidHelperReturnContractCannotSupplyAReconstructionPath(): void
    {
        $report = $this->report([
            'final readonly class Text { public function __construct(private string $text) {} public function value(): string { return $this->text; } } function normalize(Text $input): int { return trim($input->value()); } function run(Text $input): Text { return new Text(normalize($input)); }',
        ]);
        $this->assertSame([], $report['candidates']);
        $this->assertStringContainsString('Returned representation does not satisfy', (string) json_encode($report['partial']));
    }

    public function testInvalidRootReturnRollsBackItsProvisionalReconstructionWitness(): void
    {
        $report = $this->report([
            'final readonly class Text { public function __construct(private string $text) {} public function value(): string { return $this->text; } } function run(Text $input): string { return new Text(trim($input->value())); }',
        ]);
        $this->assertSame([], $report['candidates']);
        $this->assertSame(0, $report['coverage']['raw_signals']);
        $this->assertStringContainsString('Returned representation does not satisfy', (string) json_encode($report['partial']));
    }

    public function testUnknownTrailingOperationRollsBackEarlierFrameWitnesses(): void
    {
        $report = $this->report([
            'final readonly class Text { public function __construct(private string $text) {} public function value(): string { return $this->text; } } function run(Text $input): Text { $result = new Text(trim($input->value())); mutate($result); return $result; }',
        ]);
        $this->assertSame([], $report['candidates']);
        $this->assertSame(0, $report['coverage']['raw_signals']);
        $this->assertStringContainsString('mutate', (string) json_encode($report['partial']));
    }

    public function testExternalPrivateFieldCannotEstablishAnExtractionPath(): void
    {
        $report = $this->report([
            'final readonly class Text { public function __construct(private string $text) {} } function run(Text $input): Text { return new Text(trim($input->text)); }',
        ]);
        $this->assertSame([], $report['candidates']);
        $this->assertStringContainsString('Field visibility cannot be established', (string) json_encode($report['partial']));
    }

    public function testPotentialIntegerOverflowRemainsUnknownWithoutNumericBounds(): void
    {
        $report = $this->report([
            'final readonly class Amount { public function __construct(private int $value) {} public function value(): int { return $this->value; } } function run(Amount $input): Amount { return new Amount($input->value() + 1); }',
        ]);
        $this->assertSame([], $report['candidates']);
        $this->assertStringContainsString('integer overflow contract is unresolved', (string) json_encode($report['partial']));
    }

    private function report(array $bodies): array
    {
        $paths = [];
        $project = new ProjectFacts;

        try {
            foreach ($bodies as $body) {
                $path = tempnam(sys_get_temp_dir(), 'soda-flow-safety-');
                $paths[] = $path;
                file_put_contents($path, '<?php namespace FlowSafety; use function trim; '.$body);
                $project->add((new FactCollector)->collect($path, ['representationFlow']));
            }

            return (new RepresentationFlow(12, 2000, 100))->analyze($project);
        } finally {
            foreach ($paths as $path) {
                unlink($path);
            }
        }
    }
}
