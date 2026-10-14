<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Names callable scopes without adding anonymous declarations to legacy metrics.
 */
final class CallableIdentity
{
    /**
     * AST attribute connecting nested syntax to its lexical declaration.
     */
    private const PARENT_ATTRIBUTE = 'parent';

    /**
     * Use resolved names for declarations and source identities for anonymous scopes.
     */
    public static function name(Node $node): string
    {
        if ($node instanceof Stmt\ClassMethod) {
            $owner = $node->getAttribute(self::PARENT_ATTRIBUTE);

            return self::type($owner).'::'.$node->name->toString();
        }

        if ($node instanceof Stmt\Function_) {
            return $node->namespacedName?->toString() ?? $node->name->toString();
        }

        return sprintf('{callable}@%d:%d', $node->getStartLine(), $node->getStartFilePos());
    }

    /**
     * Identify the nearest named declaring method without borrowing an outer callable's contract.
     */
    public static function enclosingMethod(Node $node): ?string
    {
        $owner = $node->getAttribute(self::PARENT_ATTRIBUTE);
        while ($owner instanceof Node) {
            if ($owner instanceof Node\FunctionLike) {
                return $owner instanceof Stmt\ClassMethod && ! AnonymousClassBoundary::isDirectMember($owner)
                    ? self::name($owner) : null;
            }

            $owner = $owner->getAttribute(self::PARENT_ATTRIBUTE);
        }

        return null;
    }

    /**
     * Keep anonymous class identities unique within one source file.
     */
    public static function type(Stmt\ClassLike $node): string
    {
        return $node->namespacedName?->toString()
            ?? sprintf('{anonymous}@%d:%d', $node->getStartLine(), $node->getStartFilePos());
    }
}
