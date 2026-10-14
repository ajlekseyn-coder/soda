<?php

declare(strict_types=1);

namespace Cosmira\Soda\Contracts;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\ProjectFacts;
use Cosmira\Soda\Reporting\Violation;

/**
 * Collect research evidence independently of quality gate diagnostics.
 */
abstract class ResearchCheck extends Check
{
    /**
     * Research checks never emit file violations.
     *
     * @return iterable<Violation>
     */
    final public function checkFile(FileFacts $file): iterable
    {
        return [];
    }

    /**
     * Research checks never emit project violations.
     *
     * @return iterable<Violation>
     */
    final public function checkProject(ProjectFacts $project): iterable
    {
        return [];
    }

    /**
     * Return candidates and uncertainty evidence for manual investigation.
     *
     * @return array<string, mixed>
     */
    abstract public function researchProject(ProjectFacts $project): array;
}
