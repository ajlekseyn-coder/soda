<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Design;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;

/**
 * Recognizes fresh-object construction followed by contiguous direct calls.
 *
 * @internal
 */
trait ConfigurationEvidence
{
    /**
     * Inspect statement lists independently without stitching branches together.
     */
    private function sequences(Stmt\ClassMethod|Stmt\Function_ $callable, string $identity): array
    {
        $lists = [$callable->stmts ?? []];
        foreach ($this->bodyNodes($callable->stmts ?? []) as $node) {
            $parts = get_object_vars($node);
            $statements = $parts['stmts'] ?? null;
            $nested = $node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike;
            if (! $nested && is_array($statements)) {
                $lists[] = $statements;
            }
        }

        $rows = [];
        foreach ($lists as $statements) {
            foreach (array_keys($statements) as $index) {
                $row = $this->configurationSequence($statements, $index, $identity);
                if ($row !== null) {
                    $rows[$row['line']] = $row;
                }
            }
        }

        return array_values($rows);
    }

    /**
     * Require local construction and stop at aliases, intervening statements, or escaped receivers.
     */
    private function configurationSequence(array $statements, int $index, string $identity): ?array
    {
        $instance = $this->freshObject($statements[$index]);
        if ($instance === null) {
            return null;
        }

        $calls = [];
        foreach (array_slice($statements, $index + 1) as $next) {
            $call = $this->directCall($next);
            $same = $call !== null && $call['receiver'] === $instance['receiver'];
            if (! $same || ! $this->hasConfigurationArguments($call['call'], $instance['receiver'])) {
                break;
            }

            $syntax = $call['call'];
            $calls[] = ['method' => $call['name'], 'line' => $syntax->getStartLine(), 'arguments' => count($syntax->args)];
        }

        if (count($calls) < 3) {
            return null;
        }

        return ['name' => $identity, 'line' => $statements[$index]->getStartLine(), 'type' => $instance['type'], 'calls' => $calls];
    }

    /**
     * Recognize a direct assignment of a newly constructed exact named type.
     */
    private function freshObject(Stmt $statement): ?array
    {
        $assignment = $this->localAssignment($statement);
        $value = $assignment['value'] ?? null;
        if (! $value instanceof Expr\New_ || ! $value->class instanceof Node\Name) {
            return null;
        }

        return ['receiver' => $assignment['receiver'], 'type' => strtolower($value->class->toString())];
    }

    /**
     * Recognize a named local assignment without claiming a value's type.
     *
     * @return array{receiver: string, value: Expr}|null
     */
    private function localAssignment(Stmt $statement): ?array
    {
        if (! $statement instanceof Stmt\Expression || ! $statement->expr instanceof Expr\Assign) {
            return null;
        }

        $assignment = $statement->expr;
        $named = $assignment->var instanceof Expr\Variable && is_string($assignment->var->name);

        return $named ? ['receiver' => $assignment->var->name, 'value' => $assignment->expr] : null;
    }

    /**
     * Resolve direct statement calls with literal receivers and member names.
     *
     * @return array{receiver: string, name: string, call: Expr\MethodCall}|null
     */
    private function directCall(Stmt $statement): ?array
    {
        $parts = get_object_vars($statement);
        $call = $parts['expr'] ?? null;
        $supported = in_array($statement->getType(), ['Stmt_Expression', 'Stmt_Return'], true);
        if (! $supported || ! $call instanceof Expr\MethodCall) {
            return null;
        }

        $named = $call->var instanceof Expr\Variable && is_string($call->var->name) && $call->name instanceof Identifier;

        return $named ? ['receiver' => $call->var->name, 'name' => $call->name->toLowerString(), 'call' => $call] : null;
    }

    /**
     * Simple arguments neither execute code nor expose the configured receiver.
     */
    private function hasConfigurationArguments(Expr\MethodCall $call, string $receiver): bool
    {
        foreach ($call->args as $argument) {
            if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack || $argument->name instanceof Identifier) {
                return false;
            }

            if (! $this->isSimpleConfigurationValue($argument->value, $receiver)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Preserve literals and unrelated named local values, leaving computed arguments unknown.
     */
    private function isSimpleConfigurationValue(Expr $value, string $receiver): bool
    {
        $kind = $value->getType();
        if (in_array($kind, ['Scalar_Int', 'Scalar_Float', 'Scalar_String'], true)) {
            return true;
        }

        if ($value instanceof Expr\ConstFetch) {
            $name = strtolower($value->name->toString());

            return in_array($name, ['true', 'false', 'null'], true);
        }

        return $value instanceof Expr\Variable && is_string($value->name) && ! in_array($value->name, [$receiver, 'this'], true);
    }
}
