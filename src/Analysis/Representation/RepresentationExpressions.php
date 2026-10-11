<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Representation;

/**
 * Expression transfer rules belonging to the bounded representation interpreter.
 */
trait RepresentationExpressions
{
    /**
     * Evaluate one scalar IR expression without executing application code.
     */
    private function expression(array $node, array &$env, array $frame): array
    {
        if (! $this->checkStep($frame, $node)) {
            return $this->unknown('Expression work budget exhausted.', $this->point($frame, $node), 'maxSteps');
        }

        return $this->evaluateExpression($node, $env, $frame);
    }

    /**
     * Dispatch the collected expression protocol after its work budget is charged.
     */
    private function evaluateExpression(array $node, array &$env, array $frame): array
    {
        return match ($node['op']) {
            'variable' => $this->binding($node, $env, $frame),
            'literal'  => [...$this->value($node['type']), 'literal' => $node['value']],
            'property' => $this->property($node, $env, $frame),
            'call'     => $this->call($node, $env, $frame),
            'new'      => $this->construct($node, $env, $frame),
            'binary'   => $this->binary($node, $env, $frame),
            'cast'     => $this->cast($node, $env, $frame),
            'array'    => $this->arrayValue($node, $env, $frame),
            default    => $this->unsupportedExpression($node, $frame),
        };
    }

    /**
     * Keep the collector reason and source point for unsupported expression protocols.
     */
    private function unsupportedExpression(array $node, array $frame): array
    {
        return $this->unknown($node['reason'] ?? 'Unsupported expression.', $this->point($frame, $node));
    }

    /**
     * Resolve one local binding while retaining an explicit unresolved origin.
     */
    private function binding(array $node, array $env, array $frame): array
    {
        return $env[$node['name']] ?? $this->unknown('Local binding is unknown.', $this->point($frame, $node));
    }

    /**
     * Seed an entry contract with independent origins and known native field types.
     */
    private function seed(?string $type, string $origin, int $depth = 0): array
    {
        $types = $this->types;
        $declaration = $types[$type ?? ''] ?? null;
        if ($this->work >= $this->maxSteps) {
            $location = $declaration['file'] ?? '';
            $this->gap('truncated', 'Entry state budget exhausted.', ['file' => $location, 'line' => 1, 'callable' => $origin, 'operation' => 'entry state'], 'maxSteps');

            return $this->value('unknown');
        }

        $this->work++;
        if ($type === null || $depth > 4) {
            return $this->value('unknown');
        }

        if ($declaration === null) {
            return $this->value(in_array($type, ['string', 'int', 'float', 'bool', 'array', 'null'], true) ? $type : 'unknown');
        }

        $object = [...$this->value('object'), 'class' => $type, 'origin' => $origin.':'.++$this->serial,
            'exact'                                   => $declaration['final'], 'fields' => []];
        $fields = [];
        $properties = $declaration['eligible'] ? $declaration['properties'] : [];
        foreach ($properties as $name => $fieldType) {
            $fields[$name] = $this->seed($fieldType, $object['origin'].':'.$name, $depth + 1);
        }

        $object['fields'] = $fields;

        return $object;
    }

    /**
     * Create an abstract native value with bounded evidence containers.
     */
    private function value(string $type): array
    {
        return ['type' => $type, 'traces' => [], 'history' => [], 'descriptor' => $type === 'array' ? 'unknown' : $type];
    }

    /**
     * Obtain the representation descriptor without equating unknown element contracts.
     */
    private function descriptor(array $value): string
    {
        if ($value['type'] === 'object') {
            return ($value['exact'] ?? false) ? 'object:'.$value['class'] : 'unknown';
        }

        return $value['descriptor'];
    }

