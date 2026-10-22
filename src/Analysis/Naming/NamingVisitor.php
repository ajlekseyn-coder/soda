<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Naming;

use function array_values;

use Cosmira\Soda\Analysis\FactVisitor;
use Cosmira\Soda\Analysis\FileFacts;

use function in_array;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;

/**
 * Collects class and method names with param/return types for AvoidRedundantNaming rule.
 *
 * @internal
 *
 * @phpstan-import-type NamingFacts from FileFacts
 * @phpstan-import-type NamingType from FileFacts
 */
final class NamingVisitor extends FactVisitor
{
    /**
     * Parent edges distinguish direct declarations from conditional control bodies.
     */
    private const PARENT_ATTRIBUTE = 'parent';

    /**
     * Magic methods whose names are fixed by the engine, never by taste.
     */
    private const MAGIC_METHODS = [
        '__construct', '__destruct', '__call', '__callStatic', '__get', '__set',
        '__isset', '__unset', '__sleep', '__wakeup', '__serialize', '__unserialize',
        '__toString', '__invoke', '__set_state', '__clone', '__debugInfo',
    ];

    /**
     * Naming facts accumulated while traversing class and callable declarations.
     *
     * @var NamingFacts
     */
    private array $result = [
        'classes' => [],
        'methods' => [],
        'types'   => [],
    ];

    /**
     * Type declarations indexed for inherited naming-contract resolution.
     *
     * @var array<string, NamingType>
     */
    private array $types = [];

    /**
     * Collect facts before traversing the children of this syntax node.
     */
    #[\Override]
    protected function doEnterNode(Node $node): void
    {
        $isType = $node instanceof Class_ || $node instanceof Trait_ || $node instanceof Interface_;
        if ($isType) {
            $this->enterType($node);

            return;
        }

        if ($node instanceof ClassMethod) {
            $this->enterMethod($node);

            return;
        }

        if ($node instanceof Function_) {
            $this->enterFunction($node);
        }
    }

    /**
     * Restore analysis state after traversing the children of this syntax node.
     */
    #[\Override]
    protected function doLeaveNode(Node $node): void
    {
        if ($node instanceof Class_ || $node instanceof Trait_ || $node instanceof Interface_) {
            $this->leaveType($node);
        }
    }

    /**
     * Enter type using the current analysis state.
     */
    private function enterType(Class_|Trait_|Interface_ $node): void
    {
        $name = $node->namespacedName?->toString();
        $hasNoName = $name === null || $name === '';
        if ($hasNoName) {
            return;
        }

        $methods = $this->methodDeclarations($node);
        $this->types[$name] = [
            'name'               => $name,
            'kind'               => $this->kindOf($node),
            'line'               => $node->getStartLine(),
            'inherits'           => $this->inheritsOf($node),
            'methods'            => array_column($methods, 'name'),
            'methodDeclarations' => $methods,
            'unconditional'      => $node->getAttribute(self::PARENT_ATTRIBUTE) === null || $node->getAttribute(self::PARENT_ATTRIBUTE) instanceof Node\Stmt\Namespace_,
            'ambiguous'          => isset($this->types[$name]),
            'traits'             => [],
            'traitsResolved'     => true,
        ];
        $isInterface = $node instanceof Interface_;

        if (! $isInterface) {
            $classes = $this->result['classes'];
            $classes[] = [
                'class' => $name,
                'line'  => $node->getStartLine(),
            ];
            $this->result['classes'] = $classes;
        }
    }

    /**
     * Direct declarations supply method ownership even when a nested anonymous scope is being visited.
     */
    private function methodDeclarations(Class_|Trait_|Interface_ $node): array
    {
        $methods = [];
        foreach ($node->getMethods() as $method) {
            $name = $method->name->toString();
            if (! in_array($name, self::MAGIC_METHODS, true)) {
                $methods[strtolower($name)] = ['name' => $name, 'start' => $method->getStartFilePos(),
                    'private'                         => $method->isPrivate(), 'abstract' => $method->stmts === null];
            }
        }

        return $methods;
    }

    /**
     * Trait imports have resolved names after their children were traversed; adaptations remain unknown.
     */
    private function leaveType(Class_|Trait_|Interface_ $node): void
    {
        $name = $node->namespacedName?->toString();
        if ($name === null || ! isset($this->types[$name])) {
            return;
        }

        $traits = [];
        $resolved = true;
        foreach ($node->getTraitUses() as $use) {
            array_push($traits, ...array_map(static fn (Node\Name $trait): string => $trait->toString(), $use->traits));
            $resolved = $resolved && $use->adaptations === [];
        }

        $type = $this->types[$name];
        $type['traits'] = $traits;
        $type['traitsResolved'] = $resolved;
        $this->types[$name] = $type;
    }

    /**
     * Enter method using the current analysis state.
     */
    private function enterMethod(ClassMethod $node): void
    {
        $methodName = $node->name->toString();
        if (in_array($methodName, self::MAGIC_METHODS, true)) {
            return;
        }

        $owner = $node->getAttribute(self::PARENT_ATTRIBUTE);
        if ($owner instanceof Interface_) {
            return;
        }

        $class = $owner instanceof Class_ || $owner instanceof Trait_ ? $owner->namespacedName?->toString() : null;

        $methods = $this->result['methods'];
        $method = NamingMethodFacts::fromClassMethod($node, $class);
        $method['declarationStart'] = $node->getStartFilePos();
        $methods[] = $method;
        $this->result['methods'] = $methods;
    }

    /**
     * Enter function using the current analysis state.
     */
    private function enterFunction(Function_ $node): void
    {
        $methodResult = NamingMethodFacts::fromFunction($node);

        if ($methodResult === null) {
            return;
        }

        $methods = $this->result['methods'];
        $methods[] = $methodResult;
        $this->result['methods'] = $methods;
    }

    /**
     * Return the collected class, function, and method naming facts.
     *
     * @return NamingFacts
     */
    public function facts(): array
    {
        $this->result['types'] = array_values($this->types);

        return $this->result;
    }

    /**
     * Classify a class-like declaration for the naming facts model.
     *
     * @return 'class'|'trait'|'interface'
     */
    private function kindOf(Class_|Trait_|Interface_ $node): string
    {
        return match (true) {
            $node instanceof Interface_ => 'interface',
            $node instanceof Trait_     => 'trait',
            default                     => 'class',
        };
    }

    /**
     * Collect parent and interface names from a class-like declaration.
     *
     * @return list<string>
     */
    private function inheritsOf(Class_|Trait_|Interface_ $node): array
    {
        return match (true) {
            $node instanceof Class_ => array_values(array_filter(
                [
                    $node->extends?->toString(),
                    ...array_map(static fn (Node\Name $name): string => $name->toString(), $node->implements),
                ],
                static fn (mixed $name): bool => is_string($name) && $name !== '',
            )),
            $node instanceof Interface_ => array_map(static fn (Node\Name $name): string => $name->toString(), $node->extends),
            default                     => [],
        };
    }
}
