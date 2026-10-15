<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Composition;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

/**
 * Recognizes observed dispatch that can select a different factory implementation.
 */
final readonly class FactoryDispatchPatterns
{
    /**
     * Keep literal or bounded method names only when dispatched on the declaring receiver.
     */
    public static function collect(Stmt\ClassLike $class): array
    {
        $patterns = self::directPatterns($class);
        foreach ($class->getMethods() as $method) {
            $finder = new NodeFinder;
            $nodes = $method->stmts ?? [];
            $assignments = $finder->findInstanceOf($nodes, Expr\Assign::class);
            foreach ($finder->findInstanceOf($nodes, Expr\MethodCall::class) as $call) {
                if (! self::isOwnDispatch($call, $method)) {
                    continue;
                }

                $matches = array_filter($assignments, static fn (Expr\Assign $assignment): bool => $assignment->var instanceof Expr\Variable
                    && $assignment->var->name === $call->name->name && self::isOwnedBy($assignment, $method));
                if (count($matches) !== 1) {
                    continue;
                }

                $assignment = array_shift($matches);
                if ($assignment->getStartFilePos() >= $call->getStartFilePos()) {
                    continue;
                }

                $pattern = self::pattern($assignment->expr);
                $hasLiteral = str_replace('.*', '', $pattern) !== '';
                if ($hasLiteral) {
                    $patterns[] = '/^'.$pattern.'$/iD';
                }
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Literal receiver calls establish exact hooks without a computed selector.
     */
    private static function directPatterns(Stmt\ClassLike $class): array
    {
        $patterns = [];
        $finder = new NodeFinder;
        foreach ($class->getMethods() as $method) {
            $nodes = $method->stmts ?? [];
            foreach ($finder->find($nodes, static fn (Node $node): bool => in_array($node->getType(), [
                'Expr_MethodCall', 'Expr_NullsafeMethodCall', 'Expr_StaticCall',
            ], true)) as $call) {
                $name = self::calledHook($call, $class, $method);
                if ($name !== null) {
                    $patterns[] = '/^'.preg_quote($name, '/').'$/iD';
                }
            }

        }

        return $patterns;
    }

    /**
     * An observed virtual call preserves the factory's replaceable implementation.
     */
    private static function calledHook(Node $call, Stmt\ClassLike $class, Stmt\ClassMethod $method): ?string
    {
        $fields = get_object_vars($call);
        $name = $fields['name'] ?? null;
        $receiver = $fields['var'] ?? null;
        $owner = $fields['class'] ?? null;
        $isThis = $receiver instanceof Expr\Variable && $receiver->name === 'this' && ! $method->isStatic();
        $isLateBound = self::isReplaceableClassCall($owner, $class);
        if (! $name instanceof Identifier || ! self::isOwnedBy($call, $method) || ! self::canVaryByReceiver($class, $name, $isLateBound)) {
            return null;
        }

        return $isThis || $isLateBound ? $name->toLowerString() : null;
    }

    /**
     * Late static binding can select a descendant's same-named method even when this declaration is private.
     */
    private static function canVaryByReceiver(Stmt\ClassLike $class, Identifier $name, bool $lateBound): bool
    {
        $target = $class->getMethod($name->toString());
        if ($target === null) {
            return false;
        }

        if ($class instanceof Trait_) {
            return true;
        }

        $open = $class instanceof Class_ && ! $class->isFinal();

        return $open && ($target->isPrivate() ? $lateBound : ! $target->isFinal());
    }

    /**
     * A trait's self reference binds to the consuming class, which can replace its methods.
     */
    private static function isReplaceableClassCall(?Node $owner, Stmt\ClassLike $class): bool
    {
        if (! $owner instanceof Name) {
            return false;
        }

        $name = $owner->toLowerString();

        return $name === 'static' || ($class instanceof Trait_ && $name === 'self');
    }

    /**
     * Distinguish this object's dispatch from calls on another collaborator.
     */
    private static function isOwnDispatch(Expr\MethodCall $call, Stmt\ClassMethod $method): bool
    {
        $isThis = $call->var instanceof Expr\Variable && $call->var->name === 'this';
        $isNamedVariable = $call->name instanceof Expr\Variable && is_string($call->name->name);

        return $isThis && $isNamedVariable && self::isOwnedBy($call, $method);
    }

    /**
     * Nested closures and classes cannot donate an assignment to an unrelated callable.
     */
    private static function isOwnedBy(Node $node, Stmt\ClassMethod $method): bool
    {
        $owner = $node->getAttribute('parent');
        while ($owner instanceof Node && ! $owner instanceof Node\FunctionLike) {
            $owner = $owner->getAttribute('parent');
        }

        return $owner === $method;
    }

    /**
     * Literal concatenation segments constrain the dynamically selected method name.
     */
    private static function pattern(Expr $expression): string
    {
        if ($expression instanceof String_) {
            return preg_quote($expression->value, '/');
        }

        if ($expression instanceof Expr\BinaryOp\Concat) {
            return self::pattern($expression->left).self::pattern($expression->right);
        }

        return '.*';
    }
}
