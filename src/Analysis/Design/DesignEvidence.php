<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Design;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Collects bounded design evidence while the shared resolved AST is available.
 * Project checks retain only scalar rows, never source trees or parser instances.
 *
 * @internal
 */
final class DesignEvidence
{
    use ConfigurationEvidence;
    use DesignSyntax;
    use ForeignDecisionEvidence;
    use ForeignUpdateEvidence;
    use InvocationEvidence;
    use OwnerAccessors;
    use OwnerMethodEvidence;
    use OwnerRelations;
    use OwnerStateEvidence;
    use OwnerSyntax;

    /**
     * Record local concrete types and independent callable scenarios.
     *
     * @param list<Node> $nodes
     *
     * @return array{types: array<string, array>, decisions: list<array>, updates: list<array>, sequences: list<array>, invocations: list<array>}
     */
    public static function collect(array $nodes): array
    {
        $evidence = new self;
        $types = [];
        $decisions = [];
        $updates = [];
        $sequences = [];
        $invocations = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Stmt\Class_::class) as $class) {
            if ($class->name === null) {
                continue;
            }

            $name = isset($class->namespacedName) ? $class->namespacedName->toString() : $class->name->toString();
            $types[strtolower($name)] = $evidence->type($class, $name);
        }

        foreach ((new NodeFinder)->find($nodes, fn (Node $node): bool => $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) as $callable) {
            $identity = $evidence->identity($callable);
            array_push($decisions, ...$evidence->decisions($callable, $identity));
            array_push($updates, ...$evidence->updates($callable, $identity));
            array_push($sequences, ...$evidence->sequences($callable, $identity));
            array_push($invocations, ...$evidence->invocations($callable));
        }

        return ['types' => $types, 'decisions' => $decisions, 'updates' => $updates, 'sequences' => $sequences, 'invocations' => $invocations];
    }

    /**
     * Preserve lexical ownership so repeated calls in one method count only once.
     */
    private function identity(Stmt\ClassMethod|Stmt\Function_ $callable): string
    {
        $parent = $callable->getAttribute('parent');
        if ($parent instanceof Stmt\Class_ && ! $parent->isAnonymous()) {
            $name = isset($parent->namespacedName) ? $parent->namespacedName->toString() : $parent->name->toString();

            return $name.'::'.$callable->name->toString();
        }

        return isset($callable->namespacedName) ? $callable->namespacedName->toString() : $callable->name->toString().'@'.$callable->getStartLine();
    }

    /**
     * Attach file provenance while building an unambiguous project type index.
     */
    public static function types(array $files): array
    {
        $types = [];
        $duplicates = [];
        foreach ($files as $file => $metrics) {
            $design = $metrics['ownerDesign'] ?? [];
            foreach ($design['types'] ?? [] as $key => $type) {
                if (isset($types[$key])) {
                    $duplicates[$key] = true;
                }

                $types[$key] = [...$type, 'file' => $file];
            }
        }

        return array_diff_key($types, $duplicates);
    }

    /**
     * Describe all evidence locations using stable source names and lines.
     */
    public static function locations(array $rows): string
    {
        return implode('; ', array_map(fn (array $row): string => $row['name'].' at '.$row['file'].':'.$row['line'], $rows));
    }
}
