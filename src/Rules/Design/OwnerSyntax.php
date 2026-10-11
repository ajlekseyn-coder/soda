<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Design;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Marks lexical and effect uncertainty in owner relationships.
 *
 * @internal
 */
trait OwnerSyntax
{
    /**
     * Reject effects whose owner relationships or lexical bindings cannot be proved here.
     */
    private function isUncertain(Node $node): bool
    {
        $unsupported = in_array($node->getType(), [
            'Expr_FuncCall', 'Expr_StaticCall', 'Expr_New',
            'Expr_NullsafeMethodCall', 'Expr_NullsafePropertyFetch', 'Expr_AssignRef',
            'Expr_Yield', 'Expr_YieldFrom', 'Expr_Clone', 'Stmt_Unset', 'Stmt_Global', 'Stmt_Static',
        ], true);
        $nested = $node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike || $node instanceof Node\Scalar\MagicConst;

        return $unsupported || $nested || $this->hasIndirectSyntax($node) || $this->hasUnknownConstantBinding($node);
    }

    /**
     * Named constants preserve their resolved identity; relative and dynamic bindings stay unknown.
     */
    private function hasUnknownConstantBinding(Node $node): bool
    {
        if (! $node instanceof Expr\ClassConstFetch) {
            return false;
        }

        if (! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier) {
            return true;
        }

        $name = strtolower($node->class->toString());

        return in_array($name, ['self', 'static', 'parent'], true);
    }

    /**
     * Detect dynamic variables, references, unpacking, and escaping this.
     */
    private function hasIndirectSyntax(Node $node): bool
    {
        if ($node instanceof Node\Arg) {
            return $node->byRef || $node->unpack;
        }

        if (! $node instanceof Expr\Variable) {
            return false;
        }

        if (! is_string($node->name)) {
            return true;
        }

        $parent = $node->getAttribute('parent');
        $direct = $parent instanceof Expr\PropertyFetch || $parent instanceof Expr\MethodCall;
        $global = in_array($node->name, ['GLOBALS', '_SERVER', '_ENV', '_GET', '_POST', '_COOKIE', '_SESSION', '_REQUEST', '_FILES'], true);

        return $global || ($node->name === 'this' && ! $direct);
    }
}
