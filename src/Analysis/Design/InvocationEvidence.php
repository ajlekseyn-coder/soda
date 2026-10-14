<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Design;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;

/**
 * Records straight-line calls to exactly typed local collaborators.
 *
 * @internal
 */
trait InvocationEvidence
{
    /**
     * Require a known receiver and an exactly typed first argument at a local call site.
     */
    private function invocations(Stmt\ClassMethod|Stmt\Function_ $callable): array
    {
        $types = $this->parameterTypes($callable);
        $calls = [];
        foreach ($callable->stmts ?? [] as $statement) {
            $types = $this->assignedTypes($statement, $types);
            $call = $this->directCall($statement);
            if ($call === null) {
                continue;
            }

            $owner = $this->invocationOwner($call['call'], $types);
            $receiver = $types[$call['receiver']] ?? null;
            if ($receiver !== null && $owner !== null) {
                $syntax = $call['call'];
                $calls[] = ['type' => $receiver, 'owner' => $owner, 'method' => $call['name'], 'line' => $syntax->getStartLine()];
            }
        }

        return $calls;
    }

    /**
     * Update exact local types only for direct construction or variable aliases.
     */
    private function assignedTypes(Stmt $statement, array $types): array
    {
        $assignment = $this->localAssignment($statement);
        if ($assignment === null) {
            return $types;
        }

        $instance = $this->freshObject($statement);
        $type = $instance['type'] ?? null;
        $value = $assignment['value'];
        if ($value instanceof Expr\Variable && is_string($value->name)) {
            $type = $types[$value->name] ?? null;
        }

        $target = $assignment['receiver'];
        unset($types[$target]);
        if ($type !== null) {
            $types[$target] = $type;
        }

        return $types;
    }

    /**
     * Exclude named, unpacked, reference, and untyped owner arguments.
     */
    private function invocationOwner(Expr\MethodCall $call, array $types): ?string
    {
        $argument = $call->args[0] ?? null;
        if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack || $argument->name instanceof Identifier) {
            return null;
        }

        $value = $argument->value;

        return $value instanceof Expr\Variable && is_string($value->name) ? ($types[$value->name] ?? null) : null;
    }
}
