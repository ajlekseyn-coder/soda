<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Architecture;

/**
 * Computes observed method components and direct public-method field sharing (TCC).
 */
trait CohesionConnections
{
    /**
     * Shared state and direct local calls connect methods; unknown links remain separate evidence.
     */
    private function connections(array $type): array
    {
        $methods = $type['methods'];
        $edges = array_fill_keys(array_keys($methods), []);
        $state = $this->directEdges($type, $edges);
        $edges = $this->sharedFieldEdges($state['fields'], $state['edges']);
        $unknown = array_values(array_unique([...$type['unknown'], ...$state['unknown']]));
        $groups = $this->components($edges);
        $public = array_filter($methods, fn (array $method): bool => $method['visibility'] === 'public');
        $pairs = $this->sharedPairs($public);
        $possible = intdiv(count($public) * (count($public) - 1), 2);

        return [
            'groups'  => $groups, 'edges' => $edges, 'shared_fields' => $state['fields'], 'unknown' => $unknown,
            'tcc'     => $possible === 0 ? null : $pairs / $possible,
            'metrics' => ['method_groups' => count($groups), 'cohesion_methods' => count($methods),
                'cohesion_unknown'        => count($unknown), 'tcc_pairs' => $pairs, 'tcc_possible_pairs' => $possible],
        ];
    }

    /**
     * Record each method's field touches and direct calls, flagging unresolved targets.
     *
     * @param array<string, array> $edges Empty adjacency rows pre-created for every method.
     *
     * @return array{edges: array<string, array<string, true>>, fields: array<string, list<string>>, unknown: list<string>}
     */
    private function directEdges(array $type, array $edges): array
    {
        $fields = [];
        $unknown = [];
        $declared = $type['methods'];
        $connected = $edges;
        foreach ($type['methods'] as $name => $method) {
            array_push($unknown, ...$method['unknown']);
            foreach ($method['fields'] as $field) {
                $fields[$field] = [...($fields[$field] ?? []), $name];
                $isKnown = array_key_exists($field, $type['properties']);
                if (! $isKnown) {
                    $unknown[] = 'unresolved property '.$field;
                }
            }

            foreach ($method['calls'] as $target) {
                $isKnown = isset($declared[$target]);
                if (! $isKnown) {
                    $unknown[] = 'unresolved method '.$target;

                    continue;
                }

                $connected = $this->connect($connected, $name, $target);
            }
        }

        return ['edges' => $connected, 'fields' => $fields, 'unknown' => $unknown];
    }

    /**
     * Connect every pair of methods that touch the same instance field directly.
     *
     * @param array<string, list<string>> $fields
     */
    private function sharedFieldEdges(array $fields, array $edges): array
    {
        $connected = $edges;
        foreach ($fields as $members) {
            $first = reset($members);
            foreach ($members as $member) {
                $connected = $this->connect($connected, $first, $member);
            }
        }

        return $connected;
    }

    /**
     * Store undirected connectivity without edge objects or duplicate neighbors.
     */
    private function connect(array $edges, string $left, string $right): array
    {
        $neighbors = $edges[$left];
        $neighbors[$right] = true;
        $edges[$left] = $neighbors;
        $neighbors = $edges[$right];
        $neighbors[$left] = true;
        $edges[$right] = $neighbors;

        return $edges;
    }

    /**
     * Find actual graph components; a fractional lack-of-cohesion formula is not LCOM4.
     *
     * @return list<list<string>>
     */
    private function components(array $edges): array
    {
        $remaining = array_fill_keys(array_keys($edges), true);
        $groups = [];
        while ($remaining !== []) {
            $pending = [array_key_first($remaining)];
            $group = [];
            while ($pending !== []) {
                $method = array_pop($pending);
                $isRemaining = isset($remaining[$method]);
                if (! $isRemaining) {
                    continue;
                }

                unset($remaining[$method]);
                $group[] = $method;
                array_push($pending, ...array_keys($edges[$method]));
            }

            sort($group);
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * TCC counts public pairs sharing at least one literal field directly, not through calls.
     */
    private function sharedPairs(array $methods): int
    {
        $pairs = 0;
        $remaining = array_fill_keys(array_keys($methods), true);
        foreach ($methods as $name => $method) {
            unset($remaining[$name]);
            foreach (array_keys($remaining) as $otherName) {
                $other = $methods[$otherName];
                $shareState = array_intersect($method['fields'], $other['fields']) !== [];
                if ($shareState) {
                    $pairs++;
                }
            }
        }

        return $pairs;
    }
}
