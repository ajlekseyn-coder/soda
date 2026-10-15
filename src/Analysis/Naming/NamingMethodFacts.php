<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Naming;

use function collect;

use Cosmira\Soda\Analysis\FileFacts;

use function implode;
use function ltrim;

use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\UnionType;

use function strtolower;

/**
 * @internal
 *
 * @phpstan-import-type NamingMethod from FileFacts
 */
final class NamingMethodFacts
{
    /**
     * Extract a declared class method signature and its naming-policy context.
     *
     * @return NamingMethod
     */
    public static function fromClassMethod(ClassMethod $node, ?string $class): array
    {
        $methodName = $node->name->toString();
        $fullName = $class !== null ? $class.'::'.$methodName : $methodName;

        return [
            'name'                 => $fullName,
            'methodName'           => $methodName,
            'class'                => $class,
            'firstParamType'       => self::firstParamType($node->params),
            'returnType'           => self::returnTypeLabel($node->getReturnType()),
            'line'                 => $node->getStartLine(),
            'isPublic'             => $node->isPublic(),
            'hasOverrideAttribute' => self::hasOverrideAttribute($node->attrGroups),
        ];
    }

    /**
     * Extract a named function signature for naming-policy measurements.
     *
     * @return NamingMethod|null
     */
    public static function fromFunction(Function_ $node): ?array
    {
        $name = $node->namespacedName?->toString();
        $hasNoName = $name === null || $name === '';

        if ($hasNoName) {
            return null;
        }

        return [
            'name'                 => $name,
            'methodName'           => $name,
            'class'                => null,
            'firstParamType'       => self::firstParamType($node->params),
            'returnType'           => self::returnTypeLabel($node->getReturnType()),
            'line'                 => $node->getStartLine(),
            'isPublic'             => true,
            'hasOverrideAttribute' => false,
        ];
    }

    /**
     * Recognize the Override attribute marking an inherited method contract.
     *
     * @param list<AttributeGroup> $attributeGroups
     */
    private static function hasOverrideAttribute(array $attributeGroups): bool
    {
        foreach ($attributeGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                $isOverride = strtolower(ltrim($attribute->name->toString(), '\\')) === 'override';
                if ($isOverride) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract the first parameter type for redundant-name comparisons.
     *
     * @param list<Node\Param> $params
     */
    private static function firstParamType(array $params): ?string
    {
        $first = collect($params)->first();
        $lacksType = $first === null || $first->type === null;

        if ($lacksType) {
            return null;
        }

        return self::fromTypeNode($first->type);
    }

    /**
     * Describe a declared return type for redundancy checks.
     */
    private static function returnTypeLabel(Identifier|Name|Node\ComplexType|null $returnType): ?string
    {
        return $returnType !== null ? self::fromTypeNode($returnType) : null;
    }

    /**
     * Build the result from type node.
     */
    private static function fromTypeNode(Node $type): string
    {
        return match (true) {
            $type instanceof Name         => $type->toString(),
            $type instanceof Identifier   => $type->name,
            $type instanceof NullableType => self::fromTypeNode($type->type).'|null',
            $type instanceof UnionType    => self::unionLabel($type),
            default                       => '',
        };
    }

    /**
     * Combine the member names of a union return type.
     */
    private static function unionLabel(UnionType $type): string
    {
        return implode('|', array_map(
            self::fromTypeNode(...),
            $type->types,
        ));
    }
}
