<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Representation;

use Cosmira\Soda\Analysis\ProjectFacts;

/**
 * Executes bounded scalar callable facts with a separate environment for every invocation.
 */
final class RepresentationFlow
{
    use RepresentationExpressions;

    /**
     * Suffix identifying constructor callables in their declaration identity.
     */
    private const string CONSTRUCTOR_SUFFIX = '::__construct';

    /**
     * Indexed declarations, with source paths attached after file collection.
     */
    private array $types = [];

    /**
     * Callable IR indexed separately from type declarations.
     */
    private array $callables = [];

    /**
     * Research findings and coverage; none are quality violations.
     */
    private ResearchEvidence $evidence;

    /**
     * Number of expanded statements and expressions in this run.
     */
    private int $work = 0;

    /**
     * Invalidate evidence preceding an unresolved potentially mutating operation.
     */
    private int $epoch = 0;

    /**
     * Distinguish objects and invocations even when declarations and types coincide.
     */
    private int $serial = 0;

    /**
     * Active declarations used to stop recursive expansion.
     */
    private array $stack = [];

    /**
     * Configure finite work and evidence limits without claiming optimal budgets.
     */
    public function __construct(
        /**
         * Maximum call depth.
         */
        private readonly int $maxDepth,
        /**
         * Maximum interpreted operations.
         */
        private readonly int $maxSteps,
        /**
         * Maximum retained round-trip witnesses.
         */
        private readonly int $maxCandidates,
    ) {}

    /**
     * Analyze deterministic declaration order and retain no source or AST.
     */
    public function analyze(ProjectFacts $project): array
    {
        $this->resetState();
        $this->evidence = new ResearchEvidence(['maxDepth' => $this->maxDepth, 'maxSteps' => $this->maxSteps, 'maxCandidates' => $this->maxCandidates]);
        $this->index($project);
        foreach ($this->callables as $callable) {
            if ($this->work >= $this->maxSteps) {
                $this->gap(self::STATUS_TRUNCATED, 'Global interpretation budget exhausted.', $this->point($callable), self::LIMIT_STEPS);
                break;
            }

            $this->evidence->count('roots');
            $env = [];
            foreach ($callable['parameters'] as $parameter) {
                $env[$parameter['name']] = $this->seed($parameter['type'], $callable['id'].':'.$parameter['name']);
            }

            if ($callable['class'] !== null) {
                $env['this'] = $this->seed($callable['class'], $callable['id'].':this');
            }

            $this->execute($callable, $env, 0);
        }

        $this->evidence->measure('operations', $this->work);

        return $this->evidence->result();
    }

    /**
     * Isolate declarations, origin identities and budgets when the interpreter is reused.
     */
    private function resetState(): void
    {
        $this->types = [];
        $this->callables = [];
        $this->work = 0;
        $this->epoch = 0;
        $this->serial = 0;
        $this->stack = [];
    }

    /**
     * Attach file identity to compact declarations and expose collection truncation.
     */
    private function index(ProjectFacts $project): void
    {
        foreach ($project->files as $file => $metrics) {
            $flow = $metrics['representationFlow'] ?? [];
            foreach ($flow['types'] ?? [] as $id => $type) {
                $this->indexType($id, $type, $file);
            }

            foreach ($flow['callables'] ?? [] as $id => $callable) {
                if (isset($this->callables[$id])) {
                    $this->quarantine($id, $callable['line'], 'Ambiguous duplicate declaration.');

                    continue;
                }

                $this->callables[$id] = [...$callable, 'file' => $file];
            }

            foreach ($flow['truncated'] ?? [] as $row) {
                $this->quarantine($row['callable'] ?? '', $row['line'] ?? 1, 'Collector facts were truncated.');

                $point = ['file' => $file, 'line' => $row['line'] ?? 1, 'callable' => $row['callable'] ?? '', 'operation' => 'collection'];
                $this->gap(self::STATUS_TRUNCATED, $row['reason'] ?? 'Callable fact budget exhausted.', $point, 'collector');
            }
        }

        foreach ($this->callables as $id => $callable) {
            $type = $this->types[$callable['class'] ?? ''] ?? [];
            if (($type['ambiguous'] ?? false) === true) {
                $this->quarantine($id, $callable['line'], 'Ambiguous duplicate type declaration.');
            }
        }

        ksort($this->types);
        ksort($this->callables);
        $this->evidence->measure('callables', count($this->callables));
    }

