<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

use PhpParser\ErrorHandler\Throwing;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpToken;

/**
 * Collects interface contracts and consumer edges without retaining ASTs.
 */
final class InterfaceFacts
{
    /**
     * Parsed declarations retain their lexical parent before facts are flattened.
     */
    private const PARENT_ATTRIBUTE = 'parent';

    /**
     * A complete type prefix ends before a description, never inside an unfinished type.
     */
    private const TYPE_PATTERN = <<<'REGEX'
    ~
        (?(DEFINE)
            (?<name> \\? [a-z_][a-z0-9_-]* (?: \\ [a-z_][a-z0-9_]* )* )
            (?<literal> \'(?:[^\'\\]|\\.)*\' | "(?:[^"\\]|\\.)*" | -?[0-9]+ )
            (?<type> \?? (?&atom) (?: \s* [|&] \s* \?? (?&atom) )* )
            (?<arguments> (?&type) (?: \s+ &? (?:\.\.\.)? \$[a-z_][a-z0-9_]* )? )
            (?<entry> (?: (?: (?&name) | (?&literal) ) \?? \s* : \s* )? (?&type) )
            (?<object>
                (?! (?:int|integer|string|bool|boolean|float|double|number|numeric|scalar|resource|mixed|array|iterable|list|null|true|false|void|never)\b )
                \\? [a-z_][a-z0-9_]* (?: \\ [a-z_][a-z0-9_]* )*
                (?: < \s* (?&type) (?: \s*,\s* (?&type) )* \s* > )?
                (?: \s* [|&] \s* (?&object) )?
            )
            (?<atom>
                (?: (?:class-string|interface-string|trait-string|enum-string) < \s* (?&object) \s* >
                    | int < \s* (?:min|-?[0-9]+) \s*,\s* (?:max|-?[0-9]+) \s* >
                    | (?! (?:class-string|interface-string|trait-string|enum-string|int)\s* < ) (?&name)
                    (?: < \s* (?&type) (?: \s*,\s* (?&type) )* \s* > )?
                    (?: \{ \s* (?: (?&entry) (?: \s*,\s* (?&entry) )* \s*,? )? \s* \} )?
                    (?: \( \s* (?: (?&arguments) (?: \s*,\s* (?&arguments) )* )? \s* \)
                        (?: \s* : \s* (?&type) )? )?
                    | (?&literal) | \( \s* (?&type) \s* \)
                ) (?: \[\] )*
            )
        )
        ^ (?<prefix> (?&type) ) (?= \s | $ ) (?! \s* (?: [|<\[({] | & (?! \s* (?:\.\.\.\s*)? \$ ) ) )
    ~ix
    REGEX;

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
            $methods = [];
            foreach ($interface->getMethods() as $method) {
                $methods[] = strtolower($method->name->toString());
            }

            $interfaces[strtolower($name)] = [
                'name'          => $name,
                'isInterface'   => true,
                'hasProperties' => $interface->getProperties() !== [],
                'parentNames'   => array_map(fn (Node\Name $parent): string => $parent->toString(), $interface->extends),
                'line'          => $interface->getStartLine(),
                'methods'       => $methods,
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
        foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool => is_object($node->getDocComment())) as $node) {
            $documented = self::documentedTypes($node, self::nameContext($node, $nodes));
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
        $parent = $reference->getAttribute(self::PARENT_ATTRIBUTE);
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

            $parent = $parent->getAttribute(self::PARENT_ATTRIBUTE);
        }

        return null;
    }

    /**
     * Preserve explicit class types in signatures, properties and delegation annotations.
     */
    private static function documentedTypes(Node $node, NameContext $context): array
    {
        $comment = $node->getDocComment();
        if (! is_object($comment)) {
            return [];
        }

        preg_match_all('/^[ \t]*@([a-z][a-z0-9_-]*)(?=[ \t\r\n]|$)[ \t]*([\s\S]*?)(?=^[ \t]*@[a-z][a-z0-9_-]*(?=[ \t\r\n]|$)|\z)/mi', self::annotationText($comment->getText()), $annotations, PREG_SET_ORDER);
        $references = [];
        foreach ($annotations as $annotation) {
            array_shift($annotation);
            $tag = array_shift($annotation);
            if (! self::isDeclarationTag($node, $tag)) {
                continue;
            }

            $type = self::typeExpression($tag, array_shift($annotation));
            $type = preg_replace('/\bint<\s*(?:min|-?[0-9]+)\s*,\s*(?:max|-?[0-9]+)\s*>/i', '', $type);
            $type = preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', '', $type);
            preg_match_all('/(?<![A-Za-z0-9_$\\\\-])\\\\?[A-Za-z_][A-Za-z0-9_-]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*(?![A-Za-z0-9_\\\\-]|\??\s*:)/', $type, $classes);
            foreach ($classes[0] as $class) {
                $resolved = self::documentedName($class, $context, self::typeBindings($node));
                if ($resolved !== null) {
                    $references[$resolved] = true;
                }
            }
        }

        return $references;
    }

    /**
     * Docblock line decoration separates comment syntax from multiline annotation payloads.
     */
    private static function annotationText(string $comment): string
    {
        $body = preg_replace('~^/\*\*|\*/$~', '', trim($comment));

        return preg_replace('/^[ \t]*\*[ \t]?/m', '', $body);
    }

    /**
     * Read the declared type or generic bound, excluding descriptions and default values.
     */
    private static function typeExpression(string $tag, string $text): string
    {
        if ($tag === 'method') {
            return self::methodTypes(trim($text));
        }

        if (str_starts_with($tag, 'template') && preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s+(?:of|as)\s+(.+)$/s', trim($text), $bound) !== 1) {
            return '';
        }

        return self::typePrefix($bound[1] ?? $text);
    }

    /**
     * A malformed delimiter or trailing operator cannot leave a shorter valid prefix.
     */
    private static function typePrefix(string $text): string
    {
        return preg_match(self::TYPE_PATTERN, trim($text), $match) === 1 ? $match['prefix'] : '';
    }

    /**
     * Method descriptions follow the closing parameter list, outside the declared signature.
     */
    private static function methodTypes(string $text): string
    {
        $declaration = preg_replace('/^static\s+/', '', $text);
        $returnType = self::typePrefix($declaration);
        $afterType = ltrim(substr($declaration, strlen($returnType)));
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*\(/', $afterType, $signature) !== 1) {
            [$afterType, $returnType] = [$declaration, ''];
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*\(/', $afterType, $signature) !== 1) {
            return '';
        }

        return self::methodParameterTypes(substr($afterType, strlen($signature[0])), $returnType);
    }

    /**
     * Parameter names end their type region; defaults can contain nested calls and quoted delimiters.
     */
    private static function methodParameterTypes(string $text, string $returnType): string
    {
        $types = $returnType;
        [$parameter, $readingType] = ['', true];
        $depth = 0;
        $nesting = [ord('(') => 1, ord('[') => 1, ord('{') => 1, ord(')') => -1, ord(']') => -1, ord('}') => -1, T_CURLY_OPEN => 1, T_DOLLAR_OPEN_CURLY_BRACES => 1];
        foreach (array_slice(PhpToken::tokenize('<?php '.$text), 1) as $token) {
            $boundary = $depth === 0 ? $token->id : null;
            if ($boundary === ord(')')) {
                return $types.' '.self::typePrefix($parameter);
            }

            if ($boundary === ord(',')) {
                $types .= ' '.self::typePrefix($parameter);
                [$parameter, $readingType] = ['', true];

                continue;
            }

            if ($depth === 0 && $token->id === T_VARIABLE) {
                $parameter = preg_replace('/(?:&\s*)?(?:\.\.\.\s*)?$/', '', $parameter);
                $readingType = false;
            }

            if ($readingType && $token->id === ord('=')) {
                return '';
            }

            $parameter .= $readingType ? $token->text : '';
            $depth += ($nesting[$token->id] ?? 0) + (int) $readingType * ([ord('<') => 1, ord('>') => -1, T_SR => -2][$token->id] ?? 0);
        }

        return '';
    }

    /**
     * PHPDoc class names share only preceding imports in their own namespace block.
     */
    private static function nameContext(Node $node, array $nodes): NameContext
    {
        $owner = $node;
        while ($owner instanceof Node && ! $owner instanceof Stmt\Namespace_) {
            $owner = $owner->getAttribute(self::PARENT_ATTRIBUTE);
        }

        $context = new NameContext(new Throwing);
        $context->startNamespace($owner?->name);
        foreach ($owner?->stmts ?? $nodes as $statement) {
            if ($statement->getStartFilePos() >= $node->getStartFilePos()) {
                break;
            }

            if ($statement instanceof Stmt\Use_ || $statement instanceof Stmt\GroupUse) {
                self::importNames($statement, $context);
            }
        }

        return $context;
    }

    /**
     * Group items retain their own import kind and the containing prefix.
     */
    private static function importNames(Stmt\Use_|Stmt\GroupUse $statement, NameContext $context): void
    {
        foreach ($statement->uses as $use) {
            $name = $statement instanceof Stmt\GroupUse ? Node\Name::concat($statement->prefix, $use->name) : $use->name;
            $context->addAlias($name, $use->getAlias()->toString(), $use->type !== Stmt\Use_::TYPE_UNKNOWN ? $use->type : $statement->type);
        }
    }

    /**
     * Lexical PHPDoc bindings take precedence over class imports with the same spelling.
     */
    private static function typeBindings(Node $node): array
    {
        $names = [];
        $owner = $node;
        do {
            if ($owner instanceof Stmt\ClassLike || $owner instanceof Node\FunctionLike) {
                $names += self::declaredBindings($owner);
            }

            if ($owner instanceof Stmt\ClassLike) {
                break;
            }

            $owner = $owner->getAttribute(self::PARENT_ATTRIBUTE);
        } while ($owner instanceof Node);

        return $names;
    }

    /**
     * Unresolved local and imported type aliases cannot become native class consumers.
     */
    private static function declaredBindings(Node $owner): array
    {
        $tags = $owner instanceof Stmt\ClassLike ? 'template(?:-covariant|-contravariant)?|(?:phpstan|psalm)-type' : 'template(?:-covariant|-contravariant)?';
        $comment = self::annotationText($owner->getDocComment()?->getText() ?? '');
        preg_match_all('/^[ \t]*@(?:'.$tags.')\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $comment, $bindings);
        $names = array_fill_keys($bindings[1], true);
        if (! $owner instanceof Stmt\ClassLike) {
            return $names;
        }

        preg_match_all('/^[ \t]*@(?:phpstan|psalm)-import-type\s+([A-Za-z_][A-Za-z0-9_]*)\s+from\s+\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?/m', $comment, $imports, PREG_SET_ORDER);
        foreach ($imports as $import) {
            $names[$import[2] ?? $import[1]] = true;
        }

        return $names;
    }

    /**
     * Resolve whole class names without interpreting template variables as imported classes.
     */
    private static function documentedName(string $class, NameContext $context, array $templates): ?string
    {
        if (isset($templates[$class]) || str_contains($class, '-')) {
            return null;
        }

        if (preg_match('/^(?:int|integer|string|bool|boolean|float|double|number|numeric|scalar|resource|mixed|array|iterable|list|null|true|false|void|never|empty|object|callable)$/i', $class) === 1) {
            return null;
        }

        if (str_starts_with($class, '\\')) {
            return strtolower(ltrim($class, '\\'));
        }

        if (str_starts_with(strtolower($class), 'namespace\\')) {
            return strtolower(ltrim(($context->getNamespace()?->toString() ?? '').'\\'.substr($class, 10), '\\'));
        }

        $name = new Node\Name($class);

        return $name->isSpecialClassName() ? null : $context->getResolvedClassName($name)->toLowerString();
    }

    /**
     * Match annotation roles to declarations rather than accepting arbitrary comment text.
     */
    private static function isDeclarationTag(Node $node, string $tag): bool
    {
        return match ($tag) {
            'var'                                                             => $node instanceof Stmt\Property,
            'param', 'return'                                                 => $node instanceof Node\FunctionLike,
            'mixin', 'method', 'property', 'property-read', 'property-write'  => $node instanceof Stmt\ClassLike,
            'template', 'template-covariant', 'template-contravariant'        => $node instanceof Stmt\ClassLike || $node instanceof Node\FunctionLike,
            'phpstan-require-implements'                                      => $node instanceof Stmt\Trait_,
            default                                                           => false,
        };
    }
}
