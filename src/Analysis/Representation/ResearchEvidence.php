<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Representation;

/**
 * Owns canonical research witnesses, bounded gaps and the denominators of a run.
 */
final class ResearchEvidence
{
    /**
     * Gap status marking an exhausted budget instead of an unresolved operation.
     */
    private const string STATUS_TRUNCATED = 'truncated';

    /**
     * Confirmed structural paths keyed by their extraction and reconstruction declarations.
     */
    private array $candidates = [];

    /**
     * Explicit uncertainty and truncation keyed by cause and source position.
     */
    private array $partial = [];

    /**
     * Paths excluded because a known operation changes the concept.
     */
    private array $excluded = [];

    /**
     * Coverage measures without pretending absent findings prove application consistency.
     */
    private array $coverage = [
        'callables'   => 0, 'roots' => 0, 'operations' => 0, 'resolved_calls' => 0,
        'raw_signals' => 0, 'retained_signals' => 0, 'incomplete' => false, 'truncated' => false,
        'limitations' => ['straight-line bodies', 'exact dispatch', 'native scalar slots',
            'unqualified namespaced core calls need proven global resolution',
            'generic contracts and architectural purpose require review'],
    ];

    /**
     * Capture the effective settings so every research result declares its budgets.
     */
    public function __construct(
        /**
         * Effective depth, work and witness caps of the interpreter.
         */
        private readonly array $limits,
    ) {}

    /**
     * Capture bounded witness state while an invocation establishes its complete contract.
     */
    public function checkpoint(): array
    {
        return ['candidates' => $this->candidates, 'excluded' => $this->excluded,
            'raw_signals'    => $this->coverage['raw_signals']];
    }

    /**
     * Discard provisional witnesses from an unresolved frame while retaining its explicit gaps.
     */
    public function rollback(array $checkpoint): void
    {
        $this->candidates = $checkpoint['candidates'];
        $this->excluded = $checkpoint['excluded'];
        $this->coverage['raw_signals'] = $checkpoint['raw_signals'];
    }

    /**
     * Publish a measured count after collection or interpretation.
     */
    public function measure(string $name, int $value): void
    {
        $this->coverage[$name] = $value;
    }

    /**
     * Count an observed root, call or pre-deduplication witness.
     */
    public function count(string $name): void
    {
        $this->coverage[$name]++;
    }

    /**
     * Keep one canonical witness while disclosing omissions caused by the retention cap.
     */
    public function candidate(string $key, array $candidate): void
    {
        $this->count('raw_signals');
        if (isset($this->candidates[$key])) {
            return;
        }

        if (count($this->candidates) >= $this->limits['maxCandidates']) {
            $this->gap(self::STATUS_TRUNCATED, 'Retained witness budget exhausted.', $candidate['reconstruction'], 'maxCandidates', $candidate['path']);

            return;
        }

        $this->candidates[$key] = $candidate;
    }

    /**
     * Record concept-changing paths separately from consistency review candidates.
     */
    public function exclude(string $key, array $path): void
    {
        if (count($this->excluded) >= $this->limits['maxCandidates']) {
            $last = end($path);
            $point = $last === false ? ['file' => '', 'line' => 1, 'callable' => '', 'operation' => 'excluded'] : $last;
            $this->gap(self::STATUS_TRUNCATED, 'Excluded witness budget exhausted.', $point, 'maxCandidates');

            return;
        }

        $this->excluded[$key] = ['status' => 'changed_concept', 'path' => $path,
            'reason'                      => 'A core operation produces a different concept.'];
    }

    /**
     * Preserve explicit gaps, including global exhaustion before a witness exists.
     */
    public function gap(string $status, string $reason, array $point, ?string $limit = null, array $path = []): void
    {
        $this->coverage['incomplete'] = true;
        if ($status === self::STATUS_TRUNCATED) {
            $this->coverage['truncated'] = true;
        }

        if (count($this->partial) >= $this->limits['maxSteps']) {
            $this->coverage['truncated'] = true;

            return;
        }

        $key = $status.':'.$reason.':'.$point['file'].':'.$point['line'];
        $this->partial[$key] = [...$point, 'status' => $status, 'reason' => $reason, 'limit' => $limit, 'path' => $path];
    }

    /**
     * Serialize only arrays and scalars, with exact profile and budget metadata.
     */
    public function result(): array
    {
        $this->coverage['retained_signals'] = count($this->candidates);

        return ['candidates' => array_values($this->candidates), 'partial' => array_values($this->partial),
            'excluded'       => array_values($this->excluded), 'coverage' => $this->coverage, 'limits' => $this->limits,
            'profiles'       => ['php-core-v1:trim,ltrim,rtrim,str_replace,strtolower,strtoupper',
                'php-core-v1:strlen,md5,sha1,hash', 'php-core-v1:array_reverse,array_values,array_filter']];
    }
}
