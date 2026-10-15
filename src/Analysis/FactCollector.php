<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Parses one PHP file and extracts quality metrics, complexity, and per-method aggregates.
 */
final readonly class FactCollector
{
    /**
     * AST link to the immediately enclosing syntax node.
     */
    private const PARENT_ATTRIBUTE = 'parent';

    /**
     * Reuse the PHP parser between files; visitors belong to a single collection.
     */
    private Parser $parser;

    /**
     * Use the standard PHP parser, or supply one for integration and instrumentation.
     */
    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * Parse once, collect the requested facts, and retain this file's AST for checks.
     *
     * @param list<string>|null $required
     *
     * @throws ParserException
     */
    public function collect(string $file, ?array $required = null): FileFacts
    {
        $source = @file_get_contents($file);
        throw_if($source === false, ParserException::class, 'Cannot read '.$file, 0);
        $nodes = $this->parse($source, $file);
        $lineMap = LogicalLineMap::fromSource($source);

        $metrics = AstFacts::collect($nodes, $lineMap, $required, $source);
        if ($required === null || in_array('classBehavior', $required, true)) {
            $metrics['staticAccessors'] = $this->staticAccessors($nodes);
        }
        $needsComments = $required === null || in_array('comments', $required, true);
        $commentIssues = $needsComments
            ? SourceCommentIssueScanner::scan($source)
            : ['todoFixme' => [], 'commentedCode' => []];
        $metrics['todoFixme'] = $commentIssues['todoFixme'];
        $metrics['commentedCode'] = $commentIssues['commentedCode'];

        return new FileFacts($file, $source, $nodes, $metrics);
    }

    /**
     * Keep named accessor calls as scalars for resolution after all declarations are known.
     */
    private function staticAccessors(array $nodes): array
    {
        $calls = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, StaticCall::class) as $call) {
            if (! $call->class instanceof Name || ! $call->name instanceof Identifier || $call->isFirstClassCallable()) {
                continue;
            }

            $method = $call->name->toLowerString();
            if (in_array($method, ['instance', 'getinstance'], true)) {
                $calls[] = ['class' => $this->accessorClass($call), 'method' => $method, 'line' => $call->getStartLine()];
            }
        }

        return $calls;
    }

    /**
     * Late-bound receivers can select overrides outside the lexical declaration.
     */
    private function accessorClass(StaticCall $call): ?string
    {
        $name = $call->class instanceof Name ? $call->class->toLowerString() : null;
        if (! in_array($name, ['self', 'static', 'parent'], true)) {
            return $name;
        }

        $owner = $call->getAttribute(self::PARENT_ATTRIBUTE);
        while ($owner instanceof Node && ! $owner instanceof Class_) {
            $owner = $owner->getAttribute(self::PARENT_ATTRIBUTE);
        }

        if (! $owner instanceof Class_ || ($name === 'static' && ! $owner->isFinal())) {
            return null;
        }

        return $name === 'parent' ? $owner->extends?->toLowerString() : $owner->namespacedName?->toLowerString();
    }

    /**
     * Parse PHP while preserving the source path and original parser error.
     *
     * @return list<Node>
     */
    private function parse(string $source, string $file): array
    {
        try {
            $nodes = $this->parser->parse($source);
        } catch (Error $error) {
            throw new ParserException(
                sprintf('Cannot parse %s: %s', $file, $error->getMessage()),
                $error->getCode(),
                $error,
            );
        }

        throw_if($nodes === null, ParserException::class, 'Cannot parse '.$file, 0);

        return $nodes;
    }
}