    /**
     * Attach a proven field extraction while retaining earlier nested representation paths.
     */
    private function property(array $node, array &$env, array $frame): array
    {
        $receiver = $this->expression($node['receiver'], $env, $frame);
        $types = $this->types;
        $type = $types[$receiver['class'] ?? ''] ?? null;
        $stored = $receiver['fields'] ?? [];
        $value = $stored[$node['name']] ?? null;
        if ($type === null || ! $type['eligible'] || ! $receiver['exact'] || $value === null) {
            return $this->unknown('Property receiver, hooks or field storage are unresolved.', $this->point($frame, $node));
        }

        if ($receiver['class'] !== $frame['class']) {
            return $this->unknown('Field visibility cannot be established outside its declaring class.', $this->point($frame, $node));
        }

        $point = $this->point($frame, $node, 'extract '.$type['name'].'::$'.$node['name']);
        $fields = array_map(fn (array $field): string => $this->descriptor($field), $receiver['fields']);
        $trace = ['origin' => $receiver['origin'], 'representation' => $receiver['class'], 'field' => $node['name'],
            'fields'       => $fields, 'extraction' => $point, 'path' => [...$receiver['history'], $point],
            'processed'    => false, 'concept' => 'unknown', 'epoch' => $this->epoch];
        $value['traces'] = [...$value['traces'], $trace];

        return $this->visit($value, $point);
    }

    /**
     * Resolve exact dispatch or explicit core contracts before transferring argument evidence.
     */
    private function call(array $node, array &$env, array $frame): array
    {
        $receiver = $node['receiver'] === null ? null : $this->expression($node['receiver'], $env, $frame);
        $args = $this->arguments($node['args'], $env, $frame);
        if ($node['kind'] === 'function') {
            $callables = $this->callables;
            $callable = $callables[$node['name']] ?? null;
            if ($callable !== null) {
                return $this->invoke($callable, $args, null, $frame, $node);
            }

            return $this->core($node, $args, $frame);
        }

        return $this->methodCall($node, $args, $receiver, $frame);
    }

    /**
     * Validate receiver dispatch and declaration eligibility before interpreting a method.
     */
    private function methodCall(array $node, array $args, ?array $receiver, array $frame): array
    {
        $typeId = $node['class'] ?? $receiver['class'] ?? null;
        $types = $this->types;
        $callables = $this->callables;
        $type = $types[$typeId ?? ''] ?? null;
        $methods = $type['methods'] ?? [];
        $id = $methods[$node['name']] ?? null;
        $callable = $callables[$id ?? ''] ?? null;
        $exact = $receiver['exact'] ?? false;
        if ($type === null || ! $type['eligible'] || $callable === null) {
            return $this->unknown('Method target or composed contract is unknown.', $this->point($frame, $node));
        }

        $fixed = $type['final'] || $callable['final'] || $callable['visibility'] === 'private' || $exact;
        $invalidStatic = $node['kind'] === 'static' && ! $callable['static'];
        if (! $fixed || $invalidStatic) {
            return $this->unknown('Virtual dispatch is not proven.', $this->point($frame, $node));
        }

        return $this->invoke($callable, $args, $receiver, $frame, $node);
    }

    /**
     * Evaluate each argument at its own source location before binding named inputs.
     */
    private function arguments(array $arguments, array &$env, array $frame): array
    {
        return array_map(fn (array $argument): array => [...$argument,
            'value' => $this->expression($argument['value'], $env, $frame)], $arguments);
    }

    /**
     * Reconstruct declared fields only through the actual known constructor body.
     */
    private function construct(array $node, array &$env, array $frame): array
    {
        $args = $this->arguments($node['args'], $env, $frame);
        $types = $this->types;
        $type = $types[$node['class']] ?? null;
        if ($type === null || ! $type['eligible']) {
            return $this->unknown('Constructor or representation declaration is unknown.', $this->point($frame, $node));
        }

        $object = $this->seed($node['class'], $frame['id'].':new');
        $object['exact'] = true;
        $id = $type['constructor'];
        if ($id !== null) {
            $callables = $this->callables;
            $callable = $callables[$id];
            $object = $this->invoke($callable, $args, $object, $frame, $node);
        }

        if ($object['type'] !== 'object') {
            return $object;
        }

        $object = $this->visit($object, $this->point($frame, $node, 'create '.$type['name']));

        return $this->reconstructed($object, $frame, $node);
    }

