<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

use function count;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;

/**
 * Collects per-method, per-class, per-file metrics for quality analysis.
 *
 * @internal
 *
 * @phpstan-import-type FileMetrics from FileFacts
 */
final class StructureVisitor extends FactVisitor
{
    /**
     * Attribute key referencing the enclosing syntax node of a declaration.
     */
    private const PARENT = 'parent';

    /**
     * Accumulated file, namespace, class, and method structural facts.
     *
     * @var FileMetrics
     */
    private array $result = [
        'file_loc'              => 0,
        'classes_count'         => 0,
        'classes'               => [],
        'interfaceParents'      => [],
        'methods'               => [],
        'namespaces'            => [],
    ];

    /**
     * Configure structural measurements with physical and logical file line information.
     *
     * @param ?LogicalLineMap $logicalLineMap Maps physical source lines to logical statement counts.
     *
     * @psalm-param non-negative-int $fileLines
     */
    public function __construct(
        /**
         * Total file line count used as the basis for structural measurements.
         *
         * @psalm-var non-negative-int
         */
        private readonly int $fileLines,
        /**
         * Optional code-line map used to exclude whitespace and comments from lengths.
         */
        private readonly ?LogicalLineMap $logicalLineMap = null,
    ) {}

    /**
     * Collect facts before traversing the children of this syntax node.
     */
    #[\Override]
    protected function doEnterNode(Node $node): void
    {
        match (true) {
            $node instanceof Class_, $node instanceof Trait_ => $this->handleType($node),
            $node instanceof Interface_                      => $this->handleInterface($node),
            $node instanceof ClassMethod                     => $this->handleClassMethod($node),
            $node instanceof Function_                       => $this->handleFunction($node),
            default                                          => null,
        };
    }

    /**
     * Handle type using the current analysis state.
     */
    private function handleType(Class_|Trait_ $node): void
    {
        $isAnonymousClass = $node->getAttribute(self::PARENT) instanceof New_;
        if ($isAnonymousClass) {
            return;
        }

        $name = $node->namespacedName?->toString();
        $hasNoName = $name === null || $name === '';
        /**
         * @psalm-suppress TypeDoesNotContainType - Name::toString() can return '' for anonymous
         */
        if ($hasNoName) {
            return;
        }

        $this->result['classes_count']++;
        $this->storeClass($name, $this->classRow($node));
        $this->updateNamespace($name);
    }

    /**
     * Compose the structural fact row of one named class or trait declaration.
     */
    private function classRow(Class_|Trait_ $node): array
    {
        $row = ClassFactsExtractor::extract($node, $this->logicalLineMap);
        $row['concrete_method_names'] = array_values(array_map(fn (ClassMethod $method): string => $method->name->toLowerString(), array_filter($node->getMethods(), fn (ClassMethod $method): bool => ! $method->isAbstract())));
        $row['trait_method_aliases'] = TraitAdaptations::methodAliases($node);
        $row['public_method_names'] = ClassFactsExtractor::publicMethodNames($node);
        $row['trait_names'] = ClassFactsExtractor::usedTraitNames($node);
        $row['property_keys'] = PropertyMetrics::keys($node);
        $row['public_trait_aliases'] = TraitAdaptations::publicTraitAliasNames($node);
        $row['hidden_trait_methods'] = TraitAdaptations::nonPublicTraitMethodNames($node);

        return $this->classParents($node, $row);
    }

    /**
     * Attach resolved inheritance facts that only concrete classes declare.
     *
     * @param array $row Structural facts collected so far for the declaration.
     */
    private function classParents(Class_|Trait_ $node, array $row): array
    {
        if ($node instanceof Class_) {
            $parent = ClassFactsExtractor::parentType($node);
            if ($parent !== null) {
                $row['parent'] = $parent;
            }

            $row['interface_names'] = ClassFactsExtractor::implementedInterfaceNames($node);
        }

        return $row;
    }

    /**
     * Record one class fact row under its resolved qualified name.
     *
     * @param array $row Structural facts of the class or trait declaration.
     */
    private function storeClass(string $name, array $row): void
    {
        $classes = $this->result['classes'];
        $classes[$name] = $row;
        $this->result['classes'] = $classes;
    }

