<?php

declare(strict_types=1);

namespace Cosmira\Soda\Reporting;

use Illuminate\Support\Collection;

final readonly class QualityResult
{
    /**
     * Diagnostics produced by the configured rules for this analysis run.
     *
     * @psalm-var Collection<int, Violation>
     */
    public Collection $violations;

    /**
     * Capture the analysis diagnostics as a collection for reporting and filtering.
     *
     * @param array<string, array<string, mixed>> $research Nongating evidence from explicitly configured research checks.
     *
     * @psalm-param Collection<int, Violation>|list<Violation> $violations
     */
    public function __construct(
        Collection|array $violations,
        public array $research = [],
    ) {
        /** @var Collection<int, Violation> $col */
        $col = $violations instanceof Collection ? $violations : collect($violations);
        $this->violations = $col;
    }

    /**
     * Report whether analysis completed without any violations.
     */
    public function isPassing(): bool
    {
        return $this->violations->isEmpty();
    }
}
