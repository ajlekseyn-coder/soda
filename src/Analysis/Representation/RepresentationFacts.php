<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Representation;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Bounded scalar syntax facts for representation flow; no AST survives collection.
 */
final class RepresentationFacts
{
    use RepresentationCallSyntax;
    use RepresentationValueSyntax;

    /**
     * Bound retained syntax facts for each callable independently.
     */
    private const int MAX_FACTS = 512;

    /**
     * Index scalar declarations and preserve explicit gaps for unsafe declarations.
     *
     * @param list<Node> $nodes
     *
     * @return array{types: array<string, array>, callables: array<string, array>, truncated: list<array>}
     */
    public static function collect(array $nodes): array
    {
        $types = [];
        $callables = [];
        $truncated = [];
        $duplicates = [];
        $unconditional = self::unconditional($nodes);
        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf($nodes, Stmt\Class_::class) as $type) {
            if ($type->name === null) {
                continue;
            }

            $row = self::type($type);
            $id = strtolower($row['name']);
            $declarationId = spl_object_id($type);
            $reason = isset($unconditional[$declarationId]) ? null : 'Conditional or nested declaration.';
            if (isset($types[$id])) {
                $reason = 'Ambiguous duplicate declaration.';
                $duplicates[$id] = true;
            }

            if ($reason !== null) {
                $row['eligible'] = false;
            }

            $types[$id] = $row;
            foreach ($type->getMethods() as $method) {
                $callable = self::callable($method, $row['name'].'::'.$method->name->toString(), $id, $truncated);
                self::store($callables, $callable, $reason);
            }
        }

        self::rejectDuplicateTypes($callables, $duplicates);
        self::functions($nodes, $unconditional, $callables, $truncated);

