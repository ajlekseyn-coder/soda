<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Usage;

use PhpParser\ErrorHandler\Throwing;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Reads construction forwarding and return bounds from declared factory signatures.
 *
 * @internal
 */
trait FactorySignatures
{
    /**
     * PHPDoc names share the namespace and import table of their declaration.
     */
    private static function nameContext(Stmt\ClassLike $class, array $nodes): NameContext
    {
        $owner = $class->getAttribute(self::PARENT_ATTRIBUTE);
        while ($owner instanceof Node && ! $owner instanceof Stmt\Namespace_) {
            $owner = $owner->getAttribute(self::PARENT_ATTRIBUTE);
        }

        $context = new NameContext(new Throwing);
        $context->startNamespace($owner?->name);
        foreach ($owner?->stmts ?? $nodes as $statement) {
            if ($statement->getStartFilePos() >= $class->getStartFilePos()) {
                break;
            }

            if (! $statement instanceof Stmt\Use_ && ! $statement instanceof Stmt\GroupUse) {
                continue;
            }

            self::importNames($statement, $context);
        }

        return $context;
    }

    /**
     * Group imports contribute their prefix while each item retains its own alias kind.
     */
    private static function importNames(Stmt\Use_|Stmt\GroupUse $statement, NameContext $context): void
    {
        foreach ($statement->uses as $use) {
            $name = $statement instanceof Stmt\GroupUse ? Name::concat($statement->prefix, $use->name) : $use->name;
            $context->addAlias($name, $use->getAlias()->toString(), $use->type !== Stmt\Use_::TYPE_UNKNOWN ? $use->type : $statement->type);
        }
    }

    /**
     * A native class declaration bounds an object input without relying on parameter names.
     */
    private static function nativeClass(?Node $type): ?string
    {
        return $type instanceof Name && ! $type->isSpecialClassName() ? $type->toLowerString() : null;
    }

    /**
     * Only a single declared class type bounds the factory's receiver family.
     */
    private static function returnedClass(Stmt\ClassMethod $method, Stmt\ClassLike $class, array $nodes): ?string
    {
        if ($method->returnType instanceof Name) {
            return $method->returnType->isSpecialClassName() ? null : $method->returnType->toLowerString();
        }

        if ($method->returnType !== null || preg_match('/(?:^|\\R)\\s*(?:\\/\\*\\*|\\*)?\\s*@return\\s+(\\\\?[a-z_][a-z0-9_\\\\]*)(?=\\s|\\*\\/)/i', $method->getDocComment()?->getText() ?? '', $match) !== 1) {
            return null;
        }

        $context = self::nameContext($class, $nodes);
        $name = str_starts_with($match[1], '\\') ? new Name\FullyQualified(substr($match[1], 1)) : new Name($match[1]);

        return $name->isSpecialClassName() ? null : $context->getResolvedClassName($name)->toLowerString();
    }

    /**
     * Require the whole explicit body to be a single parent constructor invocation.
     */
    private static function doesForwardConstructor(Stmt\ClassMethod $method): bool
    {
        if ($method->name->toLowerString() !== '__construct') {
            return false;
        }

        $call = self::parentCall($method);
        if (! $call instanceof Expr\StaticCall) {
            return false;
        }

        $parameters = array_map(static fn ($parameter): ?string => ! $parameter->variadic && ! $parameter->byRef && $parameter->var instanceof Expr\Variable
            && is_string($parameter->var->name) ? $parameter->var->name : null, $method->params);
        $arguments = array_map(self::argument(...), $call->getArgs());

        return ! in_array(null, $parameters, true) && $parameters === $arguments;
    }

    /**
     * Read the sole body statement and require an actual parent constructor call.
     */
    private static function parentCall(Stmt\ClassMethod $method): ?Expr\StaticCall
    {
        $statements = $method->stmts ?? [];
        $statement = count($statements) === 1 ? reset($statements) : null;
        $call = $statement instanceof Stmt\Expression ? $statement->expr : null;
        $isParent = $call instanceof Expr\StaticCall && $call->class instanceof Name
            && $call->class->toLowerString() === 'parent';
        if (! $isParent || ! $call->name instanceof Node\Identifier || $call->name->toLowerString() !== '__construct' || $call->isFirstClassCallable()) {
            return null;
        }

        return $call;
    }

    /**
     * An abstract method alias adds a separate requirement within the exact importing use group.
     */
    private static function traitAliases(Stmt\ClassLike $class): array
    {
        $aliases = [];
        foreach ($class->getTraitUses() as $use) {
            foreach ($use->adaptations as $adaptation) {
                if (! $adaptation instanceof Stmt\TraitUseAdaptation\Alias) {
                    continue;
                }
                $name = ($adaptation->newName ?? $adaptation->method)->toLowerString();
                $sources = $adaptation->trait === null ? $use->traits : [$adaptation->trait];
                $aliases[$name] = [...($aliases[$name] ?? []), [
                    'method'   => $adaptation->method->toLowerString(),
                    'renamed'  => $name !== $adaptation->method->toLowerString(),
                    'modifier' => $adaptation->newModifier,
                    'traits'   => array_map(static fn (Name $trait): string => $trait->toLowerString(), $sources),
                ]];
            }
        }

        return $aliases;
    }
}
