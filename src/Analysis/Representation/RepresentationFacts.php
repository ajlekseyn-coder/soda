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
    private const MAX_FACTS = 512;

    /**
     * Collection reason marking a same-file redeclaration of one identity.
     */
    private const DUPLICATE_DECLARATION = 'Ambiguous duplicate declaration.';

    /**
     * Collection reason marking a conditional or nested declaration.
     */
    private const CONDITIONAL_DECLARATION = 'Conditional or nested declaration.';

    /**
     * AST parent link shared by same-list and own-callable outcome proofs.
     */
    private const PARENT_ATTRIBUTE = 'parent';

    /**
     * Scalar IR discriminant for a plain local binding.
     */
    private const VALUE_VARIABLE = 'variable';

    /**
     * Scalar IR discriminant for an unresolved statement boundary.
     */
    private const STATEMENT_BARRIER = 'barrier';

    /**
     * Index scalar declarations and preserve explicit gaps for unsafe declarations.
     *
     * @param list<Node> $nodes
     *
     * @return array{types: array<string, array>, callables: array<string, array>, truncated: list<array>, catchCalls: array<int,array>, returnedCatches: array<int,true>}
     */
    public static function collect(array $nodes): array
    {
        $types = [];
        $callables = [];
        $truncated = [];
        $unconditional = self::unconditional($nodes);
        self::classes($nodes, $unconditional, $types, $callables, $truncated);
        self::functions($nodes, $unconditional, $callables, $truncated);

        return ['types'       => $types, 'callables' => $callables, 'truncated' => $truncated, 'catchCalls' => self::catchCalls($nodes),
            'returnedCatches' => self::returnedCatches($nodes)];
    }

    /**
     * Empty catches and catch-defined locals can continue into the next explicit return.
     */
    private static function returnedCatches(array $nodes): array
    {
        $returned = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Stmt\TryCatch::class) as $try) {
            $retry = self::retryCatch($try);
            if ($retry !== null) {
                $returned[$retry] = true;

                continue;
            }
            $following = self::followingReturn($try, $nodes);
            if ($following === null) {
                continue;
            }
            foreach ($try->catches as $catch) {
                if (self::doesReturnCatchValue($catch->stmts, $following)) {
                    $returned[$catch->getStartFilePos()] = true;
                }
            }
        }

        return $returned;
    }

    /**
     * A finite selector retry returns a value or reaches an explicit exhaustion throw.
     */
    private static function retryCatch(Stmt\TryCatch $try): ?int
    {
        $loop = $try->getAttribute(self::PARENT_ATTRIBUTE);
        if (! $loop instanceof Stmt\Foreach_ || ! self::hasRetryLoop($try, $loop)) {
            return null;
        }
        $scope = $loop->getAttribute(self::PARENT_ATTRIBUTE);
        if (! $scope instanceof Stmt\ClassMethod && ! $scope instanceof Stmt\Function_) {
            return null;
        }
        $name = self::retryLocal($try, $scope);
        $failure = self::retryThrow($loop, $scope);

        return $name !== null && $failure !== null && self::hasRetryValues($try, $loop, $failure, $name)
            ? $try->catches[0]->getStartFilePos() : null;
    }

    /**
     * The loop must only attempt a value-returning operation and advance after one empty handler.
     */
    private static function hasRetryLoop(Stmt\TryCatch $try, Stmt\Foreach_ $loop): bool
    {
        if ($try->finally !== null || count($try->catches) !== 1 || $loop->stmts !== [$try]) {
            return false;
        }
        if ($loop->byRef || $loop->keyVar !== null) {
            return false;
        }
        $attempt = $try->stmts[0] ?? null;

        return count($try->stmts) === 1 && $attempt instanceof Stmt\Return_ && $attempt->expr !== null;
    }

    /**
     * Shared parameters remain outside the bounded retry binding proof.
     */
    private static function retryLocal(Stmt\TryCatch $try, Stmt\ClassMethod|Stmt\Function_ $scope): ?string
    {
        $catch = $try->catches[0];
        $name = $catch->var?->name;
        $body = array_filter($catch->stmts, static fn (Node $node): bool => ! $node instanceof Stmt\Nop);
        if (! is_string($name) || $body !== []) {
            return null;
        }
        foreach ($scope->params as $parameter) {
            if ($parameter->byRef && $parameter->var->name === $name) {
                return null;
            }
        }

        return $name;
    }

    /**
     * Exhaustion reaches the next and final statement in this own callable body.
     */
    private static function retryThrow(Stmt\Foreach_ $loop, Stmt\ClassMethod|Stmt\Function_ $scope): ?Expr\Throw_
    {
        $body = array_values(array_filter($scope->stmts ?? [], static fn (Node $node): bool => ! $node instanceof Stmt\Nop));
        $last = $body[1] ?? null;
        if (count($body) !== 2 || $body[0] !== $loop || ! $last instanceof Stmt\Expression) {
            return null;
        }

        return $last->expr instanceof Expr\Throw_ ? $last->expr : null;
    }

    /**
     * Bounded scalar syntax preserves the selector and final error bindings without retaining ASTs.
     */
    private static function hasRetryValues(Stmt\TryCatch $try, Stmt\Foreach_ $loop, Expr\Throw_ $failure, string $name): bool
    {
        $remaining = self::MAX_FACTS;
        $iterable = self::expression($loop->expr, null, $remaining);
        $input = $iterable['value'] ?? [];
        $selector = self::expression($loop->valueVar, null, $remaining);
        $error = self::expression($failure->expr, null, $remaining);
        $value = self::statement($try->stmts[0], null, $remaining);
        if (($iterable['op'] ?? null) !== 'cast' || $iterable['type'] !== 'array' || ($input['op'] ?? null) !== self::VALUE_VARIABLE) {
            return false;
        }
        if (($selector['op'] ?? null) !== self::VALUE_VARIABLE || $selector['name'] === $name) {
            return false;
        }

        return ($error['op'] ?? null) === self::VALUE_VARIABLE && $error['name'] === $name
            && $remaining >= 0 && ! self::hasRetryBindingGap([$iterable, $value], $name);
    }

    /**
     * Symbol-table operations or exposure of the catch local leave the final binding unproved.
     */
    private static function hasRetryBindingGap(array $values, string $name): bool
    {
        if (in_array($values['op'] ?? null, ['unknown', self::STATEMENT_BARRIER], true) || (($values['op'] ?? null) === self::VALUE_VARIABLE && $values['name'] === $name)) {
            return true;
        }
        if (($values['kind'] ?? null) === 'function' && basename(str_replace('\\', '/', $values['name'])) === 'extract') {
            return true;
        }
        foreach ($values as $value) {
            if (is_array($value) && self::hasRetryBindingGap($value, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only the next statement in the same execution list can consume this catch's fallback.
     */
    private static function followingReturn(Stmt\TryCatch $try, array $nodes): ?Stmt\Return_
    {
        if ($try->finally !== null) {
            return null;
        }
        $parent = $try->getAttribute(self::PARENT_ATTRIBUTE);
        if (! $parent instanceof Node) {
            return self::nextReturn($try, $nodes);
        }
        foreach ($parent->getSubNodeNames() as $field) {
            $siblings = get_object_vars($parent)[$field];
            if (! is_array($siblings)) {
                continue;
            }
            $following = self::nextReturn($try, $siblings);
            if ($following !== null) {
                return $following;
            }
        }

        return null;
    }

    /**
     * Root files and nested statement lists share the same adjacent-return boundary.
     */
    private static function nextReturn(Stmt\TryCatch $try, array $siblings): ?Stmt\Return_
    {
        $ordered = array_values($siblings);
        $position = array_search($try, $ordered, true);
        $following = $position === false ? null : ($ordered[$position + 1] ?? null);

        return $following instanceof Stmt\Return_ ? $following : null;
    }

    /**
     * An empty handler can return its next expression; an assignment must preserve its binding.
     */
    private static function doesReturnCatchValue(array $statements, Stmt\Return_ $following): bool
    {
        if ($following->expr === null) {
            return false;
        }
        $body = array_values(array_filter($statements, static fn (Node $statement): bool => ! $statement instanceof Stmt\Nop));
        if ($body === []) {
            return true;
        }
        if (count($body) !== 1) {
            return false;
        }
        $remaining = self::MAX_FACTS;
        $assignment = self::statement($body[0], null, $remaining);
        $target = $assignment['target'] ?? [];
        if (($assignment['op'] ?? null) !== 'assign' || ($target['op'] ?? null) !== self::VALUE_VARIABLE) {
            return false;
        }
        $value = self::expression($following->expr, null, $remaining);
        $variables = self::returnedVariables($value);
        $name = $target['name'];

        return $remaining >= 0 && $variables !== null && isset($variables[$name]);
    }

    /**
     * Index named class declarations and make duplicate type identities ambiguous.
     *
     * @param list<Node>       $nodes
     * @param array<int, true> $unconditional
     */
    private static function classes(array $nodes, array $unconditional, array &$types, array &$callables, array &$truncated): void
    {
        $duplicates = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Stmt\Class_::class) as $type) {
            if ($type->name === null) {
                continue;
            }

            $row = self::type($type);
            $id = strtolower($row['name']);
            $reason = self::declarationReason($type, $unconditional, $types, $id, $duplicates);
            if ($reason !== null) {
                $row['eligible'] = false;
            }

            $row['declared'] = $reason === null;
            $types[$id] = $row;
            foreach ($type->getMethods() as $method) {
                $callable = self::callable($method, $row['name'].'::'.$method->name->toString(), $id, $truncated);
                self::store($callables, $callable, $reason);
            }
        }

        self::rejectDuplicateTypes($callables, $duplicates);
    }

    /**
     * Keep duplicated and conditional declarations uncertain before indexing their members.
     *
     * @param array<int, true>    $unconditional
     * @param array<string, true> $duplicates
     */
    private static function declarationReason(Stmt\Class_ $type, array $unconditional, array $types, string $id, array &$duplicates): ?string
    {
        if (isset($types[$id])) {
            $duplicates[$id] = true;

            return self::DUPLICATE_DECLARATION;
        }

        $declarationId = spl_object_id($type);

        return isset($unconditional[$declarationId]) ? null : self::CONDITIONAL_DECLARATION;
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
            $reason = isset($unconditional[$declarationId]) ? null : self::CONDITIONAL_DECLARATION;
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
                $callable['statements'] = [self::barrier(self::DUPLICATE_DECLARATION, $callable['line'])];
                $callable['terminal'] = false;
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
        $barrier = isset($callables[$id]) ? self::DUPLICATE_DECLARATION : $reason;
        if ($barrier !== null) {
            $callable['statements'] = [self::barrier($barrier, $callable['line'])];
            $callable['terminal'] = false;
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
            'returnType'                            => self::nativeType($node->returnType, $class), 'byRef' => $node->byRef, 'statements' => $statements,
            'terminal'                              => self::isTerminal($node, $statements, $class, $remaining)];
    }

    /**
     * Native terminal syntax supplies the positive proof; scalar gaps only veto it.
     */
    private static function isTerminal(Stmt\ClassMethod|Stmt\Function_ $node, array $statements, ?string $class, int $remaining): bool
    {
        if ($node->stmts === null || $remaining < 0) {
            return false;
        }
        $body = array_values(array_filter($node->stmts, static fn (Node $statement): bool => ! $statement instanceof Stmt\Nop));
        $last = $body === [] ? null : $body[array_key_last($body)];
        $terminal = $last instanceof Stmt\Expression ? $last->expr : null;
        if ($terminal instanceof Expr\Exit_ || $terminal instanceof Expr\Throw_) {
            $prefix = array_slice($statements, 0, -1);
            $operand = $terminal->expr === null ? [] : [self::expression($terminal->expr, $class, $remaining)];

            return ! self::hasOutcomeGap([...$prefix, ...$operand]);
        }

        return self::nativeType($node->returnType, $class) === 'never' && ! self::hasOutcomeGap($statements);
    }

    /**
     * Unknown expressions include deferred generators; unsupported control can bypass a later exit.
     */
    private static function hasOutcomeGap(array $values): bool
    {
        if (in_array($values['op'] ?? null, ['unknown', self::STATEMENT_BARRIER, 'return'], true)) {
            return true;
        }
        foreach ($values as $value) {
            if (is_array($value) && self::hasOutcomeGap($value)) {
                return true;
            }
        }

        return false;
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
    private static function statement(Node $statement, ?string $class, int &$remaining): array
    {
        $line = $statement->getStartLine();
        if ($statement instanceof Stmt\Return_) {
            return ['op' => 'return', 'value' => $statement->expr !== null ? self::expression($statement->expr, $class, $remaining) : null, 'line' => $line];
        }

        if ($statement instanceof Stmt\Expression) {
            return self::expressionStatement($statement->expr, $class, $remaining, $line);
        }

        return self::barrier($statement->getType(), $line);
    }

    /**
     * Stop body interpretation at an unresolved control or effect boundary.
     */
    private static function barrier(string $reason, int $line): array
    {
        return ['op' => self::STATEMENT_BARRIER, 'reason' => $reason, 'line' => $line];
    }
}
