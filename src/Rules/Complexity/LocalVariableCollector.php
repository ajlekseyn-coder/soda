<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Reserves encountered local names without entering nested callable or type declarations.
 *
 * @internal
 */
final class LocalVariableCollector extends NodeVisitorAbstract
{
    /**
     * Names encountered in the current direct statement and its control-flow children.
     *
     * @var array<string, true>
     */
    private array $names = [];

    /**
     * Collect named variables from one statement while respecting lexical boundaries.
     *
     * @return array<string, true>
     */
    public function collect(Node $statement): array
    {
        $this->names = [];
        (new NodeTraverser($this))->traverse([$statement]);

        return $this->names;
    }

    /**
     * Stop deferred bodies and record explicit variable identifiers only.
     */
    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Expr\Closure) {
            foreach ($node->uses as $use) {
                if (is_string($use->var->name)) {
                    $this->names[$use->var->name] = true;
                }
            }
        }

        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Variable && is_string($node->name)) {
            $this->names[$node->name] = true;
        }

        return null;
    }
}