    /**
     * Handle interface using the current analysis state.
     */
    private function handleInterface(Interface_ $node): void
    {
        $name = $node->namespacedName?->toString();
        $hasNoName = $name === null || $name === '';
        if ($hasNoName) {
            return;
        }

        $parents = $this->result['interfaceParents'];
        $parents[$name] = ClassFactsExtractor::extendedInterfaceNames($node);
        $this->result['interfaceParents'] = $parents;
    }

    /**
     * Update namespace using the current analysis state.
     */
    private function updateNamespace(string $name): void
    {
        $classes = $this->result['classes'];
        $classRow = $classes[$name];
        $namespace = $classRow['namespace'] ?? '';
        if ($namespace === '') {
            return;
        }

        $namespaces = $this->result['namespaces'];
        $namespaces[$namespace] = ($namespaces[$namespace] ?? 0) + 1;
        $this->result['namespaces'] = $namespaces;
    }

    /**
     * Handle class method using the current analysis state.
     */
    private function handleClassMethod(ClassMethod $node): void
    {
        if ($this->isSkippedMethod($node)) {
            return;
        }

        $owner = $node->getAttribute(self::PARENT);
        $class = $owner instanceof ClassLike ? $owner->namespacedName?->toString() : null;
        if ($class === null) {
            return;
        }

        $this->recordMethod($node, $class);
        $this->incrementClassMethods($class);
    }

    /**
     * Interface members, anonymous-class members and abstract declarations carry no body facts.
     */
    private function isSkippedMethod(ClassMethod $node): bool
    {
        return $node->getAttribute(self::PARENT) instanceof Interface_
            || AnonymousClassBoundary::isDirectMember($node)
            || $node->isAbstract();
    }

    /**
     * Store the per-method fact row, including readonly data field evidence.
     */
    private function recordMethod(ClassMethod $node, string $class): void
    {
        $name = $class.'::'.$node->name->toString();
        $loc = $this->linesBetween($node->getStartLine(), $node->getEndLine());
        $methods = $this->result['methods'];
        $methods[$name] = ['line' => $node->getStartLine(), 'loc' => $loc, 'args' => count($node->params)];
        $readonlyDataFields = PropertyMetrics::readonlyDataFields($node);
        if ($readonlyDataFields !== null) {
            $row = $methods[$name];
            $row['readonlyDataFields'] = $readonlyDataFields;
            $methods[$name] = $row;
        }

        $this->result['methods'] = $methods;
    }

    /**
     * Count the method against its declaring class when the class row already exists.
     */
    private function incrementClassMethods(string $class): void
    {
        $classes = $this->result['classes'];
        if (! isset($classes[$class])) {
            return;
        }

        $row = $classes[$class];
        $row['methods']++;
        $classes[$class] = $row;
        $this->result['classes'] = $classes;
    }

    /**
     * Handle function using the current analysis state.
     */
    private function handleFunction(Function_ $node): void
    {
        $name = $node->namespacedName?->toString();
        $hasNoName = $name === null || $name === '';
        /**
         * @psalm-suppress TypeDoesNotContainType - Name::toString() can return '' for anonymous
         */
        if ($hasNoName) {
            return;
        }

        $loc = $this->linesBetween($node->getStartLine(), $node->getEndLine());
        $methods = $this->result['methods'];
        $methods[$name] = ['line' => $node->getStartLine(), 'loc' => $loc, 'args' => count($node->params)];
        $this->result['methods'] = $methods;
    }

    /**
     * Return the structural facts accumulated during syntax-tree traversal.
     *
     * @return FileMetrics
     */
    public function metrics(): array
    {
        $this->result['file_loc'] = $this->fileLines;

        return $this->result;
    }

    /**
     * Count logical source lines within the inclusive source span.
     */
    private function linesBetween(int $startLine, int $endLine): int
    {
        return $this->logicalLineMap?->countBetween($startLine, $endLine)
            ?? $endLine - $startLine + 1;
    }
}
