<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Naming;

use function collect;

use Illuminate\Support\Str;

use function similar_text;

/**
 * Проверка избыточности в именах методов (add/has/getAll и т.п.).
 *
 * @internal
 */
final readonly class MethodRedundancyChecker
{
    /**
     * Defines method prefix add used by this policy.
     */
    private const array METHOD_PREFIX_ADD = ['add', 'has', 'remove', 'append', 'prepend', 'push', 'attach'];

    /**
     * Defines method prefix get used by this policy.
     */
    private const array METHOD_PREFIX_GET = ['get', 'find', 'list', 'all', 'fetch', 'load'];

    /**
     * Defines collection marker word used by this policy.
     */
    private const string COLLECTION_MARKER = 'all';

    /**
     * Defines finding type method used by this policy.
     */
    private const string FINDING_TYPE_METHOD = 'method';

    /**
     * Initialize the configured values and collaborators for this instance.
     *
     * @param float $similarityThreshold Minimum percentage similarity required to flag repeated naming.
     * @param int   $minWordLength       Minimum token length considered when comparing names.
     */
    public function __construct(
        private float $similarityThreshold = 80.0,
        private int $minWordLength = 4,
    ) {}

    /**
     * Find method-name words that repeat the parameter, return type, or class context.
     *
     * @return array{type: 'method', current: string, suggested: string, reason: string, similarity: float, line: int, className?: string}|null
     */
    public function check(array $methodData): ?array
    {
        $isOverride = ($methodData['hasOverrideAttribute'] ?? false) === true;
        if ($isOverride) {
            return null;
        }

        $paramShortName = $this->normalizeType($methodData['firstParamType'] ?? null);
        $returnShortName = $this->normalizeType($methodData['returnType'] ?? null);
        $words = $this->splitCamelCase($methodData['methodName']);
        $isInterfaceType = $paramShortName !== null && str_ends_with($paramShortName, 'Interface');

        return ($isInterfaceType)
            ? null
            : ($this->checkAddLike($words, $paramShortName, $methodData)
                ?? $this->checkFromClassContext($words, $methodData)
                ?? $this->checkGetAllLike($words, $returnShortName, $methodData));
    }

    /**
     * Measure redundant entity naming in an add-style method against its parameter type.
     *
     * @param list<string> $words
     */
    private function addLikeSimilarity(array $words, ?string $paramShortName): ?float
    {
        if ($paramShortName === null) {
            return null;
        }

        $nameAfterPrefix = implode('', array_slice($words, 1));
        $similarity = $this->similarity($nameAfterPrefix, $paramShortName);
        $isRedundant = $similarity >= $this->similarityThreshold && strlen($paramShortName) >= $this->minWordLength;

        if ($isRedundant) {
            return $similarity;
        }

        $redundant = $this->findRedundant($words, $paramShortName, 1);

        return $redundant !== null ? $redundant['similarity'] : null;
    }

    /**
     * Extract the domain name used when checking redundant method prefixes.
     */
    private function entityFromClass(string $classShort): ?string
    {
        $words = $this->splitCamelCase($classShort);
        $suffix = collect(array_filter(
            self::CLASS_ENTITY_SUFFIXES,
            fn (string $s) => str_ends_with($classShort, $s),
        ))->first();

        if ($suffix === null) {
            return null;
        }

        array_pop($words);
        $entity = implode('', array_filter(
            $words,
            static fn (string $word): bool => ! in_array($word, self::CLASS_CONTEXT_FILLERS, true),
        ));

        return $entity !== '' ? $entity : null;
    }

    /**
     * Remove the namespace prefix from a qualified class name.
     */
    private function shortClassName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }

    /**
     * Check whether an identifier repeats context already supplied by its declaring class.
     */
    private function doesRepeatClassContext(string $firstWord, ?string $entity, string $nameAfterPrefix): bool
    {
        return $firstWord !== ''
            && $entity !== null
            && strlen($entity) >= $this->minWordLength
            && $nameAfterPrefix !== ''
            && $this->similarity($nameAfterPrefix, $entity) >= $this->similarityThreshold;
    }

    /**
     * Defines class entity suffixes used by this policy.
     */
    private const array CLASS_ENTITY_SUFFIXES = [
        'Collection', 'Service', 'Repository', 'Manager', 'Controller', 'Handler',
        'Provider', 'Factory', 'Gateway', 'Store',
    ];

    /**
     * Defines class context fillers used by this policy.
     */
    private const array CLASS_CONTEXT_FILLERS = ['Item', 'Items', 'Entity', 'Entities', 'Model', 'Models'];

    /**
     * Measure overlap between a collection method name and its return type.
     */
    private function getAllSimilarity(string $nameAfterPrefix, ?string $returnShortName): ?float
    {
        $isShortSuffix = $nameAfterPrefix === '' || strlen($nameAfterPrefix) < $this->minWordLength;
        if ($isShortSuffix) {
            return null;
        }

        if ($this->isGenericReturn($returnShortName)) {
            return 100.0;
        }

        $similarity = $this->similarity($nameAfterPrefix, $returnShortName);

        return $similarity < $this->similarityThreshold ? null : $similarity;
    }

    /**
     * Determine whether add like applies to the supplied input.
     */
    private function isAddLike(string $word): bool
    {
        return in_array(strtolower($word), self::METHOD_PREFIX_ADD, true);
    }

    /**
     * Determine whether get all like applies to the supplied input.
     */
    private function isGetAllLike(string $firstWord, string $fullName): bool
    {
        $lower = strtolower($firstWord);
        $fullLower = strtolower($fullName);

        return in_array($lower, self::METHOD_PREFIX_GET, true)
            && (str_contains($fullLower, self::COLLECTION_MARKER) || str_contains($fullLower, 'list') || $lower === self::COLLECTION_MARKER);
    }

    /**
     * Determine whether generic return applies to the supplied input.
     */
    private function isGenericReturn(?string $type): bool
    {
        return in_array($type, [null, '', 'array'], true);
    }

    /**
     * Return an unqualified type name, or null when no type was supplied.
     */
    private function normalizeType(?string $type): ?string
    {
        $hasType = $type !== null && $type !== '';
        $pos = $hasType ? strrpos($type, '\\') : false;
        $hasNoType = $type === null || $type === '';

        if ($hasNoType) {
            return null;
        }

        return $pos !== false ? substr($type, $pos + 1) : $type;
    }

    /**
     * Split a camel-case identifier into nonempty word segments.
     *
     * @return list<string>
     */
    private function splitCamelCase(string $name): array
    {
        return array_values(array_filter(Str::ucsplit($name), fn (string $p) => $p !== ''));
    }

    /**
     * Suggest removing entity words already conveyed by an add-style method parameter.
     *
     * @param list<string> $words
     */
    private function checkAddLike(array $words, ?string $paramShortName, array $methodData): ?array
    {
        $firstWord = collect($words)->first() ?? '';
        $finalSimilarity = $this->addLikeSimilarity($words, $paramShortName);
        $cannotCompare = ! $this->isAddLike($firstWord) || $paramShortName === null || $finalSimilarity === null;

        return ($cannotCompare)
            ? null
            : [
                'type'       => self::FINDING_TYPE_METHOD,
                'current'    => $methodData['methodName'].'('.$paramShortName.' $...)',
                'suggested'  => $firstWord.'('.$paramShortName.' $...)',
                'reason'     => '"'.$paramShortName.'" already conveyed by parameter type',
                'similarity' => $finalSimilarity,
                'line'       => $methodData['line'],
                'className'  => $methodData['class'],
            ];
    }

    /**
     * Suggest removing entity words already conveyed by the owning class.
     *
     * @param list<string> $words
     */
    private function checkFromClassContext(array $words, array $methodData): ?array
    {
        $firstWord = collect($words)->first() ?? '';
        $className = $methodData['class'] ?? null;
        $nameAfterPrefix = implode('', array_slice($words, 1));
        $entity = $className !== null ? $this->entityFromClass($this->shortClassName($className)) : null;

        return $this->doesRepeatClassContext($firstWord, $entity, $nameAfterPrefix)
            ? [
                'type'       => self::FINDING_TYPE_METHOD,
                'current'    => $methodData['methodName'].'()',
                'suggested'  => $firstWord.'()',
                'reason'     => '"'.$entity.'" redundant (class context)',
                'similarity' => $this->similarity($nameAfterPrefix, $entity),
                'line'       => $methodData['line'],
                'className'  => $className,
            ]
            : null;
    }

    /**
     * Suggest removing collection entity words already conveyed by the return type.
     *
     * @param list<string> $words
     */
    private function checkGetAllLike(array $words, ?string $returnShortName, array $methodData): ?array
    {
        $methodName = $methodData['methodName'];
        $firstWord = collect($words)->first() ?? '';
        $nameAfterPrefix = implode('', array_slice($words, 1));
        $similarity = $this->getAllSimilarity($nameAfterPrefix, $returnShortName);
        $cannotCheckCollection = ! $this->isGetAllLike($firstWord, $methodName) || $similarity === null;

        if ($cannotCheckCollection) {
            return null;
        }

        $hasAll = in_array('All', $words, true) || in_array(self::COLLECTION_MARKER, $words, true);
        $suggested = $hasAll ? self::COLLECTION_MARKER : $firstWord;
        $returnDisplay = $this->isGenericReturn($returnShortName) ? 'array' : $returnShortName.'[]';
        $hasReturnType = ! $this->isGenericReturn($returnShortName);

        return [
            'type'       => self::FINDING_TYPE_METHOD,
            'current'    => $methodName.'(): '.$returnDisplay,
            'suggested'  => $suggested.'(): '.$returnDisplay,
            'reason'     => $hasReturnType
                ? '"'.$returnShortName.'" already conveyed by return type'
                : 'Entity name redundant in getter',
            'similarity' => $similarity,
            'line'       => $methodData['line'],
            'className'  => $methodData['class'],
        ];
    }

    /**
     * Determine whether word similar applies to the supplied input.
     */
    private function isWordSimilar(string $a, string $b): bool
    {
        return $a === $b
            || (strlen($a) >= $this->minWordLength && strlen($b) >= $this->minWordLength && $this->similarity($a, $b) >= $this->similarityThreshold);
    }

    /**
     * Measure normalized identifier overlap for the naming policy.
     */
    private function similarity(string $a, string $b): float
    {
        similar_text($a, $b, $percent);

        return $percent;
    }

    /**
     * Find the first identifier word matching the referenced type above the similarity threshold.
     *
     * @param list<string> $words
     *
     * @return array{word: string, similarity: float}|null
     */
    private function findRedundant(array $words, string $typeShortName, int $startIndex): ?array
    {
        $typeFirst = collect($this->splitCamelCase($typeShortName))->first() ?? '';
        $isShortType = strlen($typeFirst) < $this->minWordLength;

        if ($isShortType) {
            return null;
        }

        $counter = count($words);
        for ($i = $startIndex; $i < $counter; $i++) {
            $w = $words[$i];
            $isSimilarWord = strlen($w) >= $this->minWordLength && ($this->isWordSimilar($w, $typeFirst) || $this->isWordSimilar($w, $typeShortName));
            if ($isSimilarWord) {
                return ['word' => $w, 'similarity' => $this->similarity($w, $typeFirst)];
            }
        }

        return null;
    }
}
