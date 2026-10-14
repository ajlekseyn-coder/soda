<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Architecture;

/**
 * Resolves ordinary inheritance and trait selections from compact declaration facts.
 */
trait CohesionComposition
{
    /**
     * Compose only known declarations; missing/cyclic relationships remain explicit unknowns.
     */
    private function surface(string $name, array $types, array $seen = []): array
    {
        if (isset($this->surfaces[$name])) {
            return $this->surfaces[$name];
        }

        $isMissing = ! isset($types[$name]) || isset($seen[$name]);
        if ($isMissing) {
            return ['methods' => [], 'properties' => [], 'unknown' => ['unresolved or cyclic type '.$name]];
        }

        $seen[$name] = true;
        $type = $types[$name];
        $base = $this->inheritedSurface($type, $types, $seen);
        $composed = $this->importedSurface($type, $types, $seen, $base);
        $this->surfaces[$name] = $this->mergeSurface($composed, $type);

        return $this->surfaces[$name];
    }

    /**
     * Start from the parent surface, keeping inherited private identity an explicit unknown.
     *
     * @param array $type Declared facts of the type being resolved.
     */
    private function inheritedSurface(array $type, array $types, array $seen): array
    {
        $base = ['methods' => [], 'properties' => [], 'unknown' => $type['unknown']];
        if ($type['parent'] === null) {
            return $base;
        }

        $base = $this->surface(strtolower($type['parent']), $types, $seen);
        $hasPrivateState = in_array('private', $base['properties'], true)
            || in_array('private', array_column($base['methods'], 'visibility'), true);
        if ($hasPrivateState) {
            $base['unknown'] = [...$base['unknown'], 'inherited private member identity requires scope resolution'];
        }

        return $base;
    }

    /**
     * Merge every use group in declared order, flagging conflicts between groups.
     *
     * @param array $type Declared facts of the type being resolved.
     * @param array $base Surface accumulated so far for the type.
     */
    private function importedSurface(array $type, array $types, array $seen, array $base): array
    {
        $imported = [];
        foreach ($type['uses'] as $use) {
            $import = $this->traitSurface($use, $types, $seen, $type['methods']);
            $conflicts = array_diff_key(array_intersect_key($imported, $import['methods']), $type['methods']);
            foreach (array_keys($conflicts) as $method) {
                $import['unknown'] = [...$import['unknown'], 'trait conflict across use groups '.$method];
            }

            $imported += $import['methods'];
            $base = $this->mergeSurface($base, $import);
        }

        return $base;
    }

    /**
     * Resolve one use group, preserving excluded implementations for explicit aliases.
     */
    private function traitSurface(array $use, array $types, array $seen, array $own): array
    {
        $sources = [];
        $base = ['methods' => [], 'properties' => [], 'unknown' => []];
        foreach ($use['traits'] as $name) {
            $base = $this->mergeTrait($base, $name, $this->surface($name, $types, $seen), $sources);
        }

        $selected = $this->selectTraits($sources, $use['adaptations']);
        foreach ($selected as $method => $candidates) {
            $base = $this->applySelection($base, $own, $method, $candidates);
        }

        return $this->aliasTraits($base, $sources, $selected, $use['adaptations']);
    }

    /**
     * Merge one trait's surface into the group, tracking per-method candidate sources.
     *
     * @param array $base   Group surface accumulated so far.
     * @param array $trait  Resolved surface of the imported trait.
     * @param array $sources Candidate method bodies by trait name, updated in place.
     */
    private function mergeTrait(array $base, string $name, array $trait, array &$sources): array
    {
        $duplicates = array_intersect_key($base['properties'], $trait['properties']);
        foreach (array_keys($duplicates) as $property) {
            $base['unknown'] = [...$base['unknown'], 'trait property compatibility unresolved '.$property];
        }

        $base['properties'] += $trait['properties'];
        $base['unknown'] = [...$base['unknown'], ...$trait['unknown']];
        foreach ($trait['methods'] as $method => $body) {
            $candidates = $sources[$method] ?? [];
            $candidates[$name] = $body;
            $sources[$method] = $candidates;
        }

        return $base;
    }

