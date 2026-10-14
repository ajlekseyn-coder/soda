<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Rules\Naming\NoDumpsterSuffixes;
use Cosmira\Soda\Tests\CheckFixture;
use PHPUnit\Framework\TestCase;

final class NoDumpsterSuffixesTest extends TestCase
{
    public function testReportsTypesNamedAfterTheirJobCategory(): void
    {
        $violations = CheckFixture::run([new NoDumpsterSuffixes], [$this->fixture() => []]);

        $this->assertSame(
            ['OrderHelper', 'CacheManager', 'ImageProcessor'],
            $violations->map(static fn ($item): string => substr((string) $item->class, (int) strrpos((string) $item->class, '\\') + 1))->all(),
        );
        $this->assertSame([4, 8, 14], $violations->map(static fn ($item): ?int => $item->line)->all());
        $this->assertStringContainsString('OrderHelper', (string) $violations->first()->message);
    }

    public function testAllowsNamesThatStateWhatTheTypeDoes(): void
    {
        $violations = CheckFixture::run([new NoDumpsterSuffixes], [$this->fixture() => []]);

        $reported = $violations->map(static fn ($item): ?string => $item->class)->implode(' | ');
        $this->assertStringNotContainsString('Pricer', $reported);
        $this->assertStringNotContainsString('DeploymentState', $reported);
    }

    public function testExtraSuffixesExtendTheRejectedVocabulary(): void
    {
        $rule = new NoDumpsterSuffixes(extraSuffixes: ['Evidence']);

        $violations = CheckFixture::run([$rule], [$this->fixture() => []]);

        $reported = $violations->map(static fn ($item): ?string => $item->class)->implode(' | ');
        $this->assertStringContainsString('ResearchEvidence', $reported);
    }

    /**
     * Build one source file holding generic and specific type names.
     */
    private function fixture(): string
    {
        $path = sys_get_temp_dir().'/soda-dumpster-suffixes-'.uniqid().'.php';
        file_put_contents($path, <<<'PHP'
<?php
namespace App\Billing;

final class OrderHelper {}

final class Pricer {}

trait CacheManager {}

interface Metadata {}

final class ResearchEvidence {}

abstract class ImageProcessor {}

final class DeploymentState {}
PHP);

        return $path;
    }
}