    /**
     * Keep the first type identity and invalidate its proofs when another file redeclares it.
     */
    private function indexType(string $id, array $type, string $file): void
    {
        $existing = $this->types[$id] ?? null;
        if ($existing === null) {
            $this->types[$id] = [...$type, 'file' => $file];

            return;
        }

        $existing['eligible'] = false;
        $existing['ambiguous'] = true;
        $this->types[$id] = $existing;
        $this->gap(self::TYPE_UNKNOWN, 'Ambiguous duplicate type declaration.',
            ['file' => $file, 'line' => 1, 'callable' => $type['name'], 'operation' => 'declaration']);
    }

    /**
     * Replace an ambiguous or truncated declaration body with an explicit analysis barrier.
     */
    private function quarantine(string $id, int $line, string $reason): void
    {
        $callable = $this->callables[$id] ?? null;
        if ($callable === null) {
            return;
        }

        $callable['statements'] = [['op' => 'barrier', 'line' => $line, 'reason' => $reason]];
        $this->callables[$id] = $callable;
    }

    /**
     * Evaluate one invocation with isolated argument bindings and sequential local versions.
     */
    private function execute(array $callable, array $env, int $depth): array
    {
        $frame = [...$callable, 'depth' => $depth];
        if ($depth > $this->maxDepth || isset($this->stack[$callable['id']])) {
            return $this->unknown('Call expansion is truncated or recursive.', $this->point($frame), 'maxDepth');
        }

        if ($callable['byRef'] || array_filter($callable['parameters'], fn (array $p): bool => $p['byRef']) !== []) {
            return $this->unknown('By-reference callable binding is unsupported.', $this->point($frame));
        }

        $checkpoint = $this->evidence->checkpoint();
        $this->stack[$callable['id']] = true;
        $value = $this->evaluateBody($callable['statements'], $env, $frame);

        unset($this->stack[$callable['id']]);

        return $this->completeFrame($callable, $env, $value, $checkpoint);
    }

    /**
     * Commit only complete native return contracts and known constructor initialization.
     */
    private function completeFrame(array $callable, array $env, array $value, array $checkpoint): array
    {
        $known = $value['type'] !== self::TYPE_UNKNOWN;
        if (! $known || ! $this->checkReturnContract($callable, $value)) {
            $rejected = $known ? $this->unknown('Returned representation does not satisfy a proven return contract.', $this->point($callable)) : $value;
            $this->evidence->rollback($checkpoint);

            return $rejected;
        }

        if (str_ends_with($callable['id'], self::CONSTRUCTOR_SUFFIX)) {
            return $env['this'] ?? $value;
        }

        return $value;
    }

    /**
     * Match declared result types conservatively while allowing known void and constructor bodies.
     */
    private function checkReturnContract(array $callable, array $value): bool
    {
        if (str_ends_with($callable['id'], self::CONSTRUCTOR_SUFFIX)) {
            return true;
        }

        if ($callable['returnType'] === 'void') {
            return $value['type'] === self::TYPE_NULL;
        }

        return $this->checkParameterValue($callable['returnType'], $value);
    }

    /**
     * Interpret statements in order and stop the frame when any operation is unresolved.
     */
    private function evaluateBody(array $statements, array &$env, array $frame): array
    {
        foreach ($statements as $statement) {
            if (! $this->checkStep($frame, $statement)) {
                return $this->unknown('Interpretation budget exhausted.', $this->point($frame, $statement), self::LIMIT_STEPS);
            }

            $terminal = $this->statementResult($statement, $env, $frame);
            if ($terminal !== null) {
                return $terminal;
            }
        }

        return $this->value(self::TYPE_NULL);
    }

    /**
     * Execute one statement protocol; a value terminates the frame and null continues it.
     */
    private function statementResult(array $statement, array &$env, array $frame): ?array
    {
        if ($statement['op'] === 'barrier') {
            return $this->unknown($statement['reason'], $this->point($frame, $statement));
        }

        $value = $statement['value'] === null ? $this->value(self::TYPE_NULL) : $this->expression($statement['value'], $env, $frame);
        $returned = $statement['op'] === 'return';
        if ($value['type'] === self::TYPE_UNKNOWN || $returned) {
            return $returned ? $this->visit($value, $this->point($frame, $statement, 'return')) : $value;
        }

        if ($statement['op'] === 'assign' && ! $this->checkAssign($statement['target'], $value, $env, $frame)) {
            return $this->unknown('Unsupported heap or reference assignment.', $this->point($frame, $statement));
        }

        return null;
    }

