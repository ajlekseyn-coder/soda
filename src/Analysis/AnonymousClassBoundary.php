<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Stmt\Class_;

/**
 * @internal
 */
final class AnonymousClassBoundary
{
    /**
     * Attribute key under which the parser stores the syntactic parent node.
     */
    private const string PARENT = 'parent';

    /**
     * Determine whether direct member applies to the supplied input.
     */
    public static function isDirectMember(Node $node): bool
    {
        return self::isAnonymousClass($node->getAttribute(self::PARENT));
    }

    /**
     * Detects nodes nested anywhere inside an anonymous class body.
     */
    public static function isInside(Node $node): bool
    {
        $current = $node->getAttribute(self::PARENT);

        while ($current instanceof Node) {
            if (self::isAnonymousClass($current)) {
                return true;
            }

            $current = $current->getAttribute(self::PARENT);
        }

        return false;
    }

    /**
     * Detects class nodes created by a new expression.
     */
    public static function isAnonymousClass(mixed $node): bool
    {
        return $node instanceof Class_
            && $node->getAttribute(self::PARENT) instanceof New_;
    }
}
