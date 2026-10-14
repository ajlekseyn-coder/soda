<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Usage;

/** Resolve PHP method precedence locally to the usage check. */
trait UnusedMethodComposition
{
    /**
     * Cache composed method tables for this project, retaining lexical private identities.
     */
    private function surface(string $name, array $seen = []): array
    {
        if (isset($this->surfaces[$name])) {
            return $this->surfaces[$name];
        }

        $type = $this->types[$name] ?? null;
        if ($type === null || isset($seen[$name])) {
            return $this->unknownSurface();
        }

        $seen[$name] = true;
        $surface = $this->composedSurface($name, $type, $seen);
        $this->surfaces[$name] = $surface;

        return $surface;
    }

    /**
     * Build the surface recorded for a type whose composition cannot be resolved.
     */
    private function unknownSurface(): array
    {
        return ['methods' => [], 'bodies' => [], 'unknown' => true];
    }

    /**
     * Compose one type surface from its parent chain, trait imports and own methods.
     *
     * @param array<string, mixed> $type
     */
    private function composedSurface(string $name, array $type, array $seen): array
    {
        $surface = $type['parent'] !== null ? $this->surface($type['parent'], $seen) : $this->unknownSurface();
        $surface['unknown'] = false;
        $imports = $this->imports($type, $seen);
        $surface['unknown'] = $surface['unknown'] || $imports['unknown'] || $type['duplicate'];
        $surface['methods'] = array_replace($surface['methods'], $this->scopedMethods($name, $imports['methods']), $type['methods']);
        $surface['bodies'] = $this->indexedBodies($surface['bodies'], $surface['methods']);

        return $surface;
    }

    /**
     * Attribute every imported method to the composing type that surfaces it.
     *
     * @param array<string, array<string, mixed>> $methods
     *
     * @return array<string, array<string, mixed>>
     */
    private function scopedMethods(string $name, array $methods): array
    {
        foreach ($methods as &$method) {
            $method['scope'] = $name;
        }

        unset($method);

        return $methods;
    }

    /**
     * Index composed methods by origin and surface for later call-site resolution.
     *
     * @param array<string, array<string, mixed>> $bodies
     * @param array<string, array<string, mixed>> $methods
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexedBodies(array $bodies, array $methods): array
    {
        foreach ($methods as $method) {
            $bodies[$method['origin'].'@'.$method['scope']] = $method;
        }

        return $bodies;
    }

    /**
     * Trait use groups compose independently; unresolved collisions stay explicit.
     */
    private function imports(array $type, array $seen): array
    {
        $result = ['methods' => [], 'unknown' => false];
        foreach ($type['uses'] as $use) {
            $import = $this->traitGroup($use, $seen, $type['methods']);
            $conflicts = array_diff_key(array_intersect_key($result['methods'], $import['methods']), $type['methods']);
            $result['unknown'] = $result['unknown'] || $import['unknown'] || $conflicts !== [];
            $result['methods'] += $import['methods'];
        }

        return $result;
    }

    /**
     * Preserve original sources so an excluded method can still receive an explicit alias.
     */
    private function traitGroup(array $use, array $seen, array $own): array
    {
        $sources = [];
        $result = ['methods' => [], 'unknown' => false];
        foreach ($use['traits'] as $name) {
            $trait = $this->surface($name, $seen);
            $result['unknown'] = $result['unknown'] || $trait['unknown'];
            foreach ($trait['methods'] as $method => $body) {
                $candidates = $sources[$method] ?? [];
                $candidates[$name] = $body;
                $sources[$method] = $candidates;
            }
        }

        $selected = $this->selectTraits($sources, $use['adaptations']);
        $methods = [];
        foreach ($selected as $method => $candidates) {
            if (count($candidates) !== 1) {
                $result['unknown'] = $result['unknown'] || ! isset($own[$method]);

                continue;
            }

            [$methods[$method]] = array_values($candidates);
        }

        $result['methods'] = $methods;

        return $this->aliasTraits($result, $sources, $selected, $use['adaptations']);
    }

    /**
     * Remove only explicitly excluded trait implementations.
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
     * Alias visibility belongs to the composed method, not its original declaration.
     */
    private function aliasTraits(array $result, array $sources, array $selected, array $adaptations): array
    {
        $methods = $result['methods'];
        foreach ($adaptations as $adaptation) {
            if ($adaptation['kind'] !== 'alias') {
                continue;
            }

            $name = $adaptation['method'];
            $candidates = $selected[$name] ?? [];
            if ($adaptation['trait'] !== null) {
                $implementations = $sources[$name] ?? [];
                $body = $implementations[$adaptation['trait']] ?? null;
                $candidates = $body === null ? [] : [$body];
            }

            if (count($candidates) !== 1) {
                $result['unknown'] = true;

                continue;
            }

            [$body] = array_values($candidates);
            $body['visibility'] = $adaptation['visibility'] ?? $body['visibility'];
            $methods[$adaptation['alias'] ?? $name] = $body;
        }

        $result['methods'] = $methods;

        return $result;
    }
}