    /**
     * Support local rebinding and constructor initialization without heap alias claims.
     */
    private function checkAssign(array $target, array $value, array &$env, array $frame): bool
    {
        if ($target['op'] === 'variable') {
            $env[$target['name']] = $value;

            return true;
        }

        $initializing = str_ends_with($frame['id'], self::CONSTRUCTOR_SUFFIX);
        $receiver = $target['receiver'] ?? [];
        $ownField = $target['op'] === 'property' && $receiver['op'] === 'variable' && $receiver['name'] === 'this';
        $object = $env['this'] ?? [];
        if ($initializing && $ownField && isset($object['fields'])) {
            $fields = $object['fields'];
            $fields[$target['name']] = $value;
            $object['fields'] = $fields;
            $env['this'] = $object;

            return true;
        }

        return false;
    }

    /**
     * Bind positional and named arguments for exactly one resolved invocation.
     */
    private function invoke(array $callable, array $args, ?array $receiver, array $frame, array $site): array
    {
        if (! $this->checkVisibility($callable, $frame)) {
            return $this->unknown('Method visibility is not accessible from the calling declaration.', $this->point($frame, $site));
        }

        $env = $this->invocationBindings($callable['parameters'], $args, $frame, $site);
        if ($env === null) {
            return $this->value(self::TYPE_UNKNOWN);
        }

        if (! $this->checkParameterValues($callable['parameters'], $env)) {
            return $this->unknown('Argument representation does not satisfy a proven parameter contract.', $this->point($frame, $site));
        }

        if ($receiver !== null) {
            $env['this'] = $receiver;
        }

        $this->evidence->count('resolved_calls');

        return $this->visit($this->execute($callable, $env, $frame['depth'] + 1), $this->point($frame, $site, 'call '.$callable['name']));
    }

    /**
     * Allow public calls and same-class access without inferring inheritance visibility.
     */
    private function checkVisibility(array $callable, array $frame): bool
    {
        return $callable['visibility'] === 'public' || $callable['class'] === null || $callable['class'] === $frame['class'];
    }

    /**
     * Resolve an invocation environment from positional, named and default argument contracts.
     */
    private function invocationBindings(array $parameters, array $args, array $frame, array $site): ?array
    {
        $env = [];
        $position = 0;
        foreach ($args as $argument) {
            if ($argument['unpack']) {
                $this->unknown('Argument unpacking is unsupported.', $this->point($frame, $site));

                return null;
            }

            $parameter = $this->parameter($parameters, $argument['name'], $position);
            if ($parameter === null || isset($env[$parameter['name']])) {
                $this->unknown('Argument binding is unknown or ambiguous.', $this->point($frame, $site));

                return null;
            }

            $env[$parameter['name']] = $argument['value'];
            $position++;
        }

        foreach ($parameters as $parameter) {
            if (array_key_exists($parameter['name'], $env)) {
                continue;
            }

            if ($parameter['default'] === null) {
                $this->unknown('A required argument is missing.', $this->point($frame, $site));

                return null;
            }

            $env[$parameter['name']] = $this->expression($parameter['default'], $env, $frame);
        }

        return $env;
    }

