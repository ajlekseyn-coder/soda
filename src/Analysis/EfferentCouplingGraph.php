<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

use function array_pop;
use function count;
use function strcasecmp;

/**
 * Mutable Ce state: dependency edges and class/trait stack.
 *
 * @internal
 */
final class EfferentCouplingGraph
{
    /**
     * Distinct outgoing dependency names grouped by owning class.
     *
     * @var array<string, array<string, true>>
     */
    private array $dependencies = [];

    /**
     * Nested class scopes retaining their owner and parent names.
     *
     * @var list<array{name: non-empty-string, parent: ?non-empty-string}>
     */
    private array $frames = [];

    /**
     * Enter a class scope and initialize its outgoing dependency set.
     *
     * @psalm-param non-empty-string $name
     * @psalm-param non-empty-string|null $parent
     */
    public function pushFrame(string $name, ?string $parent): void
    {
        $this->frames[] = ['name' => $name, 'parent' => $parent];
        $this->dependencies[$name] ??= [];
    }

    /**
     * Pop frame using the current analysis state.
     */
    public function popFrame(): void
    {
        if ($this->frames !== []) {
            array_pop($this->frames);
        }
    }

    /**
     * Return the class owning references in the active traversal scope.
     *
     * @psalm-return non-empty-string|null
     */
    public function currentOwner(): ?string
    {
        $last = array_key_last($this->frames);
        $frame = $last === null ? null : $this->frames[$last];

        return $frame['name'] ?? null;
    }

    /**
     * Return the parent class of the active traversal scope.
     *
     * @psalm-return non-empty-string|null
     */
    public function currentParent(): ?string
    {
        $last = array_key_last($this->frames);
        $frame = $last === null ? null : $this->frames[$last];

        return $frame['parent'] ?? null;
    }

    /**
     * Record an outgoing dependency for the active owner while excluding self references.
     *
     * @psalm-param non-empty-string $fqcn
     */
    public function addEdge(string $fqcn): void
    {
        $owner = $this->currentOwner();

        if ($owner === null) {
            return;
        }

        $isSelfReference = strcasecmp($fqcn, $owner) === 0;

        if ($isSelfReference) {
            return;
        }

        $edges = $this->dependencies[$owner] ?? [];
        $edges[$fqcn] = true;
        $this->dependencies[$owner] = $edges;
    }

    /**
     * Return the number of distinct outgoing dependencies for each class.
     *
     * @psalm-return array<string, int>
     */
    public function couplingCountsByClass(): array
    {
        $out = [];

        foreach ($this->dependencies as $class => $set) {
            $out[$class] = count($set);
        }

        return $out;
    }

    /**
     * Return distinct outgoing dependency names grouped by owning class.
     *
     * @return array<string, list<string>>
     */
    public function dependenciesByClass(): array
    {
        return array_map(array_keys(...), $this->dependencies);
    }
}
