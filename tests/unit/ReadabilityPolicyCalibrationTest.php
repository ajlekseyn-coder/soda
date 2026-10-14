<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\FactCollector;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Rules\Complexity\NoAssignmentInReturn;
use Cosmira\Soda\Rules\Complexity\NoRepeatedStringLiterals;
use Cosmira\Soda\Rules\Usage\NoReassignedParameters;
use PHPUnit\Framework\TestCase;

final class ReadabilityPolicyCalibrationTest extends TestCase
{
    public function testActualLazyCachesRemainReadableUnderTheStandardPolicy(): void
    {
        foreach (['Config/FactCatalog.php', 'Rules/ExpressionCheck.php'] as $path) {
            self::assertSame([], $this->findings($this->standard('no_assignment_in_return'), $path));
            self::assertCount(1, $this->findings(new NoAssignmentInReturn(allowLazyInitialization: false), $path));
        }
    }

    public function testActualIdentityNormalizationNeedsNoExtraLocals(): void
    {
        $path = 'Analysis/Dependencies/FactoryHookContracts.php';
        self::assertSame([], $this->findings($this->standard('no_reassigned_parameters'), $path));
        self::assertCount(2, $this->findings(new NoReassignedParameters(allowInputNormalization: false), $path));
    }

    public function testActualDataShapeKeysDoNotForceProtocolConstants(): void
    {
        $path = 'Analysis/Composition/ClassBehaviorFacts.php';
        $contextual = $this->findings($this->standard('no_repeated_string_literals'), $path);
        $strict = $this->findings(new NoRepeatedStringLiterals(includeArrayKeys: true), $path);
        self::assertGreaterThan(count($contextual), count($strict));
    }

    private function standard(string $id): Check
    {
        foreach (RuleCatalog::standard() as $check) {
            if ($check->id() === $id) {
                return $check;
            }
        }

        self::fail('Missing standard rule '.$id);
    }

    private function findings(Check $check, string $path): array
    {
        $file = (new FactCollector)->collect(dirname(__DIR__, 2).'/src/'.$path, $check->requiredAnalyses());

        return iterator_to_array($check->checkFile($file));
    }
}
