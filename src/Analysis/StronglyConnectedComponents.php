<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

/**
 * Tarjan's linear-time partition of a directed graph.
 */
final class StronglyConnectedComponents
{
    /**
     * Discovery order for each visited node.
     */
    private array $indices = [];

    /**
     * Earliest discovery reachable from the active depth-first subtree.
     */
    private array $low = [];

    /**
     * Nodes awaiting assignment to a component.
     */
    private array $stack = [];

    /**
     * Membership of the pending component stack.
     */
    private array $active = [];

    /**
     * Completed strongly connected components.
     */
    private array $components = [];

    /**
     * Next discovery index.
     */
    private int $next = 0;

    /**
     * Find strongly connected graph components after resetting the traversal state.
     *
     * @param array<string, list<string>> $graph
     */
    public function collect(array $graph): array
    {
        $this->indices = [];
        $this->low = [];
        $this->stack = [];
        $this->active = [];
        $this->components = [];
        $this->next = 0;
        foreach (array_keys($graph) as $node) {
            if (! isset($this->indices[$node])) {
                $this->visit($node, $graph);
            }
        }

        return $this->components;
    }

    /**
     * Breadth-first search gives a shortest cycle through the component's first module.
     *
     * @param array<string, list<string>> $graph
     * @param non-empty-list<string>      $component
     *
     * @return list<string>
     */
    public static function cyclePath(array $graph, array $component): array
    {
        $start = $component[0];
        $members = array_fill_keys($component, true);
        $parents = [$start => null];
        $queue = [$start];
        $counter = 1;
        for ($index = 0; $index < $counter; $index++) {
            $node = $queue[$index];
            foreach ($graph[$node] ?? [] as $target) {
                if (! isset($members[$target])) {
                    continue;
                }

                if ($target === $start) {
                    return self::closePath($parents, $node, $start);
                }

                if (! array_key_exists($target, $parents)) {
                    $parents[$target] = $node;
                    $queue[] = $target;
                    $counter = count($queue);
                }
            }
        }

        throw new \LogicException('A strongly connected module component must contain a cycle.');
    }

    /**
     * Reconstruct actual graph edges, then repeat the starting module to close the cycle.
     *
     * @param array<string, string|null> $parents
     *
     * @return list<string>
     */
    private static function closePath(array $parents, string $node, string $start): array
    {
        $path = [$start];
        $current = $node;
        while ($current !== $start) {
            $path[] = $current;
            $current = $parents[$current];
        }

        $path[] = $start;

        return array_reverse($path);
    }

    /**
     * Visit outgoing edges, then remove a completed strongly connected component from the stack.
     */
    private function visit(string $node, array $graph): void
    {
        $discovered = $this->next++;
        $this->indices[$node] = $discovered;
        $this->low[$node] = $discovered;
        $this->stack[] = $node;
        $this->active[$node] = true;
        foreach ($graph[$node] ?? [] as $target) {
            if (! isset($this->indices[$target])) {
                $this->visit($target, $graph);
                $this->low[$node] = min($this->low[$node], $this->low[$target]);

                continue;
            }

            if (isset($this->active[$target])) {
                $this->low[$node] = min($this->low[$node], $this->indices[$target]);
            }
        }

        if ($this->low[$node] !== $this->indices[$node]) {
            return;
        }

        $this->closeComponent($node);
    }

    /**
     * Pop the completed strongly connected component rooted at this node from the stack.
     */
    private function closeComponent(string $root): void
    {
        $component = [];
        do {
            $member = array_pop($this->stack);
            unset($this->active[$member]);
            $component[] = $member;
        } while ($member !== $root);

        sort($component);
        $this->components[] = $component;
    }
}
