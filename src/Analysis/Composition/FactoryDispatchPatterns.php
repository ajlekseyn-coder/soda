<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Composition;

use Cosmira\Soda\Analysis\Control\LiteralDispatch;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Recognizes native name bounds for observed dispatch on the declaring receiver.
 *
 * @internal
 */
trait FactoryDispatchPatterns
{
    /**
     * PHP method names ignore case and must match through the selector's final byte.
     */
    private const PATTERN_END = '$/iD';

    /**
     * Keep literal or bounded method names only when dispatched on the declaring receiver.
     */
    private static function factoryPatterns(Stmt\ClassLike $class): array
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
                    && $assignment->var->name === $call->name->name
                    && $assignment->getStartFilePos() < $call->getStartFilePos() && self::isOwnedBy($assignment, $method));
                if (count($matches) !== 1) {
                    continue;
                }

                $assignment = array_shift($matches);
                $pattern = self::pattern($assignment->expr);
                $hasLiteral = str_replace('.*', '', $pattern) !== '';
                if ($hasLiteral) {
                    $patterns[] = '/^'.$pattern.self::PATTERN_END;
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
                    $patterns[] = '/^'.preg_quote($name, '/').self::PATTERN_END;
                }
            }

        }

        return $patterns;
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
     * Native strings constrain names even when an embedded formatter's behavior is unknown.
     */
    private static function boundedSelector(Expr $expression, Stmt\ClassMethod $method): ?string
    {
        $pattern = self::selectorPattern($expression, $method);
        if ($pattern === null || ! str_contains($pattern, '.*') || str_replace('.*', '', $pattern) === '') {
            return null;
        }

        return '/^'.$pattern.self::PATTERN_END;
    }

    /**
     * Closed literal inputs cannot donate positions to every matching sibling name.
     */
    private static function selectorPattern(Expr $expression, Stmt\ClassMethod $method): ?string
    {
        if ($expression instanceof String_) {
            return self::pattern($expression);
        }
        if ($expression instanceof Expr\Assign || self::doesBlockSelectorInput($expression, $method, [])) {
            return null;
        }
        if ($expression instanceof Expr\BinaryOp\Concat) {
            $left = self::selectorPattern($expression->left, $method);
            $right = self::selectorPattern($expression->right, $method);

            return $left === null || $right === null ? null : $left.$right;
        }
        if ($expression instanceof InterpolatedString) {
            return self::interpolatedPattern($expression, $method);
        }

        return self::isOpenSelector($expression) ? '.*' : null;
    }

    /**
     * Lookups and actual calls leave a name segment open without interpreting their values.
     */
    private static function isOpenSelector(Expr $expression): bool
    {
        if ($expression instanceof Expr\CallLike) {
            return ! $expression->isFirstClassCallable();
        }

        return $expression instanceof Expr\Variable || $expression instanceof Expr\PropertyFetch || $expression instanceof Expr\ArrayDimFetch;
    }

    /**
     * Finite or unsupported local selections cannot donate positions to matching sibling names.
     */
    private static function doesBlockSelectorInput(Node $variable, Stmt\ClassMethod $method, array $seen): bool
    {
        if ((new LiteralDispatch)->literal($variable) !== null) {
            return true;
        }
        if (! $variable instanceof Expr\Variable) {
            return self::doesBlockNativeSelector($variable, $method, $seen);
        }
        if (! is_string($variable->name) || isset($seen[$variable->name])) {
            return false;
        }
        $seen[$variable->name] = true;
        $assignments = (new NodeFinder)->findInstanceOf($method->stmts ?? [], Expr\Assign::class);
        $matches = array_filter($assignments, static fn (Expr\Assign $assignment): bool => self::isPriorSelectorAssignment($assignment, $variable, $method));
        $last = array_pop($matches);

        return $last !== null && self::doesAssignmentDominate($last, $variable) && self::doesBlockSelectorInput($last->expr, $method, $seen);
    }

    /**
     * Only preceding writes in this callable can close the interpolated input.
     */
    private static function isPriorSelectorAssignment(Expr\Assign $assignment, Expr\Variable $variable, Stmt\ClassMethod $method): bool
    {
        $target = $assignment->var;

        return $target instanceof Expr\Variable && $target->name === $variable->name && $assignment->getEndFilePos() < $variable->getStartFilePos() && self::isOwnedBy($assignment, $method);
    }

    /**
     * Native segments retain finite selections and unsupported operators through local aliases.
     */
    private static function doesBlockNativeSelector(Node $expression, Stmt\ClassMethod $method, array $seen): bool
    {
        if ($expression instanceof Expr\BinaryOp\Concat) {
            return self::doesBlockSelectorInput($expression->left, $method, $seen) && self::doesBlockSelectorInput($expression->right, $method, $seen);
        }
        if (! $expression instanceof InterpolatedString) {
            return ! $expression instanceof Expr || ! self::isOpenSelector($expression);
        }
        foreach ($expression->parts as $part) {
            if ($part instanceof Expr && ! self::doesBlockSelectorInput($part, $method, $seen)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Each native interpolation segment keeps its bound or rejects a closed selector.
     */
    private static function interpolatedPattern(InterpolatedString $expression, Stmt\ClassMethod $method): ?string
    {
        $pattern = '';
        foreach ($expression->parts as $part) {
            $segment = $part instanceof Expr ? self::selectorPattern($part, $method) : preg_quote($part->value, '/');
            if ($segment === null) {
                return null;
            }
            $pattern .= $segment;
        }

        return $pattern;
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