    /**
     * Add one selected trait implementation unless it conflicts with the owning declaration.
     *
     * @param array $base       Group surface accumulated so far.
     * @param array $own        Methods declared by the owning type itself.
     * @param array $candidates Trait bodies proposed for one method name.
     */
    private function applySelection(array $base, array $own, string $method, array $candidates): array
    {
        $isConflict = count($candidates) !== 1 && ! isset($own[$method]);
        if ($isConflict) {
            $base['unknown'] = [...$base['unknown'], 'trait method conflict '.$method];

            return $base;
        }

        $body = reset($candidates);
        if (is_array($body)) {
            $methods = $base['methods'];
            $methods[$method] = $body;
            $base['methods'] = $methods;
        }

        return $base;
    }

    /**
     * A property redeclaration can alter identity/visibility and is not silently flattened.
     */
    private function mergeSurface(array $base, array $own): array
    {
        $duplicates = array_intersect_key($base['properties'], $own['properties']);
        $unknown = [...$base['unknown'], ...$own['unknown']];
        foreach (array_keys($duplicates) as $property) {
            $unknown[] = 'redeclared property '.$property;
        }

        return [
            'methods'    => array_replace($base['methods'], $own['methods']),
            'properties' => array_replace($base['properties'], $own['properties']),
            'unknown'    => $unknown,
        ];
    }

    /**
     * Apply insteadof without treating a conflict as absent behavior.
     */
    private function selectTraits(array $sources, array $adaptations): array
    {
        foreach ($adaptations as $adaptation) {
            if ($adaptation['kind'] !== 'precedence') {
                continue;
            }

            $method = $adaptation['method'];
            $candidates = $sources[$method] ?? [];
            foreach ($adaptation['instead'] as $excluded) {
                unset($candidates[$excluded]);
            }

            $sources[$method] = $candidates;
        }

        return $sources;
    }

    /**
     * Copy the explicitly selected implementation and apply alias visibility locally.
     */
    private function aliasTraits(array $base, array $sources, array $selected, array $adaptations): array
    {
        $methods = $base['methods'];
        foreach ($adaptations as $adaptation) {
            $methods = $this->aliasMethod($methods, $base, $sources, $selected, $adaptation);
        }

        $base['methods'] = $methods;

        return $base;
    }

    /**
     * Apply one alias adaptation, recording ambiguity or unresolved targets explicitly.
     *
     * @param array $methods Method table accumulated so far for the group surface.
     * @param array $base    Group surface whose unknown evidence is updated in place.
     * @param array $sources Candidate method bodies by trait name.
     * @param array $selected Candidate bodies after insteadof exclusions.
     *
     * @return array
     */
    private function aliasMethod(array $methods, array &$base, array $sources, array $selected, array $adaptation): array
    {
        if ($adaptation['kind'] !== 'alias') {
            return $methods;
        }

        $method = $adaptation['method'];
        $candidates = $sources[$method] ?? [];
        $selectedBodies = $selected[$method] ?? [];
        $isAmbiguous = $adaptation['trait'] === null && count($selectedBodies) !== 1;
        if ($isAmbiguous) {
            $base['unknown'] = [...$base['unknown'], 'ambiguous trait alias '.$method];

            return $methods;
        }

        $body = $adaptation['trait'] === null ? reset($selectedBodies) : ($candidates[$adaptation['trait']] ?? null);
        if (! is_array($body)) {
            $base['unknown'] = [...$base['unknown'], 'unresolved trait alias '.$method];

            return $methods;
        }

        $alias = $adaptation['alias'] ?? $method;
        $body['name'] = $alias;
        $body['visibility'] = $adaptation['visibility'] ?? $body['visibility'];
        $methods[$alias] = $body;

        return $methods;
    }
}