    /**
     * Apply versioned pure core profiles to exact global function names only.
     */
    private function core(array $node, array $args, array $frame): array
    {
        $contract = $this->coreContract($node['name']);
        if ($contract === null) {
            return $this->unknown('No applicable exact core profile for '.$node['name'].'.', $this->point($frame, $node));
        }

        $bound = $this->bindProfile($contract, $args);
        if ($bound === null) {
            return $this->unknown('Core profile argument binding is unknown.', $this->point($frame, $node));
        }

        $source = $bound[$contract['source']] ?? $this->value('unknown');
        $point = $this->point($frame, $node, 'profile php-core-v1:'.$node['name']);

        return $this->profileValue($contract['kind'], $source, $bound, $point);
    }

    /**
     * Describe exact global core profiles with named argument and result contracts.
     */
    private function coreContract(string $name): ?array
    {
        $text = ['kind' => 'string', 'min' => 1, 'max' => 1, 'names' => ['string'], 'source' => 'string'];
        $trim = [...$text, 'max' => 2, 'names' => ['string', 'characters']];
        $array = ['kind' => 'array', 'min' => 1, 'max' => 1, 'names' => ['array'], 'source' => 'array'];
        $contracts = [
            'trim'          => $trim, 'ltrim' => $trim, 'rtrim' => $trim,
            'strtolower'    => $text, 'strtoupper' => $text,
            'str_replace'   => ['kind' => 'replace', 'min' => 3, 'max' => 3, 'names' => ['search', 'replace', 'subject'], 'source' => 'subject'],
            'strlen'        => [...$text, 'kind' => 'changed:int'],
            'md5'           => [...$text, 'kind' => 'changed:string'], 'sha1' => [...$text, 'kind' => 'changed:string'],
            'hash'          => ['kind' => 'hash', 'min' => 2, 'max' => 2, 'names' => ['algo', 'data'], 'source' => 'data'],
            'array_reverse' => [...$array, 'kind' => 'reverse', 'max' => 2, 'names' => ['array', 'preserve_keys']],
            'array_values'  => [...$array, 'kind' => 'values'], 'array_filter' => $array,
        ];

        return $contracts[$name] ?? null;
    }

    /**
     * Bind supported profile arguments without coercion, unpacking or ambiguous slots.
     */
    private function bindProfile(array $contract, array $args): ?array
    {
        if (count($args) < $contract['min'] || count($args) > $contract['max']) {
            return null;
        }

        $names = $contract['names'];
        $bound = [];
        foreach ($args as $index => $arg) {
            $name = $arg['name'] ?? $names[$index];
            if ($arg['unpack'] || ! in_array($name, $names, true) || isset($bound[$name])) {
                return null;
            }

            $bound[$name] = $arg['value'];
        }

        return $bound;
    }

    /**
     * Keep concept-changing profiles separate from ordinary text or container processing.
     */
    private function profileValue(string $kind, array $source, array $bound, array $point): array
    {
        $arrayOperation = in_array($kind, ['array', 'reverse', 'values'], true);
        $expected = $arrayOperation ? 'array' : 'string';
        if ($source['type'] !== $expected || ! $this->hasProfileInputs($bound, $arrayOperation)) {
            return $this->unknown('Core profile input representation is unresolved.', $point);
        }

        $changed = str_starts_with($kind, 'changed:') || $kind === 'hash';
        $changedType = $kind === 'changed:int' ? 'int' : 'string';
        $type = $changed ? $changedType : $source['type'];
        $value = [...$source, 'type' => $type];
        if ($source['type'] !== 'array') {
            $value['descriptor'] = $type;
        }

        $value = $this->profileDescriptor($value, $kind, $bound);

        return $this->processed($value, $point, $changed);
    }

    /**
     * Preserve only proven array key contracts when applying container profiles.
     */
    private function profileDescriptor(array $value, string $kind, array $bound): array
    {
        if ($kind === 'values') {
            $value['descriptor'] = $value['descriptor'] === 'unknown' ? 'unknown' : preg_replace('/keys=[^;]+/', 'keys=int', $value['descriptor']);
        }

        $preserveKeys = $bound['preserve_keys'] ?? [];
        $losesKeyContract = ($preserveKeys['literal'] ?? false) !== true;
        if ($kind === 'reverse' && $losesKeyContract) {
            $value['descriptor'] = 'unknown';
        }

        return $value;
    }

