<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Structure;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;

/**
 * Reports classes whose declared surface is entirely static.
 */
final class NoStaticOnlyClass extends Check
{
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
        return 'no_static_only_class';
    }

    /**
     * Report named classes that offer no instance behavior of their own.
     *
     * @return iterable<Violation>
     */
    #[\Override]
    public function checkFile(FileFacts $facts): iterable
    {
        foreach ((new NodeFinder)->findInstanceOf($facts->nodes, Class_::class) as $class) {
            if (! $this->isStaticOnly($class)) {
                continue;
            }

            yield new Violation(
                rule: $this->id(),
                file: $facts->path,
                value: 1,
                threshold: 0,
                class: $class->name?->toString(),
                line: $class->name?->getStartLine(),
                message: sprintf('Class %s declares only static members. Name the operations as functions or constants, or keep instance state on the class.', $class->name?->toString()),
            );
        }
    }

    /**
     * Require at least one method, all methods static, and no inherited or trait surface.
     */
    private function isStaticOnly(Class_ $class): bool
    {
        if (!$class->name instanceof \PhpParser\Node\Identifier || $class->isAbstract() || $class->extends instanceof \PhpParser\Node\Name || $class->getTraitUses() !== []) {
            return false;
        }

        $methods = $class->getMethods();
        if ($methods === []) {
            return false;
        }

        foreach ($methods as $method) {
            if (! $method->isStatic()) {
                return false;
            }
        }

        foreach ($class->getProperties() as $property) {
            if (! $property->isStatic()) {
                return false;
            }
        }

        return true;
    }
}
