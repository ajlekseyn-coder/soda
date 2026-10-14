<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;

/** Property identity and promoted immutable data belong to the same structural policy. */
final class PropertyMetrics
{
    /**
     * Build property identities that distinguish private declarations from shared inherited names.
     *
     * @return list<string>
     */
    public static function keys(Class_|Trait_ $node): array
    {
        $declaringType = $node->namespacedName?->toString() ?? 'anonymous';
        $keys = [...self::declaredKeys($node, $declaringType), ...self::promotedKeys($node, $declaringType)];

        return array_values(array_unique($keys));
    }

    /**
     * Qualify private property declarations with the type that owns their storage.
     *
     * @return list<string>
     */
    private static function declaredKeys(Class_|Trait_ $node, string $declaringType): array
    {
        $keys = [];
        foreach ($node->getProperties() as $property) {
            foreach ($property->props as $item) {
                $name = $item->name->toString();
                $keys[] = $property->isPrivate() ? $declaringType.'::$'.$name : '$'.$name;
            }
        }

        return $keys;
    }

    /**
     * Qualify promoted constructor fields that declare an explicit visibility modifier.
     *
     * @return list<string>
     */
    private static function promotedKeys(Class_|Trait_ $node, string $declaringType): array
    {
        $keys = [];
        foreach ($node->getMethods() as $method) {
            $methodName = strtolower($method->name->toString());
            if ($methodName !== '__construct') {
                continue;
            }

            foreach ($method->params as $parameter) {
                $name = is_string($parameter->var->name) ? $parameter->var->name : null;
                if ($parameter->flags === 0 || $name === null) {
                    continue;
                }

                $isPrivate = ($parameter->flags & Class_::MODIFIER_PRIVATE) !== 0;
                $keys[] = $isPrivate ? $declaringType.'::$'.$name : '$'.$name;
            }
        }

        return $keys;
    }

    /**
     * Count public promoted fields in a final readonly constructor; return null for other methods.
     */
    public static function readonlyDataFields(ClassMethod $method): ?int
    {
        $isNonConstructor = strtolower($method->name->toString()) !== '__construct';
        if ($isNonConstructor) {
            return null;
        }

        $class = $method->getAttribute('parent');
        $isMutableClass = ! $class instanceof Class_ || ! $class->isFinal() || ! $class->isReadonly() || $method->params === [];
        if ($isMutableClass) {
            return null;
        }

        foreach ($method->params as $parameter) {
            $isNonPublic = ($parameter->flags & Class_::MODIFIER_PUBLIC) === 0;
            if ($isNonPublic) {
                return null;
            }
        }

        return count($method->params);
    }
}