    /**
     * Validate every optional profile input without implicit scalar or object coercion.
     */
    private function hasProfileInputs(array $bound, bool $arrayOperation): bool
    {
        foreach ($bound as $name => $value) {
            $arrayType = $name === 'array' ? 'array' : 'bool';
            $expected = $arrayOperation ? $arrayType : 'string';
            if ($value['type'] !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * Transfer dependencies through eager native arithmetic and concatenation only.
     */
    private function binary(array $node, array &$env, array $frame): array
    {
        if (! in_array($node['operator'], ['.', '+', '-', '*'], true)) {
            return $this->unknown('Conditional, division, modulo or exponent contract is unresolved.', $this->point($frame, $node));
        }

        $left = $this->expression($node['left'], $env, $frame);
        $right = $this->expression($node['right'], $env, $frame);
        if (! $this->checkBinaryOperands($node['operator'], $left, $right)) {
            return $this->unknown('Binary operand or integer overflow contract is unresolved.', $this->point($frame, $node));
        }

        $type = $node['operator'] === '.' ? 'string' : 'float';
        $value = [...$this->value($type), 'traces' => [...$left['traces'], ...$right['traces']], 'history' => [...$left['history'], ...$right['history']]];

        return $this->processed($value, $this->point($frame, $node, 'binary '.$node['operator']));
    }

    /**
     * Prove concatenation or floating arithmetic without assuming bounds for integer overflow.
     */
    private function checkBinaryOperands(string $operator, array $left, array $right): bool
    {
        if ($operator === '.') {
            return $left['type'] === 'string' && $right['type'] === 'string';
        }

        $numeric = in_array($left['type'], ['int', 'float'], true) && in_array($right['type'], ['int', 'float'], true);
        $floating = $left['type'] === 'float' || $right['type'] === 'float';

        return $numeric && $floating;
    }

    /**
     * Preserve native dependencies across supported casts without invoking object conversion.
     */
    private function cast(array $node, array &$env, array $frame): array
    {
        $value = $this->expression($node['value'], $env, $frame);
        if (! in_array($value['type'], ['string', 'int', 'float', 'bool'], true) || ! in_array($node['type'], ['string', 'int', 'float', 'bool'], true)) {
            return $this->unknown('Cast contract or object conversion is unresolved.', $this->point($frame, $node));
        }

        $value['type'] = $node['type'];
        $value['descriptor'] = $node['type'];

        return $this->processed($value, $this->point($frame, $node, 'cast '.$node['type']));
    }

    /**
     * Describe literal array key and element types without losing slot dependencies.
     */
    private function arrayValue(array $node, array &$env, array $frame): array
    {
        $value = $this->value('array');
        $keys = [];
        $elements = [];
        foreach ($node['items'] as $item) {
            $key = $item['key'] === null ? $this->value('int') : $this->expression($item['key'], $env, $frame);
            $element = $this->expression($item['value'], $env, $frame);
            $keys[] = $key['type'];
            $elements[] = $this->descriptor($element);
            array_push($value['traces'], ...$element['traces']);
        }

        sort($keys);
        sort($elements);
        $value['descriptor'] = in_array('unknown', $elements, true) ? 'unknown' : 'array;keys='.implode('|', array_unique($keys)).';elements='.implode('|', array_unique($elements));

        return $value;
    }

    /**
     * Mark actual processing and known changes of concept without guessing domain semantics.
     */
    private function processed(array $value, array $point, bool $changed = false): array
    {
        foreach ($value['traces'] as &$trace) {
            $trace['processed'] = true;
            if ($changed) {
                $trace['concept'] = 'changed';
            }
        }

        return $this->visit($value, $point);
    }

    /**
     * Append bounded locations through calls, processing and object construction.
     */
    private function visit(array $value, array $point): array
    {
        if (count($value['history']) < $this->maxSteps) {
            $value['history'] = [...$value['history'], $point];
        }

        foreach ($value['traces'] as &$trace) {
            if (count($trace['path']) >= $this->maxSteps) {
                $this->gap('truncated', 'Witness path budget exhausted.', $point, 'maxSteps');

                continue;
            }

            if (end($trace['path']) !== $point) {
                $trace['path'] = [...$trace['path'], $point];
            }
        }

        return $value;
    }
}
