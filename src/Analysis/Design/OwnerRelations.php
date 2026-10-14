<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Design;

use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Records direct owner reads, writes, and local calls.
 *
 * @internal
 */
trait OwnerRelations
{
    /**
     * Accumulate fields, local calls, and writes while preserving unresolved syntax.
     */
    private function methodRelations(array $statements, array $properties): array
    {
        $relations = ['fields' => [], 'calls' => [], 'writes' => [], 'foreign' => [], 'unknown' => false, 'meaningful' => false, 'foreignWrites' => false];
        foreach ($this->bodyNodes($statements) as $node) {
            $relations = $this->memberRelations($node, $properties, $relations);
            $relations = $this->mutationRelations($node, $relations);
            $relations['unknown'] = $relations['unknown'] || $this->isUncertain($node);
            $meaningful = in_array($node->getType(), ['Stmt_If', 'Expr_BooleanNot', 'Expr_Match'], true) || $node instanceof Expr\BinaryOp;
            $relations['meaningful'] = $relations['meaningful'] || $meaningful;
        }

        foreach (['fields', 'calls', 'writes'] as $key) {
            $relations[$key] = array_keys($relations[$key]);
        }

        return $relations;
    }

    /**
     * Separate direct owner relationships from foreign member accesses.
     */
    private function memberRelations(Node $node, array $properties, array $relations): array
    {
        if ($node instanceof Expr\PropertyFetch) {
            $field = $this->ownField($node);
            if ($field !== null) {
                $fields = $relations['fields'];
                $fields[$field] = true;
                $relations['fields'] = $fields;
                $relations['unknown'] = $relations['unknown'] || ! array_key_exists($field, $properties);
            }
        }

        if ($node instanceof Expr\PropertyFetch || $node instanceof Expr\MethodCall) {
            $direct = $node->var instanceof Expr\Variable && is_string($node->var->name) && $node->name instanceof Node\Identifier;
            if (! $direct) {
                $relations['unknown'] = true;

                return $relations;
            }

            return $this->receiverRelations($node, $relations, $node->var->name);
        }

        return $relations;
    }

    /**
     * Preserve receiver identity and the accessed member's kind.
     */
    private function receiverRelations(Expr\PropertyFetch|Expr\MethodCall $node, array $relations, string $receiver): array
    {
        if ($receiver === 'this') {
            if ($node instanceof Expr\MethodCall) {
                $calls = $relations['calls'];
                $calls[$node->name->toLowerString()] = true;
                $relations['calls'] = $calls;
            }

            return $relations;
        }

        $foreign = $relations['foreign'];
        $accesses = $foreign[$receiver] ?? [];
        $accesses[] = [$node instanceof Expr\MethodCall ? 'method' : 'property', $node->name->toString()];
        $foreign[$receiver] = $accesses;
        $relations['foreign'] = $foreign;

        return $relations;
    }

    /**
     * Recognize mutation node kinds without conflating writes with ordinary field reads.
     */
    private function mutationRelations(Node $node, array $relations): array
    {
        $mutation = in_array($node->getType(), ['Expr_Assign', 'Expr_PreInc', 'Expr_PostInc', 'Expr_PreDec', 'Expr_PostDec'], true) || $node instanceof Expr\AssignOp;
        if (! $mutation) {
            return $relations;
        }

        $parts = get_object_vars($node);
        $target = $parts['var'];
        $field = $this->ownField($target);
        if ($field !== null) {
            $writes = $relations['writes'];
            $writes[$field] = true;
            $relations['writes'] = $writes;
        }

        $foreignWrite = $field === null && $target instanceof Expr\PropertyFetch;
        $relations['foreignWrites'] = $relations['foreignWrites'] || $foreignWrite;

        return $relations;
    }
}
