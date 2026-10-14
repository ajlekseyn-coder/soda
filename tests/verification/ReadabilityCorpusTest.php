<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Complexity\NoAssignmentInReturn;
use Cosmira\Soda\Rules\Complexity\NoRepeatedStringLiterals;
use Cosmira\Soda\Rules\Usage\NoReassignedParameters;
use PHPUnit\Framework\TestCase;

final class ReadabilityCorpusTest extends TestCase
{
    public function testPinnedCorpusReadabilityPolicies(): void
    {
        $mapping = getenv('SODA_READABILITY_CORPUS');
        if ($mapping === false || $mapping === '') {
            self::markTestSkipped('Set SODA_READABILITY_CORPUS to a JSON file mapping audited package names to installed roots.');
        }
        $roots = json_decode(file_get_contents($mapping), true, flags: JSON_THROW_ON_ERROR);
        $base = __DIR__.'/../../docs/research/';
        $sources = json_decode(file_get_contents($base.'laravel-audit/packages.json'), true, flags: JSON_THROW_ON_ERROR)['packages'];
        $audit = json_decode(file_get_contents($base.'readability-calibration/results.json'), true, flags: JSON_THROW_ON_ERROR);
        $checks = array_values(array_filter(RuleCatalog::standard(), fn ($check): bool => in_array($check->id(), $audit['checks'], true)));
        self::assertCount(7, $checks);
        $strict = array_map(fn ($check) => match ($check->id()) {
            'no_assignment_in_return'     => new NoAssignmentInReturn(allowLazyInitialization: false, allowOwnerStateReturn: false, allowArrowStateUpdates: false),
            'no_reassigned_parameters'    => new NoReassignedParameters(allowInputNormalization: false),
            'no_repeated_string_literals' => new NoRepeatedStringLiterals(includeArrayKeys: true),
            default                       => $check,
        }, $checks);
        foreach ($audit['packages'] as $package) {
            self::assertArrayHasKey($package['name'], $roots);
            $source = array_values(array_filter($sources, fn ($source): bool => $source['name'] === $package['name']))[0];
            self::assertSame($source['version'], $package['version']);
            self::assertSame($source['source_commit'], $package['source_commit']);
            self::assertCount($package['php_files'], $source['files']);
            $files = [];
            foreach ($source['files'] as $file) {
                $path = $roots[$package['name']].'/'.$file['path'];
                self::assertSame($file['sha256'], hash_file('sha256', $path), 'Pinned source changed: '.$path);
                $files[] = $path;
            }
            foreach (['contextual_counts' => $checks, 'strict_counts' => $strict] as $policy => $rules) {
                $result = (new Runner)->check();
                $counts = array_count_values(array_column($result->violations->toArray(), 'rule'));
                ksort($counts);
                self::assertSame($package[$policy], $counts, $package['name'].' '.$policy);
            }
        }
    }
}
