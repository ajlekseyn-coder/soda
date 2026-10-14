<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Usage;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeVisitorAbstract;

/**
 * Tracks parameter bindings and explicit reference captures across lexical callable scopes.
 *
 * @internal
 */
final class ParameterReassignmentVisitor extends NodeVisitorAbstract
{
    /**
     * Eligible parameter bindings for each callable or type boundary.
     *
     * @var list<array<string, true>>
     */
    private array $scopes = [];

    /**
     * Approved entry assignments within the current callable, never inherited by captures.
     *
     * @var list<array<int, true>>
     */
    private array $normalizations = [];

    /**
     * Direct parameter writes found during this traversal.
     *
     * @var list<array{name: string, line: int}>
     */
    private array $writes = [];

    /**
     * Entry normalizations resolved against source function declarations.
     */
    private readonly ParameterInputNormalization $normalizer;

    /**
     * Configure exact parameter names that are intentionally rebound.
     *
     * @param list<string> $ignoredParameters Names without a dollar prefix.
     * @param list<string> $declaredFunctions Known namespace functions, preventing builtin fallback.
     */
    public function __construct(
        /**
         * Explicit parameter-name exceptions applied to each lexical callable.
         */
        private readonly array $ignoredParameters,
        /**
         * Accept the narrow entry-normalization prefix for actual inputs.
         */
        private readonly bool $allowInputNormalization = true,
        array $declaredFunctions = [],
    ) {
        $this->normalizer = new ParameterInputNormalization($declaredFunctions);
    }

    /**
     * Enter lexical owners before inspecting their writes.
     */
    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->scopes[] = [];
            $this->normalizations[] = [];

            return null;
        }

        if ($node instanceof Node\FunctionLike) {
            $parameters = $this->parameters($node);
            $this->scopes[] = $parameters;
            $this->normalizations[] = $this->allowInputNormalization
                ? $this->normalizer->assignments($node, $parameters) : [];

            return null;
        }

        $allowed = $this->normalizations === [] ? [] : $this->normalizations[array_key_last($this->normalizations)];
        $identity = spl_object_id($node);
        if (isset($allowed[$identity])) {
            return null;
        }

        $active = $this->scopes === [] ? [] : $this->scopes[array_key_last($this->scopes)];
        $bindings = [];
        foreach (ParameterWriteTargets::from($node) as $target) {
            $bindings += ParameterWriteTargets::bindings($target);
        }

        foreach (array_intersect_key($bindings, $active) as $name => $line) {
            $this->writes[] = ['name' => $name, 'line' => $line];
        }

        return null;
    }

    /**
     * Start a fresh parameter scope and carry only explicit by-reference captures from its owner.
     *
     * @return array<string, true>
     */
    private function parameters(Node\FunctionLike $node): array
    {
        $parameters = $this->referenceCaptures($node);

        foreach ($node->getParams() as $parameter) {
            $name = $parameter->var instanceof Expr\Variable ? $parameter->var->name : null;
            if (! $parameter->byRef && is_string($name) && ! in_array($name, $this->ignoredParameters, true)) {
                $parameters[$name] = true;
            }
        }

        return $parameters;
    }

    /**
     * Carry tracked outer bindings only through explicit reference captures.
     *
     * @return array<string, true>
     */
    private function referenceCaptures(Node\FunctionLike $node): array
    {
        if (! $node instanceof Expr\Closure) {
            return [];
        }

        $outer = $this->scopes === [] ? [] : $this->scopes[array_key_last($this->scopes)];
        $parameters = [];
        foreach ($node->uses as $use) {
            $name = $use->var->name;
            if ($use->byRef && is_string($name) && isset($outer[$name])) {
                $parameters[$name] = true;
            }
        }

        return $parameters;
    }

    /**
     * Restore the containing scope after leaving a callable or type.
     */
    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            array_pop($this->scopes);
            array_pop($this->normalizations);
        }

        return null;
    }

    /**
     * Return recorded source evidence for the concrete rule.
     *
     * @return list<array{name: string, line: int}>
     */
    public function writes(): array
    {
        return $this->writes;
    }
}
