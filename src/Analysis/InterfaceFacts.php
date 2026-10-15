<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Collects interface contracts and consumer edges without retaining ASTs.
 */
final class InterfaceFacts
{
    /**
     * Separate outside consumers from references that become useful with their interface owner.
     *
     * @param list<Node> $nodes
     */
    public static function collect(array $nodes): array
    {
        $interfaces = self::declaredInterfaces($nodes);
        [$references, $dependencies] = self::referencedNames($nodes);
        [$references, $dependencies] = self::documentedReferences($nodes, $references, $dependencies);

        return ['declarations' => $interfaces, 'references' => $references, 'dependencies' => $dependencies];
    }

    /**
     * Index every declared interface with its method signatures and inherited contracts.
     *
     * @param list<Node> $nodes
     */
    private static function declaredInterfaces(array $nodes): array
    {
        $interfaces = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Stmt\Interface_::class) as $interface) {
            $name = $interface->namespacedName?->toString() ?? $interface->name->toString();
            $interfaces[strtolower($name)] = [
                'name'          => $name,
                'isInterface'   => true,
                'hasProperties' => $interface->getProperties() !== [],
                'parentNames'   => array_map(fn (Node\Name $parent): string => $parent->toString(), $interface->extends),
                'line'          => $interface->getStartLine(),
                'methods'       => array_map(fn (Stmt\ClassMethod $method): string => strtolower($method->name->toString()), $interface->getMethods()),
                'parents'       => array_map(fn (Node\Name $parent): string => strtolower($parent->toString()), $interface->extends),
            ];
        }

        return $interfaces;
    }

    /**
     * Separate free type references from dependencies owned by an interface declaration.
     *
     * @param list<Node> $nodes
     *
     * @return array{0: array<string, true>, 1: array<string, array<string, true>>}
     */
    private static function referencedNames(array $nodes): array
    {
        $references = [];
        $dependencies = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Name::class) as $reference) {
            if (! self::isTypeReference($reference)) {
                continue;
            }

            $target = strtolower($reference->toString());
            $owner = self::interfaceOwner($reference);
            if ($owner === null) {
                $references[$target] = true;

                continue;
            }

            $outgoing = $dependencies[$owner] ?? [];
            $outgoing[$target] = true;
            $dependencies[$owner] = $outgoing;
        }

        return [$references, $dependencies];
    }

    /**
     * Merge documented PHPDoc type references into free references and interface dependencies.
     *
     * @param list<Node> $nodes
     *
     * @return array{0: array<string, true>, 1: array<string, array<string, true>>}
     */
    private static function documentedReferences(array $nodes, array $references, array $dependencies): array
    {
        $free = $references;
        foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool => $node->getDocComment() instanceof Doc) as $node) {
            $documented = self::documentedTypes($node);
            $owner = self::interfaceOwner($node);
            if ($owner === null) {
                $free += $documented;

                continue;
            }

            $dependencies[$owner] = ($dependencies[$owner] ?? []) + $documented;
        }

        return [$free, $dependencies];
    }

    /**
     * Imports, declarations of implementation, and function or constant names do not consume a type.
     */
    private static function isTypeReference(Node\Name $reference): bool
    {
        $parent = $reference->getAttribute('parent');
        if ($parent instanceof Stmt\Class_ && in_array($reference, $parent->implements, true)) {
            return false;
        }

        return ! in_array($parent?->getType(), [
            'Expr_FuncCall', 'Expr_ConstFetch', 'UseItem', 'Stmt_Use', 'Stmt_GroupUse', 'Stmt_Namespace',
        ], true);
    }

    /**
     * Interface signatures and inheritance only consume contracts when the owning interface is used.
     */
    private static function interfaceOwner(Node $node): ?string
    {
        $parent = $node;
        while ($parent instanceof Node) {
            if ($parent instanceof Stmt\Interface_) {
                return strtolower($parent->namespacedName?->toString() ?? $parent->name->toString());
            }

            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    /**
     * Preserve explicit class types in parameter, result and property annotations.
     */
    private static function documentedTypes(Node $node): array
    {
        $comment = $node->getDocComment();
        if (! $comment instanceof Doc) {
            return [];
        }

        preg_match_all('/^\s*(?:\/\*\*|\*)\s*@(param|return|var|template(?:-covariant|-contravariant)?)\s+([^\r\n]+)/m', $comment->getText(), $annotations, PREG_SET_ORDER);
        $references = [];
        foreach ($annotations as $annotation) {
            array_shift($annotation);
            $tag = array_shift($annotation);
            $type = self::typeExpression($tag, array_shift($annotation));
            if (! self::isDeclarationTag($node, $tag)) {
                continue;
            }

            preg_match_all('/\\\\[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/', $type, $classes);
            foreach ($classes[0] as $class) {
                $references[strtolower(ltrim($class, '\\'))] = true;
            }
        }

        return $references;
    }

    /**
     * Read the declared type or generic bound, excluding descriptions and default values.
     */
    private static function typeExpression(string $tag, string $text): string
    {
        $normalized = trim($text);
        $tokens = preg_split('/\s+/', $normalized);
        if ($tokens === false) {
            return '';
        }

        $first = array_shift($tokens) ?? '';
        $isTemplate = str_starts_with($tag, 'template');
        if (! $isTemplate) {
            return $first;
        }

        $relation = array_shift($tokens);
        if (! in_array($relation, ['of', 'as'], true)) {
            return '';
        }

        return array_shift($tokens) ?? '';
    }

    /**
     * Match annotation roles to declarations rather than accepting arbitrary comment text.
     */
    private static function isDeclarationTag(Node $node, string $tag): bool
    {
        return match ($tag) {
            'var'                                                      => $node instanceof Stmt\Property,
            'param', 'return'                                          => $node instanceof Node\FunctionLike,
            'template', 'template-covariant', 'template-contravariant' => $node instanceof Stmt\ClassLike || $node instanceof Node\FunctionLike,
            default                                                    => false,
        };
    }
}
