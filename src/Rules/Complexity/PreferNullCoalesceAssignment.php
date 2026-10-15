<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Complexity;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/**
 * Reports fallback assignments that repeat the target on both sides.
 */
final class PreferNullCoalesceAssignment extends Check
{
    /**
     * AST link to the immediately enclosing syntax node.
     */
    private const PARENT = 'parent';

    /**
     * Declare an AST-only check.
     *
     * @return list<string>
     */
    #[\Override]
    public function requiredAnalyses(): array
    {
        return [];
    }

    /**
     * Return the stable rule identifier.
     */
    #[\Override]
    public function id(): string
    {
        return 'prefer_null_coalesce_assignment';
    }

    /**
     * Report `$target = $target ?? fallback` patterns.
     *
     * @return iterable<Violation>
     */
    #[\Override]
    public function checkFile(FileFacts $facts): iterable
    {
        $printer = new Standard;
        foreach ((new NodeFinder)->findInstanceOf($facts->nodes, Assign::class) as $assign) {
            if (! $assign->expr instanceof Coalesce) {
                continue;
            }

            $target = $printer->prettyPrintExpr($assign->var);
            if ($target !== $printer->prettyPrintExpr($assign->expr->left)) {
                continue;
            }

            if ($this->doesReadWriteState($assign)) {
                continue;
            }

            yield new Violation(
                rule: $this->id(),
                file: $facts->path,
                value: 1,
                threshold: 0,
                line: $assign->getStartLine(),
                message: sprintf('Assign the fallback with the ??= operator instead of repeating %s on both sides.', $target),
            );
        }
    }

    /**
     * Reevaluation and skipped property writes must preserve observable behavior.
     */
    private function doesReadWriteState(Assign $assign): bool
    {
        if (StateWriteSearch::hasStateWriteOrCall($assign->var)) {
            return true;
        }

        $property = (new NodeFinder)->findFirst([$assign->var], static fn ($node): bool => $node instanceof PropertyFetch);

        return $property instanceof PropertyFetch && ($property !== $assign->var || ! $this->isPlainProperty($property));
    }

    /**
     * Only an owned field with no hooks or possible magic setter proves an ordinary write.
     */
    private function isPlainProperty(PropertyFetch $target): bool
    {
        $name = $target->name;
        if (! $name instanceof Identifier) {
            return false;
        }

        $class = $this->propertyOwner($target);
        if ($class === null || ! $this->hasPlainWrites($class)) {
            return false;
        }

        foreach ($class->getProperties() as $property) {
            if ($property->isStatic() || $property->isReadonly() || $property->hooks !== []) {
                continue;
            }

            foreach ($property->props as $item) {
                if ($item->name->toString() === $name->toString()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Rebound closures and unrelated receivers do not inherit the declaring method's property proof.
     */
    private function propertyOwner(PropertyFetch $target): ?Class_
    {
        if (! $target->var instanceof Variable || $target->var->name !== 'this') {
            return null;
        }

        $scope = $target->getAttribute(self::PARENT);
        while ($scope instanceof Node && ! $scope instanceof FunctionLike) {
            $scope = $scope->getAttribute(self::PARENT);
        }

        $class = $scope instanceof Node ? $scope->getAttribute(self::PARENT) : null;
        if (! $class instanceof Class_) {
            return null;
        }

        foreach ($class->getMethods() as $method) {
            if ($method === $scope) {
                return $method->isStatic() ? null : $class;
            }
        }

        return null;
    }

    /**
     * Parent classes, traits and subclasses can introduce accessors outside this declaration.
     */
    private function hasPlainWrites(Class_ $class): bool
    {
        if (! $class->isFinal() || $class->isReadonly() || $class->extends !== null || $class->getTraitUses() !== []) {
            return false;
        }

        foreach (['__get', '__set', '__isset'] as $method) {
            if ($class->getMethod($method) !== null) {
                return false;
            }
        }

        return true;
    }
}
