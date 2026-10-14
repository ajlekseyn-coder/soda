<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Usage\PreferStringStartsEndsWith;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreferStringStartsEndsWithTest extends TestCase
{
    #[DataProvider('comparisons')]
    public function testPrefixAndSuffixQuestions(string $expression, int $count): void
    {
        self::assertCount($count, $this->findings('<?php $answer = '.$expression.';'));
    }

    public static function comparisons(): iterable
    {
        yield 'prefix check' => ['substr($text, 0, 3) === "sku"', 1];
        yield 'negated prefix check' => ['substr($text, 0, 3) !== "sku"', 1];
        yield 'swapped operands' => ['"sku" === substr($text, 0, 3)', 1];
        yield 'suffix check' => ['substr($text, -4) === ".php"', 1];
        yield 'suffix with explicit length' => ['substr($text, -4, 4) === ".php"', 1];
        yield 'loose prefix check' => ['substr($text, 0, 3) == "sku"', 1];
        yield 'multibyte prefix check' => ['mb_substr($text, 0, 3) === "sku"', 1];
        yield 'full string comparison' => ['substr($text, 0) === "sku"', 0];
        yield 'length does not match the literal' => ['substr($text, 0, 4) === "sku"', 0];
        yield 'suffix longer than the offset' => ['substr($text, -2, 4) === ".php"', 0];
        yield 'middle substring' => ['substr($text, 1, 3) === "sku"', 0];
        yield 'comparison with another call' => ['substr($text, 0, 3) === substr($other, 0, 3)', 0];
        yield 'comparison with a variable' => ['substr($text, 0, 3) === $prefix', 0];
        yield 'other function against a literal' => ['str_repeat("a", 3) === "aaa"', 0];
        yield 'first-class callable' => ['substr(...)', 0];
    }

    public function testNamesTheSuffixQuestionWithEvidence(): void
    {
        $findings = $this->findings('<?php $answer = mb_substr($text, -4, 4) === ".php";');
        self::assertCount(1, $findings);
        self::assertSame('prefer_string_starts_ends_with', $findings[0]->rule);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('mb_str_ends_with()', $findings[0]->message);
        self::assertStringContainsString('suffix', $findings[0]->message);
    }

    public function testStandardRunnerEnablesTheRule(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-starts-');
        self::assertIsString($path);
        file_put_contents($path, '<?php $answer = substr($text, 0, 3) === "sku";');

        try {
            $result = (new Runner)->check([$path], Soda::configure()->with(RuleCatalog::standard())->checks());
            $findings = $result->violations->filter(fn ($item): bool => $item->rule === 'prefer_string_starts_ends_with')->values()->all();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
        } finally {
            unlink($path);
        }
    }

    private function findings(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);

        return iterator_to_array((new PreferStringStartsEndsWith)->checkFile(new FileFacts('/starts-ends.php', $source, $nodes, [])));
    }
}
