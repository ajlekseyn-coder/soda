<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\State;

use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Identifies reads and releases of private slots separately from ordinary writes.
 */
final class PrivateStateAccess
{
    /**
     * Reads and explicit releases on a known owner both establish storage use.
     */
    public static function member(Node $node, string $owner, array $receivers): ?string
    {
        if ($node instanceof Expr\ClassConstFetch && StateReceivers::isOwnerType($node->class, $owner)) {
            $constant = $node->name instanceof Node\Identifier ? $node->name->toString() : '*';

            return 'constant:'.$constant;
        }

        $isProperty = $node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch || $node instanceof Expr\StaticPropertyFetch;
        if (! $isProperty || self::isWriteOnly($node)) {
            return null;
        }

        return self::property($node, $owner, $receivers);
    }

    /**
     * Resolve named or dynamic field reads on a proven instance or static owner.
     */
    private static function property(Expr\PropertyFetch|Expr\NullsafePropertyFetch|Expr\StaticPropertyFetch $node, string $owner, array $receivers): ?string
    {
        $isOwner = $node instanceof Expr\StaticPropertyFetch
            ? StateReceivers::isOwnerType($node->class, $owner)
            : StateReceivers::isReceiver($node->var, $owner, $receivers);
        $isNamed = $node->name instanceof Node\Identifier || $node->name instanceof Node\VarLikeIdentifier;
        $property = $isNamed ? $node->name->toString() : '*';

        return $isOwner ? 'property:'.$property : null;
    }

    /**
     * Clearing a slot releases its retained value; ordinary writes leave index reads separate.
     */
    private static function isWriteOnly(Node $node): bool
    {
        $current = $node;
        $parent = $current->getAttribute('parent');
        while ($parent instanceof Expr\ArrayDimFetch && $parent->var === $current) {
            $current = $parent;
            $parent = $current->getAttribute('parent');
        }

        $isAssignment = $parent instanceof Expr\Assign || $parent instanceof Expr\AssignRef;
        $isRelease = $parent instanceof Expr\Assign && $parent->expr instanceof Expr\ConstFetch
            && $parent->expr->name->toLowerString() === 'null';

        return $isAssignment && $parent->var === $current && ! $isRelease;
    }
}
