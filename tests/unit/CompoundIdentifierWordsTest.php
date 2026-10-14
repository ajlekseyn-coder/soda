<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Tests\CheckFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompoundIdentifierWordsTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('identifierProvider')]
    public function testSplitsSemanticWordsWithoutExplodingAcronyms(string $name, array $expected): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-compound-name-');
        self::assertIsString($path);
        // The suffix makes even a single-word identifier observable in the naming facts.
        file_put_contents($path, '<?php function run() { $'.$name.'_suffix = 1; }');

        try {
            $occurrences = CheckFixture::collect($path, required: ['naming'])['metrics']['compoundVariableNames'];
            self::assertCount(1, $occurrences);
            self::assertSame([...$expected, 'suffix'], $occurrences[0]->words);
        } finally {
            unlink($path);
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function identifierProvider(): iterable
    {
        yield 'camel case' => ['fileName', ['file', 'name']];
        yield 'unit qualifier' => ['temperatureInFahrenheit', ['temperature', 'in', 'fahrenheit']];
        yield 'snake case' => ['source_file_name', ['source', 'file', 'name']];
        yield 'acronym' => ['HTTP', ['http']];
        yield 'acronym prefix' => ['HTTPResponse', ['http', 'response']];
        yield 'proper brand' => ['openAI', ['open', 'ai']];
        yield 'mixed brand' => ['iOS', ['i', 'os']];
        yield 'digits belong to word' => ['sha256', ['sha256']];
        yield 'proper name' => ['TaylorOtwell', ['taylor', 'otwell']];
    }
}
