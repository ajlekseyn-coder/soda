<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Documentation;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;
use PhpParser\Comment\Doc;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

/**
 * Flags PHPDoc descriptions that add nothing beyond the member's own name.
 *
 * A description built from filler verbs ("stores", "defines", "holds") and generic
 * context nouns ("this instance", "this policy") restates the declaration. It trains
 * readers to skip docblocks, so the ones carrying real insight get skipped too.
 * Either describe the member's purpose or delete the comment.
 *
 * @example Register in soda.php:
 *
 *   new NoVacuousDocblocks(extraFiller: ['metadata', 'payload']);
 */
final class NoVacuousDocblocks extends Check
{
    /**
     * Words that never make a description informative on their own, merged with extraFiller.
     */
    private const array FILLER = [
        // Filler verbs opening generated prose.
        'stores', 'store', 'retain', 'retains', 'holds', 'hold', 'defines', 'define',
        'initialize', 'initializes', 'initialised', 'marks', 'mark', 'describes', 'describe',
        'indicates', 'indicate', 'carries', 'carry', 'captures', 'capture', 'contains', 'contain',
        // Articles, prepositions and connectives.
        'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'with', 'by', 'for', 'from',
        'that', 'this', 'these', 'those', 'it', 'its', 'is', 'are', 'was', 'were', 'be', 'been',
        'as', 'at', 'into', 'without', 'within', 'across', 'between', 'during', 'before', 'after',
        'under', 'over', 'via', 'per', 'when', 'while', 'their', 'not', 'no', 'so', 'such',
        'than', 'then', 'there', 'where', 'which', 'if', 'because', 'since', 'until', 'whether',
        // Generic context nouns and quantifiers common in generated boilerplate.
        'instance', 'instances', 'analysis', 'policy', 'configured', 'configuration', 'used',
        'usage', 'use', 'uses', 'using', 'shared', 'current', 'active', 'value', 'values',
        'field', 'fields', 'entry', 'entries', 'item', 'items', 'element', 'elements',
        'collaborator', 'collaborators', 'declaration', 'declarations', 'member', 'members',
    ];

    /**
     * Merge extra filler vocabulary with the built-in one.
     *
     * @param string[] $extraFiller Additional words treated as carrying no information.
     */
    public function __construct(
        private readonly array $extraFiller = [],
    ) {}

    /**
     * Return the stable identifier used in diagnostics.
     */
    #[\Override]
    public function id(): string
    {
        return 'no_vacuous_docblocks';
    }

    /**
     * Inspect declarations in the already parsed syntax tree.
     *
     * @return list<string>
     */
    #[\Override]
    public function requiredAnalyses(): array
    {
        return [];
    }

    /**
     * Report each documented member whose description carries no content words.
     *
     * @return iterable<Violation>
     */
    #[\Override]
    public function checkFile(FileFacts $file): iterable
    {
        foreach ((new NodeFinder)->findInstanceOf($file->nodes, ClassLike::class) as $class) {
            $className = $class->name?->toString() ?? 'anonymous@'.$class->getLine();

            foreach ($class->getProperties() as $property) {
                foreach ($this->propertyNames($property) as $name) {
                    yield from $this->violations($file, $className, $property->getDocComment(),
                        $this->nameWords($name), $property->getLine(), '$'.$name);
                }
            }

            foreach ($class->getConstants() as $constant) {
                foreach ($this->constantNames($constant) as $name) {
                    yield from $this->violations($file, $className, $constant->getDocComment(),
                        $this->nameWords($name), $constant->getLine(), $name);
                }
            }

            foreach ($class->getMethods() as $method) {
                yield from $this->violations($file, $className, $method->getDocComment(),
                    $this->nameWords($method->name->toString()), $method->getLine(),
                    $method->name->toString().'()');

                foreach ($method->params as $parameter) {
                    if (! $parameter->isPromoted()) {
                        continue;
                    }

                    yield from $this->violations($file, $className, $parameter->getDocComment(),
                        $this->nameWords($parameter->var->name), $parameter->getLine(), '$'.$parameter->var->name);
                }
            }
        }
    }

    /**
     * Report the documented declaration once its description has no content words left.
     *
     * @param list<string> $nameWords Words composing the declared identifier.
     */
    private function violations(FileFacts $file, string $class, ?Doc $comment, array $nameWords, int $line, string $member): iterable
    {
        if ($comment === null || $this->contentWords($this->prose($comment), $nameWords) !== []) {
            return;
        }

        yield new Violation(
            rule: $this->id(), file: $file->path, value: 1, threshold: 0,
            class: $class, line: $line,
            message: sprintf('PHPDoc on %s adds nothing beyond the name; describe the purpose or delete the comment.', $member),
        );
    }

    /**
     * Extract the prose before the first tag from a PHPDoc block.
     */
    private function prose(Doc $comment): string
    {
        $prose = [];
        foreach (preg_split('/\R/', trim($comment->getText())) ?: [] as $line) {
            $content = trim(ltrim(trim((string) preg_replace('~^\s*/\*+\s*~', '', $line)), '*'));
            $content = trim((string) preg_replace('~\*/\s*$~', '', $content));

            if (str_starts_with($content, '@')) {
                break;
            }

            if ($content !== '') {
                $prose[] = $content;
            }
        }

        return implode(' ', $prose);
    }

    /**
     * Return words that survive after removing the identifier and filler vocabulary.
     *
     * @param list<string> $nameWords
     *
     * @return list<string>
     */
    private function contentWords(string $prose, array $nameWords): array
    {
        $filler = array_merge(self::FILLER, $this->extraFiller);
        $ignored = array_merge($filler, $nameWords);
        preg_match_all('/[\p{L}]+/u', $prose, $matches);

        $content = [];
        foreach ($matches[0] as $word) {
            $word = mb_strtolower($word);
            if (! in_array($word, $ignored, true) && ! in_array(rtrim($word, 's'), $filler, true)) {
                $content[] = $word;
            }
        }

        return $content;
    }

    /**
     * Split an identifier into lowercase words at camelCase and snake_case boundaries.
     *
     * @return list<string>
     */
    private function nameWords(string ...$names): array
    {
        $words = [];
        foreach ($names as $name) {
            foreach (preg_split('/(?<=[a-z0-9])(?=[A-Z])|_+/', $name) ?: [] as $word) {
                $word = strtolower(trim($word, '_$'));
                if ($word !== '') {
                    $words[] = $word;
                }
            }
        }

        return $words;
    }

    /**
     * Collect the declared property names.
     *
     * @return list<string>
     */
    private function propertyNames(Property $property): array
    {
        $names = [];
        foreach ($property->props as $item) {
            $names[] = $item->name->toString();
        }

        return $names;
    }

    /**
     * Collect the declared constant names.
     *
     * @return list<string>
     */
    private function constantNames(ClassConst $constant): array
    {
        $names = [];
        foreach ($constant->consts as $item) {
            $names[] = $item->name->toString();
        }

        return $names;
    }
}