        return ['types' => $types, 'callables' => $callables, 'truncated' => $truncated];
    }

    /**
     * Index named functions without promoting conditional or nested declarations to certainty.
     *
     * @param list<Node>       $nodes
     * @param array<int, true> $unconditional
     */
    private static function functions(array $nodes, array $unconditional, array &$callables, array &$truncated): void
    {
        foreach ((new NodeFinder)->findInstanceOf($nodes, Stmt\Function_::class) as $function) {
            $name = $function->namespacedName?->toString() ?? $function->name->toString();
            $declarationId = spl_object_id($function);
            $reason = isset($unconditional[$declarationId]) ? null : 'Conditional or nested declaration.';
            self::store($callables, self::callable($function, $name, null, $truncated), $reason);
        }
    }

    /**
     * A duplicate class makes every member declaration ambiguous, including unique method names.
     */
    private static function rejectDuplicateTypes(array &$callables, array $duplicates): void
    {
        foreach ($callables as $id => $callable) {
            if ($callable['class'] !== null && isset($duplicates[$callable['class']])) {
                $callable['statements'] = [self::barrier('Ambiguous duplicate declaration.', $callable['line'])];
                $callables[$id] = $callable;
            }
        }
    }

    /**
     * Only namespace-level declarations are unconditional; do not flatten executable scopes.
     *
     * @param list<Node> $nodes
     *
     * @return array<int, true>
     */
    private static function unconditional(array $nodes): array
    {
        $declarations = [];
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                $declarations += self::unconditional($node->stmts);

                continue;
            }

            if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Function_) {
                $declarations[spl_object_id($node)] = true;
            }
        }

        return $declarations;
    }

    /**
     * Reject same-file ambiguity and preserve syntax gaps before interpreting bodies.
     */
    private static function store(array &$callables, array $callable, ?string $reason): void
    {
        $id = $callable['id'];
        if (isset($callables[$id])) {
            $reason = 'Ambiguous duplicate declaration.';
        }

        if ($reason !== null) {
            $callable['statements'] = [self::barrier($reason, $callable['line'])];
        }

        $callables[$id] = $callable;
    }

    /**
     * Retain contracts and ordered scalar body facts under one shared callable budget.
     *
     * @param list<array> $truncated
     */
    private static function callable(Stmt\ClassMethod|Stmt\Function_ $node, string $name, ?string $class, array &$truncated): array
    {
        $remaining = self::MAX_FACTS;
        [$parameters, $statements] = self::parameters($node, $class, $remaining);
        foreach ($node->stmts ?? [] as $statement) {
            $remaining--;
            if ($remaining < 0) {
                break;
            }

            if (! $statement instanceof Stmt\Nop) {
                $statements[] = self::statement($statement, $class, $remaining);
            }
        }

        if ($node->stmts === null) {
            $statements[] = self::barrier('missing body', $node->getStartLine());
        }

        if ($remaining < 0) {
            $statements = [self::barrier('fact budget exceeded', $node->getStartLine())];
            $truncated[] = ['callable' => strtolower($name), 'line' => $node->getStartLine(), 'reason' => 'fact budget exceeded', 'limit' => self::MAX_FACTS];
        }

        return ['id'                                => strtolower($name), 'name' => $name, 'class' => $class, 'line' => $node->getStartLine(),
            ...self::modifiers($node), 'parameters' => $parameters,
            'returnType'                            => self::nativeType($node->returnType, $class), 'byRef' => $node->byRef, 'statements' => $statements];
    }

    /**
     * Preserve parameter contracts, defaults and implicit constructor assignments.
     */
    private static function parameters(Stmt\ClassMethod|Stmt\Function_ $node, ?string $class, int &$remaining): array
    {
        $parameters = [];
        $statements = [];
        $constructor = $node instanceof Stmt\ClassMethod && $node->name->toLowerString() === '__construct';
        foreach ($node->params as $parameter) {
            $remaining--;
            if ($remaining < 0) {
                break;
            }

            $parameterName = self::parameterName($parameter);
            if ($parameterName === null) {
                $statements[] = self::barrier('dynamic parameter', $parameter->getStartLine());

                continue;
            }

            $parameters[] = ['name' => $parameterName, 'type' => self::nativeType($parameter->type, $class),
                'byRef'             => $parameter->byRef, 'default' => $parameter->default === null ? null : self::expression($parameter->default, $class, $remaining)];
            if ($parameter->variadic) {
                $statements[] = self::barrier('variadic parameter', $parameter->getStartLine());
            }

            if ($constructor && $parameter->flags !== 0) {
                $statements[] = self::promotion($parameter);
                $remaining -= 4;
            }
        }

        return [$parameters, $statements];
    }

    /**
     * Record dispatch modifiers independently from callable syntax.
     */
    private static function modifiers(Stmt\ClassMethod|Stmt\Function_ $node): array
    {
        if ($node instanceof Stmt\Function_) {
            return ['final' => false, 'static' => false, 'visibility' => 'public'];
        }

        $visibility = match (true) {
            $node->isPrivate() => 'private', $node->isProtected() => 'protected', default => 'public'
        };

        return ['final' => $node->isFinal(), 'static' => $node->isStatic(), 'visibility' => $visibility];
    }

    /**
     * Preserve straight-line statements and stop at unsupported control or effects.
     */
    private static function statement(Stmt $statement, ?string $class, int &$remaining): array
    {
        $line = $statement->getStartLine();
        if ($statement instanceof Stmt\Return_) {
            return ['op' => 'return', 'value' => $statement->expr instanceof Expr ? self::expression($statement->expr, $class, $remaining) : null, 'line' => $line];
        }

        if ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Assign) {
            return ['op' => 'assign', 'target' => self::expression($statement->expr->var, $class, $remaining),
                'value'  => self::expression($statement->expr->expr, $class, $remaining), 'line' => $line];
        }

        if ($statement instanceof Stmt\Expression) {
            return ['op' => 'expression', 'value' => self::expression($statement->expr, $class, $remaining), 'line' => $line];
        }

        return self::barrier($statement->getType(), $line);
    }

    /**
     * Stop body interpretation at an unresolved control or effect boundary.
     */
    private static function barrier(string $reason, int $line): array
    {
        return ['op' => 'barrier', 'reason' => $reason, 'line' => $line];
    }
}