    /**
     * Reject impossible typed calls and unsupported type relations before interpreting their bodies.
     */
    private function checkParameterValues(array $parameters, array $env): bool
    {
        foreach ($parameters as $parameter) {
            if (! $this->checkParameterValue($parameter['type'], $env[$parameter['name']])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Match strict native types or exact declared classes; mixed inputs retain unknown domain meaning.
     */
    private function checkParameterValue(?string $type, array $value): bool
    {
        if ($type === null || $type === 'mixed') {
            return true;
        }

        if (in_array($type, [self::TYPE_STRING, self::TYPE_INT, self::TYPE_FLOAT, self::TYPE_BOOL, self::TYPE_ARRAY, self::TYPE_NULL], true)) {
            return $value['type'] === $type;
        }

        $declaration = $this->types[$type] ?? null;
        if ($declaration === null || ! $declaration['eligible']) {
            return false;
        }

        return $value['type'] === 'object' && ($value['exact'] ?? false) === true && $value['class'] === $type;
    }

    /**
     * Locate an argument by declared name or positional slot.
     */
    private function parameter(array $parameters, ?string $name, int $position): ?array
    {
        if ($name === null) {
            return $parameters[$position] ?? null;
        }

        foreach ($parameters as $parameter) {
            if ($parameter['name'] === $name) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * Compare jointly correlated reconstruction slots before retaining a structural witness.
     */
    private function reconstructed(array $object, array $frame, array $site): array
    {
        $traces = [];
        foreach ($object['fields'] as $value) {
            foreach ($value['traces'] as $trace) {
                if ($trace['representation'] !== $object['class']) {
                    continue;
                }

                $traces[$trace['origin']] = $trace;
            }
        }

        foreach ($traces as $trace) {
            $point = $this->point($frame, $site, 'reconstruct '.$object['class']);
            if ($trace['concept'] === 'changed') {
                $key = $point['file'].':'.$point['line'].':'.$trace['origin'];
                $this->evidence->exclude($key, [...$trace['path'], $point]);

                continue;
            }

            if (! $trace['processed']) {
                continue;
            }

            if ($trace['epoch'] !== $this->epoch || ! $this->checkCompatible($object, $trace)) {
                $this->gap(self::TYPE_UNKNOWN, 'Reconstruction slots, elements or effects cannot be jointly matched.', $point, null, $trace['path']);

                continue;
            }

            $this->candidate($trace, $point);
        }

        return $object;
    }

    /**
     * Require field identity, original instance correlation and known native representations.
     */
    private function checkCompatible(array $object, array $trace): bool
    {
        foreach ($trace['fields'] as $field => $descriptor) {
            $fields = $object['fields'];
            $value = $fields[$field] ?? $this->value(self::TYPE_UNKNOWN);
            if ($descriptor === self::TYPE_UNKNOWN || $this->descriptor($value) !== $descriptor) {
                return false;
            }

            $correlated = false;
            foreach ($value['traces'] as $slot) {
                if ($slot['origin'] === $trace['origin'] && $slot['field'] === $field && $slot['epoch'] === $this->epoch) {
                    $correlated = true;
                }
            }

            if (! $correlated) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deduplicate declaration transitions while retaining a complete canonical path.
     */
    private function candidate(array $trace, array $point): void
    {
        $extraction = $trace['extraction'];
        $key = $trace['representation'].':'.$extraction['file'].':'.$extraction['line'].':'.$extraction['callable'].':'.$point['file'].':'.$point['line'].':'.$point['callable'];
        $type = $this->types[$trace['representation']];
        $name = $type['name'];
        $candidate = [
            'status'               => 'structural', 'representation' => $name, 'data_dependency' => 'confirmed',
            'concept_preservation' => self::TYPE_UNKNOWN, 'compatibility' => 'confirmed', 'effects' => 'known',
            'path'                 => [...$trace['path'], $point], 'extraction' => $extraction, 'reconstruction' => $point,
            'message'              => 'Extraction and reconstruction of '.$name.' are connected through processing contracts. Meaning and boundary purpose remain unknown; inspect the operations before considering representation changes.',
        ];
        $this->evidence->candidate($key, $candidate);
    }

    /**
     * Count every interpreted operation and disclose global exhaustion.
     */
    private function checkStep(array $frame, array $site): bool
    {
        if ($this->work >= $this->maxSteps) {
            $this->gap(self::STATUS_TRUNCATED, 'Global interpretation budget exhausted.', $this->point($frame, $site), self::LIMIT_STEPS);

            return false;
        }

        $this->work++;

        return true;
    }

    /**
     * Make unresolved operations visible and invalidate all possibly affected evidence.
     */
    private function unknown(string $reason, array $point, ?string $limit = null): array
    {
        $this->epoch++;
        $this->gap($limit === null ? self::TYPE_UNKNOWN : self::STATUS_TRUNCATED, $reason, $point, $limit);

        return $this->value(self::TYPE_UNKNOWN);
    }

    /**
     * Record bounded, deduplicated gaps including exhaustion before any witness exists.
     */
    private function gap(string $status, string $reason, array $point, ?string $limit = null, array $path = []): void
    {
        $this->evidence->gap($status, $reason, $point, $limit, $path);
    }

    /**
     * Associate every evidence step with its declaration and expression position.
     */
    private function point(array $frame, array $site = [], string $operation = 'processing'): array
    {
        return ['file' => $frame['file'], 'line' => $site['line'] ?? $frame['line'],
            'callable' => $frame['name'] ?? $frame['callable'] ?? '', 'operation' => $operation];
    }
}
