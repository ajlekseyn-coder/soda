<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Control;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Tracks parameter aliases and literal dispatch to distinct direct operations.
 */
final class ParameterControlAnalysis
{
    /**
     * Mode-selection locations grouped by incoming parameter.
     */
    private array $findings = [];

    /**
     * Return parameter names and the dispatch source lines, keeping each callable isolated.
     */
    public function analyze(Node\FunctionLike $callable): array
    {
        $this->findings = [];
        $origins = [];
        foreach ($callable->getParams() as $parameter) {
            if ($parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                $origins[$parameter->var->name] = [$parameter->var->name];
            }
        }

        $this->scan($callable->getStmts() ?? [], $origins);

        return ['modes' => $this->findings];
    }

    /**
     * Report only dispatches with at least two literal alternatives and distinct operations.
     */
    private function record(Node $node, array $origins): void
    {
        $dispatch = (new LiteralDispatch)->of($node);
        if ($dispatch === null) {
            return;
        }

        [$selector, $arms] = $dispatch;
        $values = [];
        $operations = [];
        foreach ($arms as [$value, $body]) {
            $literal = $value === null ? 'default' : (new LiteralDispatch)->literal($value);
            $operation = (new OperationSequence)->encode($body);
            if ($literal !== null && $operation !== null) {
                $values[$literal] = true;
                $operations[$operation] = true;
            }
        }

        if (count($values) < 2 || count($operations) < 2) {
            return;
        }

        foreach ($this->origins($selector, $origins) as $parameter) {
            $lines = $this->findings[$parameter] ?? [];
            $lines[] = $node->getStartLine();
            $this->findings[$parameter] = $lines;
        }
    }

    /**
     * Sequential assignments replace aliases; branches conservatively merge possible origins.
     */
    private function scan(array $nodes, array $origins): ?array
    {
        $current = $origins;
        foreach ($nodes as $node) {
            if (! $node instanceof Node || $node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike) {
                continue;
            }

            $this->record($node, $current);
            $current = $this->nextOrigins($node, $current);

            if ($current === null || $this->isTerminating($node)) {
                return null;
            }

        }

        return $current;
    }

    /**
     * Only direct aliases retain an input identity; computed discriminators are separate values.
     */
    private function origins(Expr $expression, array $origins): array
    {
        return $expression instanceof Expr\Variable && is_string($expression->name) ? ($origins[$expression->name] ?? []) : [];
    }

    /**
     * Join aliases from conditional child paths without claiming a definite branch outcome.
     */
    private function branches(Node $node, array $origins): ?array
    {
        if ($node instanceof Stmt\If_) {
            return $this->conditionalOrigins($node, $origins);
        }

        foreach ($node->getSubNodeNames() as $field) {
            $child = get_object_vars($node)[$field];
            $children = is_array($child) ? $child : [];
            if ($child instanceof Node) {
                $children = [$child];
            }

            $branch = $this->scan($children, $origins);
            if ($branch === null) {
                continue;
            }
            foreach ($branch as $name => $sources) {
                $origins[$name] = array_values(array_unique([...($origins[$name] ?? []), ...$sources]));
            }
        }

        return $origins;
    }

    /**
     * Assignment right-hand sides run before their target binding is replaced.
     */
    private function nextOrigins(Node $node, array $origins): ?array
    {
        $assignment = $node instanceof Stmt\Expression ? $node->expr : $node;
        if (! $assignment instanceof Expr\Assign || ! $assignment->var instanceof Expr\Variable || ! is_string($assignment->var->name)) {
            return $this->branches($node, $origins);
        }
        $current = $this->scan([$assignment->expr], $origins);
        if ($current === null) {
            return null;
        }
        $current[$assignment->var->name] = $this->origins($assignment->expr, $current);

        return $current;
    }

    /**
     * Only paths reaching the next statement can donate an input identity to it.
     */
    private function conditionalOrigins(Stmt\If_ $conditional, array $origins): ?array
    {
        $remaining = $this->scan([$conditional->cond], $origins);
        if ($remaining === null) {
            return null;
        }
        $paths = [$this->scan($conditional->stmts, $remaining)];
        foreach ($conditional->elseifs as $elseif) {
            $remaining = $this->scan([$elseif->cond], $remaining);
            if ($remaining === null) {
                return $this->conditionalJoin($conditional, $paths, $origins);
            }
            $paths[] = $this->scan($elseif->stmts, $remaining);
        }
        $paths[] = $conditional->else === null ? $remaining : $this->scan($conditional->else->stmts, $remaining);

        return $this->conditionalJoin($conditional, $paths, $origins);
    }

    /**
     * A variable condition may carry control dependence even after its direct alias was replaced.
     */
    private function conditionalJoin(Stmt\If_ $conditional, array $paths, array $origins): ?array
    {
        $joined = $this->joinedOrigins($paths);
        if ($joined === null) {
            return null;
        }
        $conditions = [$conditional->cond];
        foreach ($conditional->elseifs as $elseif) {
            $conditions[] = $elseif->cond;
        }
        $dependent = (new NodeFinder)->findFirst($conditions, static fn (Node $node): bool => $node instanceof Expr\Variable || $node instanceof Node\FunctionLike);

        if ($dependent === null) {
            return $joined;
        }
        foreach ($origins as $name => $sources) {
            if ($this->hasUniformFinalLiteral($conditional, $paths, $name)) {
                $origins[$name] = [];
            }
        }

        return $this->joinedOrigins([$origins, $joined]);
    }

    /**
     * The same final literal on every reaching arm is independent of which condition selected it.
     */
    private function hasUniformFinalLiteral(Stmt\If_ $conditional, array $paths, string $name): bool
    {
        $bodies = [$conditional->stmts];
        foreach ($conditional->elseifs as $elseif) {
            $bodies[] = $elseif->stmts;
        }
        $bodies[] = $conditional->else?->stmts ?? [];
        $values = [];
        foreach ($paths as $index => $path) {
            if ($path === null) {
                continue;
            }
            $body = $bodies[$index];
            $statement = $body === [] ? null : $body[array_key_last($body)];
            $assignment = $statement instanceof Stmt\Expression ? $statement->expr : null;
            $value = $this->finalLiteral($assignment, $name);
            if ($value === null) {
                return false;
            }
            $values[$value] = true;
        }

        return count($values) === 1;
    }

    /**
     * Native scalar syntax excludes computed selectors and class constants that may invoke autoload.
     */
    private function finalLiteral(?Expr $expression, string $name): ?string
    {
        if (! $expression instanceof Expr\Assign || ! $expression->var instanceof Expr\Variable || $expression->var->name !== $name) {
            return null;
        }

        return $expression->expr instanceof Node\Scalar ? (new LiteralDispatch)->literal($expression->expr) : null;
    }

    /**
     * Missing alternatives contribute their unchanged path; exhausted paths contribute nothing.
     */
    private function joinedOrigins(array $paths): ?array
    {
        $origins = null;
        foreach ($paths as $path) {
            if ($path === null) {
                continue;
            }
            $origins ??= [];
            foreach ($path as $name => $sources) {
                $origins[$name] = array_values(array_unique([...($origins[$name] ?? []), ...$sources]));
            }
        }

        return $origins;
    }

    /**
     * Terminal expressions are inspected for dispatch before ending their callable path.
     */
    private function isTerminating(Node $node): bool
    {
        $expression = $node instanceof Stmt\Expression ? $node->expr : $node;

        return $node instanceof Stmt\Return_ || $expression instanceof Expr\Throw_ || $expression instanceof Expr\Exit_;
    }
}
