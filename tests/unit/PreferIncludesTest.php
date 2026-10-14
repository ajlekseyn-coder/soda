<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Usage\PreferIncludes;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreferIncludesTest extends TestCase
{
    #[DataProvider('comparisons')]
    public function testPositionQuestions(string $expression, int $count): void
    {
        self::assertCount($count, $this->findings('<?php $answer = '.$expression.';'));
    }

    public static function comparisons(): iterable
    {
        yield 'found check' => ['strpos($text, "seed") !== false', 1];
        yield 'not found check' => ['strpos($text, "seed") === false', 1];
        yield 'swapped operands' => ['false !== strpos($text, "seed")', 1];
        yield 'loose comparison' => ['strpos($text, "seed") == false', 1];
        yield 'multibyte haystack' => ['mb_strpos($text, "seed") !== false', 1];
        yield 'negated statement' => ['!strpos($text, "seed")', 0];
        yield 'position arithmetic' => ['strpos($text, "seed") + 3', 0];
        yield 'comparison with another call' => ['strpos($text, "seed") !== strpos($other, "seed")', 0];
        yield 'comparison with zero' => ['strpos($text, "seed") !== 0', 0];
        yield 'other function against false' => ['array_search($seed, $list) !== false', 0];
        yield 'first-class callable' => ['strpos(...)', 0];
    }

    public function testNamesBothFunctionsWithEvidence(): void
    {
        $findings = $this->findings('<?php $found = mb_strpos($text, "seed") !== false;');
        self::assertCount(1, $findings);
        self::assertSame('prefer_includes', $findings[0]->rule);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('mb_str_contains()', $findings[0]->message);
        self::assertStringContainsString('mb_strpos()', $findings[0]->message);
    }

    public function testStandardRunnerEnablesTheRule(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-includes-');
        self::assertIsString($path);
        file_put_contents($path, '<?php $found = strpos($text, "seed") !== false;');

        try {
            $result = (new Runner)->check([$path], Soda::configure()->with(RuleCatalog::standard())->checks());
            $findings = $result->violations->filter(fn ($item): bool => $item->rule === 'prefer_includes')->values()->all();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
        } finally {
            unlink($path);
        }
    }

    private function findings(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);

        return iterator_to_array((new PreferIncludes)->checkFile(new FileFacts('/includes.php', $source, $nodes, [])));
    }
}
