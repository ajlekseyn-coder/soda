<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Design;

/**
 * Groups proved local relationships without interpreting components as responsibilities.
 *
 * @internal
 */
trait OwnerGroups
{
    /**
     * Return groups only when all declared instance relationships are known.
     */
    private function groups(array $type): array
    {
        $edges = $this->ownerEdges($type);
        if ($edges === null) {
            return [];
        }

        $methods = $type['methods'];
        $remaining = array_fill_keys(array_keys($methods), true);
        $groups = [];
        while ($remaining !== []) {
            $pending = [array_key_first($remaining)];
            $members = [];
            while ($pending !== []) {
                $name = array_pop($pending);
                if (! isset($remaining[$name])) {
                    continue;
                }

                unset($remaining[$name]);
                $members[$name] = $methods[$name];
                array_push($pending, ...$edges[$name]);
            }

            $groups[] = $members;
        }

        return $groups;
    }

    /**
     * Prove connections through shared fields and resolved direct local calls.
     */
    private function ownerEdges(array $type): ?array
    {
        if (! $type['eligible']) {
            return null;
        }

        $methods = $type['methods'];
        $edges = array_fill_keys(array_keys($methods), []);
        foreach ($methods as $name => $method) {
            if ($method['unknown'] || $method['static']) {
                return null;
            }

            foreach ($method['calls'] as $target) {
                if (! isset($methods[$target])) {
                    return null;
                }

                $edges = $this->connectOwners($edges, $name, $target);
            }

            foreach ($methods as $other => $candidate) {
                if (array_intersect($method['fields'], $candidate['fields']) !== []) {
                    $edges = $this->connectOwners($edges, $name, $other);
                }
            }
        }

        return $edges;
    }

    /**
     * Preserve undirected local relationships without multiplying duplicate edges.
     */
    private function connectOwners(array $edges, string $left, string $right): array
    {
        $neighbors = $edges[$left];
        $neighbors[] = $right;
        $edges[$left] = array_values(array_unique($neighbors));
        $neighbors = $edges[$right];
        $neighbors[] = $left;
        $edges[$right] = array_values(array_unique($neighbors));

        return $edges;
    }

    /**
     * Require state-changing behavior and a decision, not a group of trivial accessors.
     */
    private function isConcern(array $methods, int $minimum): bool
    {
        $writes = [];
        $decisions = 0;
        $public = 0;
        foreach ($methods as $method) {
            array_push($writes, ...$method['writes']);
            $decisions += (int) $method['meaningful'];
            $public += (int) $method['public'];
            if ($method['foreign'] !== []) {
                return false;
            }
        }

        return count($methods) >= $minimum && $writes !== [] && $decisions > 0 && $public >= 2;
    }

    /**
     * Preserve all participating member locations and required owner fields.
     */
    private function concernEvidence(array $type, array $methods): array
    {
        $fields = [];
        $rows = [];
        foreach ($methods as $method) {
            array_push($fields, ...$method['fields']);
            $rows[] = ['name' => $type['name'].'::'.$method['name'].'()', 'file' => $type['file'], 'line' => $method['line']];
        }

        $fields = array_values(array_unique($fields));
        sort($fields);

        return ['fields' => $fields, 'rows' => $rows];
    }
}
