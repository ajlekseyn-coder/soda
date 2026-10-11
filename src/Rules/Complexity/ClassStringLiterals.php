<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects expression string literals owned by one class-like declaration.
 *
 * @internal
 */
final class ClassStringLiterals extends NodeVisitorAbstract
{
    /**
     * Literal occurrences grouped by exact decoded bytes.
     *
     * @var array<string, array{value: string, lines: list<int>}>
     */
    private array $groups = [];

    /**
     * Scan class behavior while excluding nested classes and declarative defaults.
     *
     * @return list<array{value: string, lines: list<int>}>
     */
    public function collect(Stmt\ClassLike $class): array
    {
        $this->groups = [];
        (new NodeTraverser($this))->traverse($class->stmts);

        return array_values($this->groups);
    }

    /**
     * Count full constant strings, preserving expression-level closures and excluding metadata.
     */
    public function enterNode(Node $node): ?int
    {
        if ($this->isExcluded($node)) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Node\Scalar\String_) {
            $key = 'literal:'.$node->value;
            $group = $this->groups[$key] ?? ['value' => $node->value, 'lines' => []];
            $lines = $group['lines'];
            $lines[] = $node->getStartLine();
            $group['lines'] = $lines;
            $this->groups[$key] = $group;
        }

        return null;
    }

    /**
     * Recognize independently owned declarations, defaults and interpolated text.
     */
    private function isExcluded(Node $node): bool
    {
        return $node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassConst || $node instanceof Node\PropertyItem
            || $node instanceof Stmt\EnumCase || $node instanceof Node\Param
            || $node instanceof Node\Attribute || $node instanceof Node\Scalar\InterpolatedString;
    }
}
