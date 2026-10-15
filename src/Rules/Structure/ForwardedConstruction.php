<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Structure;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * Recognizes transparent construction and direct calls through native incoming receivers.
 *
 * @internal
 */
trait ForwardedConstruction
{
    use NativeFactoryInputs;

    /**
     * Retain declarations and direct incoming calls without keeping their ASTs.
     */
    private static function factoryConstruction(array $nodes): array
    {
        $types = [];
        $calls = [];
        $candidates = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Stmt\ClassLike::class) as $class) {
            if ($class->name === null) {
                continue;
            }

            $name = isset($class->namespacedName) ? $class->namespacedName->toString() : $class->name->toString();
            $declaration = self::factoryDeclaration($class, $name);
            $key = strtolower($name);
            $types[$key] = isset($types[$key])
                ? ['open' => false, 'methods' => [], 'parent' => null, 'traits' => true]
                : $declaration['type'];
            array_push($calls, ...$declaration['calls']);
            array_push($candidates, ...$declaration['candidates']);
        }

        return ['types' => $types, 'calls' => $calls, 'candidates' => $candidates];
    }

    /**
     * Keep original candidate provenance separate from effective native receiver selection.
     */
    private static function factoryDeclaration(Stmt\ClassLike $class, string $name): array
    {
        $methods = [];
        $calls = [];
        $candidates = [];
        foreach ($class->getMethods() as $method) {
            $identity = strtolower($name.'::'.$method->name->toString());
            $methods[strtolower($method->name->toString())] = [
                'origin' => $identity, 'arity' => count($method->params),
                'open'   => $method->isPublic() && ! $method->isFinal() && ! $method->isStatic(),
            ];
            $creation = self::fromMethod($method);
            if ($creation instanceof New_) {
                $candidates[] = ['class' => $name, 'method' => $method->name->toString(),
                    'line'               => $method->getStartLine(), 'creation' => $creation->class->toString(), 'identity' => $identity];
            }

            if ($class instanceof Stmt\Class_) {
                array_push($calls, ...self::incomingFactoryCalls($method));
            }
        }

        $parent = $class instanceof Stmt\Class_ ? $class->extends?->toString() : null;
        $type = ['parent' => $parent, 'methods' => $methods,
            'open'        => $class instanceof Stmt\Class_ && ! $class->isFinal(),
            'traits'      => $class->getTraitUses() !== []];

        return ['type' => $type, 'calls' => $calls, 'candidates' => $candidates];
    }

    /**
     * Return construction evidence only when the entire method is a transparent factory.
     */
    private static function fromMethod(ClassMethod $method): ?New_
    {
        $creation = self::construction($method);
        if ($method->byRef || ! $creation instanceof New_ || ! self::doesForwardParameters($method, $creation)) {
            return null;
        }

        return $creation;
    }

    /**
     * Resolve a literal concrete class construction in the return statement.
     */
    private static function construction(ClassMethod $method): ?New_
    {
        $statements = $method->stmts ?? [];
        $last = array_pop($statements);
        $returned = $last instanceof Stmt\Return_ ? $last->expr : null;
        $creation = self::returnedExpression($returned, $statements);
        if (! $creation instanceof New_ || ! $creation->class instanceof Name) {
            return null;
        }

        $isRelative = in_array(strtolower($creation->class->toString()), ['self', 'static', 'parent'], true);
        $aliases = self::aliases($statements);
        if ($isRelative || $aliases === null) {
            return null;
        }

        return self::normalize($creation, $aliases);
    }

    /**
     * A returned local result and its direct aliases do not add construction behavior.
     */
    private static function returnedExpression(?Expr $expression, array &$statements): ?Expr
    {
        $returned = $expression;
        while ($returned instanceof Expr\Variable && $statements !== []) {
            $last = end($statements);
            $assignment = $last instanceof Stmt\Expression ? $last->expr : null;
            $isLocal = $assignment instanceof Expr\Assign && $assignment->var instanceof Expr\Variable;
            if (! $isLocal || $assignment->var->name !== $returned->name) {
                break;
            }

            array_pop($statements);
            $returned = $assignment->expr;
        }

        return $returned;
    }

    /**
     * Recognize only assignments that preserve an existing variable value.
     */
    private static function aliases(array $statements): ?array
    {
        $aliases = [];
        foreach ($statements as $statement) {
            $assignment = $statement instanceof Stmt\Expression ? $statement->expr : null;
            if (! $assignment instanceof Expr\Assign) {
                return null;
            }

            $isTarget = $assignment->var instanceof Expr\Variable && is_string($assignment->var->name);
            $isSource = $assignment->expr instanceof Expr\Variable && is_string($assignment->expr->name);
            if (! $isTarget || ! $isSource) {
                return null;
            }

            $aliases[$assignment->var->name] = $aliases[$assignment->expr->name] ?? $assignment->expr->name;
        }

        return $aliases;
    }

    /**
     * Normalize argument aliases in a copy, retaining named binding and source metadata.
     */
    private static function normalize(New_ $creation, array $aliases): ?New_
    {
        $normalized = clone $creation;
        $normalized->args = [];
        foreach ($creation->args as $argument) {
            if (! $argument instanceof Node\Arg) {
                return null;
            }

            $copy = clone $argument;
            if ($copy->value instanceof Expr\Variable && is_string($copy->value->name)) {
                $copy->value = new Expr\Variable($aliases[$copy->value->name] ?? $copy->value->name);
            }

            $normalized->args[] = $copy;
        }

        return $normalized;
    }

    /**
     * Compare the parameter and argument sequences without reordering or duplication.
     */
    private static function doesForwardParameters(ClassMethod $method, New_ $creation): bool
    {
        $parameters = array_map(self::parameterName(...), $method->params);
        $arguments = array_map(self::argumentName(...), $creation->args);
        $invalid = in_array(null, $parameters, true) || in_array(null, $arguments, true);

        return ! $invalid && $parameters === $arguments && count(array_unique($parameters)) === count($parameters);
    }

    /**
     * Preserve default-value, reference, variadic and promoted-state contracts.
     */
    private static function parameterName(Node\Param $parameter): ?string
    {
        $hasContract = $parameter->default instanceof Expr || $parameter->byRef || $parameter->variadic;
        $hasMetadata = $parameter->flags !== 0;
        if ($hasContract || $hasMetadata || ! $parameter->var instanceof Expr\Variable) {
            return null;
        }

        return is_string($parameter->var->name) ? $parameter->var->name : null;
    }

    /**
     * Accept an unchanged variable argument, including explicit named binding.
     */
    private static function argumentName(Node\Arg $argument): ?string
    {
        $hasBinding = $argument->unpack || $argument->byRef;
        if ($hasBinding || ! $argument->value instanceof Expr\Variable) {
            return null;
        }

        return is_string($argument->value->name) ? $argument->value->name : null;
    }
}
