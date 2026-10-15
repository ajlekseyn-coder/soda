<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Composition;

use Cosmira\Soda\Analysis\Architecture\Cohesion;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\AssignOp\Coalesce;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Compact method evidence shared by checks that must see trait and parent composition.
 */
final class ClassBehaviorFacts
{
    /**
     * Capture method behavior before releasing the file AST.
     */
    public static function collect(array $nodes): array
    {
        $skeletons = (new Cohesion)->collect($nodes);

        $types = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Stmt\ClassLike::class) as $class) {
            if ($class->name === null || $class instanceof Stmt\Interface_) {
                continue;
            }

            $name = $class->namespacedName?->toString() ?? $class->name->toString();
            $types[strtolower($name)] = self::declaration($class, $skeletons[$name]);
        }

        return $types;
    }

    /**
     * Add lexical behavior and field metadata to the declaration skeleton.
     */
    private static function declaration(Stmt\ClassLike $class, array $type): array
    {
        $name = $class->namespacedName?->toString() ?? $class->name->toString();

        $type['contracts'] = $class instanceof Stmt\Class_ ? array_map(fn (Node\Name $contract): string => strtolower($contract->toString()), $class->implements) : [];
        $type['abstract'] = $class instanceof Stmt\Class_ && $class->isAbstract();
        $properties = $type['properties'];
        $origins = array_fill_keys(array_keys($properties), strtolower($name));
        $blocked = [];
        $propertyTypes = [];
        $methods = [];
        foreach ($class->getMethods() as $method) {
            $scope = $class instanceof Stmt\Trait_ ? null : strtolower($name);
            $methods[$method->name->toLowerString()] = [
                ...MethodBehaviorFacts::collect($method, $name, $scope),
                'sharedReturn' => self::hasSharedReturn($method->stmts ?? [], $method->isStatic(), $class),
            ];
        }

        foreach (self::fields($class) as $field => $metadata) {
            $properties[$field] = $metadata['visibility'];
            $origins[$field] = strtolower($name);
            if ($metadata['type'] !== null) {
                $propertyTypes[$field] = $metadata['type'];
            }

            if ($metadata['blocked']) {
                $blocked[$field] = true;
            }
        }

        return array_replace($type, [
            'methods'       => $methods, 'properties' => $properties, 'propertyOrigins' => $origins,
            'propertyTypes' => $propertyTypes, 'blockedReads' => $blocked,
        ]);
    }

    /**
     * Normalize declared and promoted fields before composing their ownership and behavior.
     */
    private static function fields(Stmt\ClassLike $class): iterable
    {
        foreach ($class->getProperties() as $property) {
            $metadata = [
                'visibility' => $property->isPrivate() ? 'private' : 'public',
                'type'       => self::typeName($property->type),
                'blocked'    => $property->hooks !== [] || $property->isStatic(),
            ];
            foreach ($property->props as $item) {
                yield $item->name->toString() => [
                    ...$metadata,
                    'objectCache' => self::isEmptyStaticSlot($property->isStatic(), $item->default),
                ];
            }
        }

        foreach ($class->getMethod('__construct')?->params ?? [] as $parameter) {
            if (! $parameter->isPromoted() || ! $parameter->var instanceof Expr\Variable || ! is_string($parameter->var->name)) {
                continue;
            }

            yield $parameter->var->name => [
                'visibility'  => $parameter->isPrivate() ? 'private' : 'public',
                'type'        => self::typeName($parameter->type),
                'blocked'     => $parameter->hooks !== [],
                'objectCache' => false,
            ];
        }
    }

    /**
     * A nullable named dependency still has one statically known receiver type.
     */
    private static function typeName(?Node $type): ?string
    {
        $resolved = $type instanceof Node\NullableType ? $type->type : $type;

        return $resolved instanceof Node\Name ? strtolower($resolved->toString()) : null;
    }

    /**
     * A lazy construction returned from a declared static slot proves object reuse.
     */
    private static function hasSharedReturn(array $body, bool $static, Stmt\ClassLike $class): bool
    {
        $statements = array_values(array_filter($body, static fn (Node $node): bool => $node->getType() !== 'Stmt_Nop'));
        $return = count($statements) === 1 ? $statements[0] : null;
        $expression = $return?->getType() === 'Stmt_Return' ? get_object_vars($return)['expr'] : null;
        if (! $static || ! $expression instanceof Coalesce || ! $expression->expr instanceof New_) {
            return false;
        }

        $slot = self::sharedSlot($expression->var);
        foreach (self::fields($class) as $field => $metadata) {
            if ($field === $slot && $metadata['objectCache']) {
                return true;
            }
        }

        return false;
    }

    /**
     * A non-null initial scalar makes the object-construction fallback unreachable until another write.
     */
    private static function isEmptyStaticSlot(bool $static, ?Node $default): bool
    {
        return $static && ($default === null || ($default instanceof ConstFetch && $default->name->toLowerString() === 'null'));
    }

    /**
     * Dynamic property names and foreign receivers do not prove an owned storage slot.
     */
    private static function sharedSlot(Node $slot): ?string
    {
        if (! $slot instanceof StaticPropertyFetch || ! $slot->class instanceof Node\Name || $slot->name->getType() !== 'VarLikeIdentifier') {
            return null;
        }

        return in_array($slot->class->toLowerString(), ['self', 'static'], true) ? get_object_vars($slot->name)['name'] : null;
    }
}
