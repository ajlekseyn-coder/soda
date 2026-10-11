<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Counts executable statements while stopping at nested callable and type boundaries.
 *
 * @internal
 */
final class StatementCounter extends NodeVisitorAbstract
{
    /**
     * Executable statements encountered in the current callable body.
     */
    private int $statements = 0;

    /**
     * Traverse one body without attributing nested declarations to its owner.
     *
     * @param list<Node> $body Callable body nodes.
     */
    public function count(array $body): int
    {
        $this->statements = 0;
        (new NodeTraverser($this))->traverse($body);

        return $this->statements;
    }

    /**
     * Count each executable statement once and exclude control wrapper clauses.
     */
    public function enterNode(Node $node): ?int
    {
        if ($node instanceof FunctionLike || $node instanceof Stmt\ClassLike) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Stmt && ! $this->isWrapper($node)) {
            $this->statements++;
        }

        return null;
    }

    /**
     * Recognize clauses whose bodies count but whose wrapper adds no instruction.
     */
    private function isWrapper(Stmt $node): bool
    {
        return $node instanceof Stmt\Nop || $node instanceof Stmt\Else_
            || $node instanceof Stmt\ElseIf_ || $node instanceof Stmt\Catch_
            || $node instanceof Stmt\Finally_ || $node instanceof Stmt\Case_;
    }
}
