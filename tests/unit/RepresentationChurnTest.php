<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Design\RepresentationChurn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SodaRepresentationFixture\Text\Cleaner;
use SodaRepresentationFixture\Text\Formatter;
use SodaRepresentationFixture\Text\Normalizer;
use SodaRepresentationFixture\Text\Pipeline;
use SodaRepresentationFixture\Text\Producer;
use SodaRepresentationFixture\Text\Str;

final class RepresentationChurnTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/representation';

    private function report(array $files, ?RepresentationChurn $rule = null): array
    {
        $result = (new Runner)->check($files, Soda::configure()->with([$rule ?? new RepresentationChurn])->checks());
        self::assertTrue($result->isPassing(), 'Research candidates must not fail quality checks.');
        self::assertCount(0, $result->violations);
        self::assertArrayHasKey('representation_churn', $result->research);
        $report = $result->research['representation_churn'];
        foreach (['candidates', 'partial', 'excluded', 'coverage', 'limits', 'profiles'] as $key) {
            self::assertArrayHasKey($key, $report);
            self::assertIsArray($report[$key]);
        }
        self::assertSame($report, json_decode(json_encode($report, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

        return $report;
    }

    private function files(string $scenario): array
    {
        $files = glob(self::FIXTURES.'/'.$scenario.'/*.php');
        self::assertIsArray($files);
        self::assertNotEmpty($files);

        return $files;
    }

    private function sourceReport(string $body, ?RepresentationChurn $rule = null, bool $importProfiles = true): array
    {
        $file = tempnam(sys_get_temp_dir(), 'soda-representation-');
        self::assertIsString($file);
        file_put_contents($file, '<?php namespace SodaRepresentationFixture\Text; '
            .($importProfiles ? 'use function trim, str_replace, array_reverse, strtolower, strlen, md5; ' : '').$body);

        try {
            return $this->report([self::FIXTURES.'/text/Str.php', $file], $rule);
        } finally {
            unlink($file);
        }
    }

    public function testCrossFileInjectedPipelineReportsAnEvidencePathWithoutClaimingAViolation(): void
    {
        $files = $this->files('text');
        $report = $this->report($files);
        self::assertNotEmpty($report['candidates']);
        $candidate = $report['candidates'][0];
        self::assertSame('structural', $candidate['status']);
        self::assertSame('confirmed', $candidate['data_dependency']);
        self::assertSame('confirmed', $candidate['compatibility']);
        self::assertSame('known', $candidate['effects']);
        self::assertContains($candidate['concept_preservation'], ['confirmed', 'unknown']);
        self::assertStringContainsString('Str', $candidate['representation']);
        self::assertNotEmpty($candidate['extraction']);
        self::assertNotEmpty($candidate['reconstruction']);
        self::assertNotEmpty($candidate['message']);
        $pathFiles = [];
        foreach ($candidate['path'] as $step) {
            self::assertContains($step['file'], $files);
            self::assertGreaterThan(0, $step['line']);
            self::assertNotEmpty($step['callable']);
            self::assertNotEmpty($step['operation']);
            $pathFiles[] = $step['file'];
        }
        self::assertGreaterThanOrEqual(3, count(array_unique($pathFiles)), 'A local unwrap/rewrap does not test distributed flow.');
        self::assertStringContainsString('normalize', json_encode($candidate['path'], JSON_THROW_ON_ERROR));
        self::assertStringContainsString('clean', json_encode($candidate['path'], JSON_THROW_ON_ERROR));
        self::assertStringContainsString('rebuild', json_encode($candidate['path'], JSON_THROW_ON_ERROR));
        self::assertNotEmpty($report['coverage']);
        self::assertNotEmpty($report['profiles']);
        self::assertEquals($report['candidates'], $this->report(array_reverse($files))['candidates']);
    }

    public function testExecutableTextFixturePreservesTheTextAndCreatesANewValue(): void
    {
        foreach (['Str', 'Producer', 'Normalizer', 'Cleaner', 'Formatter', 'Pipeline'] as $class) {
            require_once self::FIXTURES.'/text/'.$class.'.php';
        }
        $producer = new Producer;
        $normalizer = new Normalizer;
        $cleaner = new Cleaner;
        $formatter = new Formatter;
        $pipeline = new Pipeline($producer, $normalizer, $cleaner, $formatter);
        self::assertSame('hello ', $pipeline->handle(' hello obsolete ')->value());
        $original = $producer->load('hello');
        $rebuilt = $formatter->rebuild($cleaner->clean($normalizer->normalize($original)));
        self::assertSame($original->value(), $rebuilt->value());
        self::assertNotSame($original, $rebuilt, 'Same representation does not prove preserved identity.');
    }

    public function testCollectionPipelineRetainsKeysAndValues(): void
    {
        foreach (['Bag', 'Extractor', 'Processor', 'Assembler', 'Pipeline'] as $class) {
            require_once self::FIXTURES.'/collection/'.$class.'.php';
        }
        self::assertSame(['second' => 20, 'first' => 10], (new \SodaRepresentationFixture\Collection\Pipeline)->run()->all());
        $report = $this->report($this->files('collection'));
        self::assertNotEmpty($report['candidates'], 'Literal scalar elements provide a closed native array contract.');
        foreach ($report['candidates'] as $candidate) {
            self::assertSame('confirmed', $candidate['compatibility']);
            self::assertSame('confirmed', $candidate['data_dependency']);
            self::assertGreaterThanOrEqual(3, count(array_unique(array_column($candidate['path'], 'file'))));
            self::assertStringContainsString('process', json_encode($candidate['path'], JSON_THROW_ON_ERROR));
        }
    }

    #[DataProvider('disconnectedFlows')]
    public function testIndependentValuesCannotCloseARepresentationPath(string $body): void
    {
        self::assertSame([], $this->sourceReport($body)['candidates']);
    }

    public static function disconnectedFlows(): iterable
    {
        yield 'overwritten version' => ['function run(Str $input): Str { $value = $input->value(); $value = "unrelated"; return new Str($value); }'];
        yield 'independent calls of one helper' => ['function helper(string $value): string { return trim($value); } function run(Str $input): Str { $first = helper($input->value()); $second = helper("unrelated"); return new Str($second); }'];
        yield 'different instances in required slots' => ['final readonly class Money { public function __construct(private int $amount, private string $currency) {} public function amount(): int { return $this->amount; } public function currency(): string { return $this->currency; } } function run(Money $first, Money $second): Money { return new Money($first->amount(), $second->currency()); }'];
    }

    #[DataProvider('unresolvedFlows')]
    public function testUnsupportedProofsRemainVisibleAsPartialResearch(string $body): void
    {
        $report = $this->sourceReport($body);
        self::assertSame([], $report['candidates']);
        self::assertNotEmpty($report['partial'], 'Unknown semantics must remain visible in research coverage.');
    }

    public static function unresolvedFlows(): iterable
    {
        yield 'branch merge' => ['function run(Str $input, bool $flag): Str { $value = $input->value(); if ($flag) { $value = "other"; } return new Str($value); }'];
        yield 'reference alias' => ['function run(Str $input): Str { $value = $input->value(); $alias =& $value; $alias = "other"; return new Str($value); }'];
        yield 'unknown effect' => ['function run(Str $input): Str { $value = $input->value(); mutate($value); return new Str($value); }'];
        yield 'virtual dispatch' => ['class Normalizer { public function normalize(Str $input): string { return trim($input->value()); } } function run(Str $input, Normalizer $normalizer): Str { return new Str($normalizer->normalize($input)); }'];
        yield 'unknown constructor effect' => ['final class Guarded { private string $text; public function __construct(string $text) { validate($text); $this->text = $text; } public function value(): string { return $this->text; } } function run(Guarded $input): Guarded { return new Guarded(trim($input->value())); }'];
        yield 'lazy execution' => ['function run(Str $input): Str { $value = $input->value(); $later = fn () => trim($value); return new Str($later()); }'];
        yield 'generic element guarantees unknown' => ['/** @template T */ final readonly class Bag { /** @param array<T> $items */ public function __construct(private array $items) {} /** @return array<T> */ public function all(): array { return $this->items; } } function run(Bag $input): Bag { return new Bag(array_reverse($input->all())); }'];
    }

    #[DataProvider('changedConcepts')]
    public function testKnownConceptChangesAreNotReportedAsPreservedText(string $operation): void
    {
        $report = $this->sourceReport('function run(Str $input): Str { return new Str((string) '.$operation.'($input->value())); }');
        self::assertSame([], $report['candidates']);
        self::assertNotEmpty($report['excluded']);
    }

    public static function changedConcepts(): iterable
    {
        yield 'text length' => ['strlen'];
        yield 'text digest' => ['md5'];
    }

    public function testAnExactNewReceiverCanResolveANonfinalMethod(): void
    {
        $report = $this->sourceReport(
            'class Processor { public function process(Str $input): string { return trim($input->value()); } } '
            .'function run(Str $input): Str { $processor = new Processor(); return new Str($processor->process($input)); }',
        );
        self::assertNotEmpty($report['candidates']);
        foreach ($report['candidates'] as $candidate) {
            self::assertSame('confirmed', $candidate['data_dependency']);
        }
    }

    public function testAProfileWithAnIncompatibleScalarInputCannotProveCompatibility(): void
    {
        $report = $this->sourceReport(
            'final readonly class Money { public function __construct(private int $amount, private string $currency) {} '
            .'public function amount(): int { return $this->amount; } public function currency(): string { return $this->currency; } } '
            .'function run(Money $input): Money { return new Money(trim($input->amount()), $input->currency()); }',
        );
        self::assertSame([], $report['candidates']);
        self::assertNotEmpty([...$report['partial'], ...$report['excluded']]);
    }

    public function testNestedProfilesCannotRecoverAValueAfterAnUnknownMutation(): void
    {
        $report = $this->sourceReport(
            'function run(Str $input): Str { $value = $input->value(); external_mutation($value); '
            .'return new Str(trim(strtolower($value))); }',
        );
        self::assertSame([], $report['candidates']);
        self::assertNotEmpty($report['partial']);
    }

    public function testProcessingDoesNotMakeSlotsFromDifferentMoneyInstancesCorrelated(): void
    {
        $report = $this->sourceReport(
            'final readonly class Money { public function __construct(private string $amount, private string $currency) {} '
            .'public function amount(): string { return $this->amount; } public function currency(): string { return $this->currency; } } '
            .'function run(Money $first, Money $second): Money { '
            .'return new Money(trim($first->amount()), strtolower($second->currency())); }',
        );
        self::assertSame([], $report['candidates']);
    }

    public function testAConstructorThatReplacesTheArgumentCannotRestoreItsOrigin(): void
    {
        $report = $this->sourceReport(
            'final readonly class Replacement { private string $text; '
            .'public function __construct(string $text) { $this->text = "replacement"; } '
            .'public function value(): string { return $this->text; } } '
            .'function run(Replacement $input): Replacement { return new Replacement(trim($input->value())); }',
        );
        self::assertSame([], $report['candidates']);
    }

    public function testChoosingAPrimitiveProcessingContractRemovesTheRoundTripAndPreservesBehavior(): void
    {
        $before = 'function before(Str $input): Str { return new Str(str_replace("obsolete", "", trim($input->value()))); }';
        $after = 'function after(string $input): Str { return new Str(str_replace("obsolete", "", trim($input))); }';
        self::assertNotEmpty($this->sourceReport($before)['candidates']);
        self::assertSame([], $this->sourceReport($after)['candidates']);
        require_once self::FIXTURES.'/text/Str.php';
        eval('namespace SodaRepresentationFixture\\Text; use function trim, str_replace; '.$before.$after);
        foreach ([' hello obsolete ', '', 'obsoleteobsolete', "\tretained\n"] as $input) {
            self::assertSame(
                \SodaRepresentationFixture\Text\before(new Str($input))->value(),
                \SodaRepresentationFixture\Text\after($input)->value(),
            );
        }
    }

    public function testNamespacedFunctionDoesNotInheritAGlobalProfileByName(): void
    {
        $report = $this->sourceReport(
            'function trim(string $input): string { return "unrelated"; } '
            .'function run(Str $input): Str { return new Str(trim($input->value())); }',
            importProfiles: false,
        );
        self::assertSame([], $report['candidates']);
    }

    public function testUnqualifiedNamespaceFallbackIsAnExplicitResolutionGap(): void
    {
        $report = $this->sourceReport(
            'function run(Str $input): Str { return new Str(trim($input->value())); }',
            importProfiles: false,
        );
        self::assertSame([], $report['candidates']);
        self::assertNotEmpty($report['partial']);
    }

    public function testTransportNamesCannotEstablishThePurposeOfABoundary(): void
    {
        $report = $this->sourceReport('final class TransportDTO { public function roundTrip(Str $input): Str { return new Str(trim($input->value())); } }');
        self::assertNotEmpty($report['candidates']);
        foreach ($report['candidates'] as $candidate) {
            self::assertSame('structural', $candidate['status']);
        }
    }

    #[DataProvider('boundedSearch')]
    public function testLimitsProduceExplicitTruncation(RepresentationChurn $rule): void
    {
        $report = $this->report($this->files('text'), $rule);
        self::assertStringContainsString('truncated', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertNotEmpty($report['limits']);
    }

    public function testCandidateLimitDisclosesOmittedPaths(): void
    {
        $report = $this->sourceReport(
            'function first(Str $input): Str { return new Str(trim($input->value())); } '
            .'function second(Str $input): Str { return new Str(strtolower($input->value())); }',
            new RepresentationChurn(maxCandidates: 1),
        );
        self::assertLessThanOrEqual(1, count($report['candidates']));
        self::assertStringContainsString('truncated', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public static function boundedSearch(): iterable
    {
        yield 'call depth' => [new RepresentationChurn(maxDepth: 1)];
        yield 'search work' => [new RepresentationChurn(maxSteps: 1)];
    }
}
