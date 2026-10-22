<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Structure;

use function array_key_last;
use function array_pop;

use Cosmira\Soda\Analysis\Usage\ParameterWriteTargets;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * @internal
 */
trait TellDontAskScopes
{
    /**
     * Nested variable-to-query alias maps used when resolving conditions.
     *
     * @psalm-var list<array<string, list<array{receiver: string, receiverKey: string, method: string}>>>
     */
    private array $scopes = [];

    /**
     * Reset using the current analysis state.
     */
    private function reset(): void
    {
        $this->scopes = [[]];
    }

    /**
     * Clear using the current analysis state.
     */
    private function clear(): void
    {
        $this->scopes = [];
    }

    /**
     * Check the supplied input for scopes.
     */
    private function hasScopes(): bool
    {
        return $this->scopes !== [];
    }

    /**
     * Push scope using the current analysis state.
     */
    private function pushScope(): void
    {
        if ($this->scopes !== []) {
            $this->scopes[] = [];
        }
    }

    /**
     * Pop scope using the current analysis state.
     */
    private function popScope(): void
    {
        if ($this->scopes !== []) {
            array_pop($this->scopes);
        }
    }

    /**
     * Return the active query-alias scopes from outermost to innermost.
     *
     * @return list<array<string, list<array{receiver: string, receiverKey: string, method: string}>>>
     */
    private function all(): array
    {
        return $this->scopes;
    }

    /**
     * Associate a variable with query results in the active alias scope.
     *
     * @param list<array{receiver: string, receiverKey: string, method: string}> $questions
     */
    private function record(string $variable, array $questions): void
    {
        $scopeIndex = array_key_last($this->scopes);

        if ($scopeIndex === null) {
            return;
        }

        $top = $this->scopes[$scopeIndex];

        if ($questions === []) {
            unset($top[$variable]);
            $this->scopes[$scopeIndex] = $top;

            return;
        }

        $top[$variable] = $questions;
        $this->scopes[$scopeIndex] = $top;
    }

    /**
     * Receiver keys retain lexical binding versions without keeping AST nodes in collected facts.
     */
    private function bindReceiverKeys(Node $owner): void
    {
        if (! $owner instanceof Node\FunctionLike) {
            return;
        }

        $nodes = $this->bindingNodes($owner->getStmts() ?? []);
        $writes = [];
        $escapes = $this->incomingReferenceBindings($owner);
        $opaque = [];
        foreach ($nodes as $node) {
            $this->writtenReceiverBindings($node, $writes, $escapes, $opaque);
            $this->escapedReceiverBindings($node, $escapes);
            if ($this->hasOpaqueReceiverBindings($node)) {
                $opaque[] = $node->getEndFilePos();
            }
        }

        foreach ($nodes as $node) {
            if ($node instanceof Expr\Variable && is_string($node->name)) {
                $this->stampReceiverBinding($node, $owner->getStartFilePos(), ['writes' => $writes, 'escapes' => $escapes, 'opaque' => $opaque]);
            }
        }
    }

    /**
     * Assignments take effect after their right-hand side has been evaluated.
     *
     * @param array<string,list<int>> $writes
     * @param array<string,list<int>> $escapes
     * @param list<int>               $opaque
     */
    private function writtenReceiverBindings(Node $node, array &$writes, array &$escapes, array &$opaque): void
    {
        foreach (ParameterWriteTargets::from($node) as $target) {
            if ($target instanceof Expr\Variable && ! is_string($target->name)) {
                $opaque[] = $node->getEndFilePos();
            }

            $position = $node instanceof Expr ? $node->getEndFilePos() : $target->getEndFilePos();
            foreach (ParameterWriteTargets::bindings($target) as $name => $line) {
                $this->recordBindingEvent($writes, $name, $position);
                $this->unstableReceiverBinding($node, $name, $escapes);
            }
        }
    }

    /**
     * Loop targets and reference-bound names cannot establish an unchanged input value.
     *
     * @param array<string,list<int>> $escapes
     */
    private function unstableReceiverBinding(Node $node, string $name, array &$escapes): void
    {
        if ($node instanceof Stmt\Foreach_) {
            // A loop target can have different values on separate iterations of the same syntax.
            $this->recordBindingEvent($escapes, $name, $node->expr->getEndFilePos());

            return;
        }

        if ($node instanceof Stmt\Global_ || $node instanceof Stmt\Static_ || $node instanceof Expr\AssignRef) {
            $this->recordBindingEvent($escapes, $name, $node->getEndFilePos());
        }
    }

    /**
     * Unknown callees may modify direct variable arguments after argument evaluation.
     *
     * @param array<string,list<int>> $escapes
     */
    private function escapedReceiverBindings(Node $node, array &$escapes): void
    {
        if ($node instanceof Node\Arg && $node->value instanceof Expr\Variable && is_string($node->value->name)) {
            $this->recordBindingEvent($escapes, $node->value->name, $node->getAttribute('sodaReceiverEscapeAt', $node->getEndFilePos()));
        }

        if ($node instanceof Expr\AssignRef && $node->expr instanceof Expr\Variable && is_string($node->expr->name)) {
            $this->recordBindingEvent($escapes, $node->expr->name, $node->getEndFilePos());
        }

        $this->capturedReferenceBindings($node, $node->getStartFilePos(), $escapes);
    }

    /**
     * Dynamic execution can replace bindings without a direct assignment target.
     */
    private function hasOpaqueReceiverBindings(Node $node): bool
    {
        if ($node instanceof Expr\Eval_ || $node instanceof Expr\Include_ || $node instanceof Stmt\Goto_) {
            return true;
        }

        return $node instanceof Expr\FuncCall && (! $node->name instanceof Node\Name || strtolower($node->name->getLast()) === 'extract');
    }

    /**
     * Every position remains available for the subsequent lexical comparison.
     *
     * @param array<string,list<int>> $events
     */
    private function recordBindingEvent(array &$events, string $name, int $position): void
    {
        $events[$name] = [...($events[$name] ?? []), $position];
    }

    /**
     * Equal receiver inputs require the same last write and no preceding reference escape.
     *
     * @param array{writes: array<string,list<int>>, escapes: array<string,list<int>>, opaque: list<int>} $events
     */
    private function stampReceiverBinding(Expr\Variable $variable, int $owner, array $events): void
    {
        ['writes' => $writes, 'escapes' => $escapes, 'opaque' => $opaque] = $events;
        $name = $variable->name;
        $position = $variable->getStartFilePos();
        $unsafe = array_filter([...$opaque, ...($escapes[$name] ?? [])], static fn (int $event): bool => $event < $position);
        $preceding = array_filter($writes[$name] ?? [], static fn (int $event): bool => $event < $position);
        $version = $owner.':'.$name.':'.max([-1, ...$preceding]);
        $variable->setAttribute('sodaReceiverBinding', $unsafe === [] || $name === 'this' ? $version : false);
    }
}
