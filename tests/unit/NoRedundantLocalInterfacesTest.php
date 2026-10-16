<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Architecture\NoRedundantLocalInterfaces;
use Cosmira\Soda\Tests\ParsesPhpSnippets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoRedundantLocalInterfacesTest extends TestCase
{
    use ParsesPhpSnippets;

    #[DataProvider('pipelineRegressions')]
    public function testPipelinePreservesValidCodeAndDetectsControl(string $valid, string $invalid): void
    {
        $this->assertRulePipeline('no_redundant_local_interfaces', $valid, $invalid);
    }

    public static function pipelineRegressions(): iterable
    {
        yield 'Serializable Closure: PHPDoc property is a type consumer' => [
            'namespace App; interface Signer { function sign(); } class Signed { /** @var \\App\\Signer|null */ private $signer; }',
            'namespace App; interface Signer { function sign(); } /** Documents \\App\\Signer. */ class Signed {}',
        ];
    }

    #[DataProvider('mixinConsumers')]
    public function testMixinDeclarationConsumesTheQualifiedInterface(string $consumer, int $expected): void
    {
        $this->assertDocumentedConsumer($consumer, $expected, '@mixin \\Contracts\\Selfish');
    }

    #[DataProvider('methodConsumers')]
    public function testMethodDeclarationConsumesOnlySignatureTypes(string $consumer, int $expected): void
    {
        $this->assertDocumentedConsumer($consumer, $expected, '@method static \\Contracts\\Selfish make()');
    }

    #[DataProvider('importedConsumers')]
    public function testDocumentedTypesResolveOnlyTheirDeclarationScope(string $consumer, int $expected): void
    {
        $this->assertDocumentedConsumer($consumer, $expected, '@return \\Contracts\\Selfish');
    }

    #[DataProvider('multilineConsumers')]
    public function testMultilineTagsPreserveTheirCompleteType(string $consumer, int $expected): void
    {
        $this->assertDocumentedConsumer($consumer, $expected, '@mixin \\Contracts\\Selfish');
    }

    public static function multilineConsumers(): iterable
    {
        yield 'existing fully qualified multiline property' => ['class Registry { /**
         * @var array<\\Contracts\\Subject,
         * int>
         */ public array $items; }', 0];
        yield 'imported multiline property' => ['namespace App; use Contracts\\Subject as Entry; class Registry { /**
         * @var array<int,
         * class-string<Entry>>
         */ public array $items; }', 0];
        yield 'multiline parameter' => ['namespace App; use Contracts\\Subject as Entry; /**
         * @param array<int,
         * Entry> $items
         */ function consume($items) {}', 0];
        yield 'multiline return' => ['namespace App; use Contracts\\Subject as Entry; /**
         * @return array<int,
         * Entry>
         */ function consume() {}', 0];
        yield 'multiline method signature' => ['namespace App; use Contracts\\Subject as Entry; /**
         * @method static array<int,
         * Entry> consume(
         *     list<Entry> $items
         * )
         */ class Registry {}', 0];
        yield 'multiline template bound' => ['namespace App; use Contracts\\Subject as Entry; /**
         * @template T of array<int,
         * Entry>
         */ class Registry {}', 0];
        yield 'prose continuation is not a type' => ['namespace App; use Contracts\\Subject as Entry; /**
         * @return string
         * Entry describes another class.
         */ function consume() {}', 1];
        yield 'next tag cannot close an unfinished type' => ['namespace App; use Contracts\\Subject as Entry; /**
         * @return array<int,
         * @see Entry>
         */ function consume() {}', 1];
        yield 'next tag does not swallow a later type' => ['namespace App; use Contracts\\Subject as Entry; /**
         * @param string $name
         * @return array<int,
         * Entry>
         */ function consume($name) {}', 0];
    }

    public static function importedConsumers(): iterable
    {
        yield 'LSP registry property' => ['namespace App; use Contracts\\Subject; class Registry { /** @var array<int, class-string<Subject>> */ public array $entries = []; }', 0];
        yield 'aliased return' => ['namespace App; use Contracts\\Subject as Entry; class Registry { /** @return list<Entry> */ function entries() {} }', 0];
        yield 'grouped class import' => ['namespace App; use Contracts\\{Subject as Entry}; /** @mixin Entry */ class Registry {}', 0];
        yield 'mixed grouped import kinds' => ['namespace App; use Contracts\\{Subject as Entry, function noop, const FLAG}; /** @method static array<int, Entry> entries() */ class Registry {}', 0];
        yield 'parameter whitespace generic' => ['namespace App; use Contracts\\Subject as Entry; /** @param array<string, list<Entry>> $entries */ function consume($entries) {}', 0];
        yield 'template bound' => ['namespace App; use Contracts\\Subject as Entry; /** @template T of array<string, Entry> */ class Registry {}', 0];
        yield 'namespace relative type' => ['namespace Contracts; /** @return Subject */ function consume() {}', 0];
        yield 'explicit namespace relative type' => ['namespace Contracts; /** @return namespace\\Subject */ function consume() {}', 0];
        yield 'imported namespace prefix' => ['namespace App; use Contracts as Types; /** @return Types\\Subject */ function consume() {}', 0];
        yield 'imports do not consume a type alone' => ['namespace App; use Contracts\\Subject as Entry; class Registry {}', 1];
        yield 'aliases do not match substrings' => ['namespace App; use Contracts\\Subject as Entry; /** @return OtherEntry */ function consume() {}', 1];
        yield 'prose after type' => ['namespace App; use Contracts\\Subject as Entry; /** @return string Entry description */ function consume() {}', 1];
        yield 'array shape key is not a type' => ['namespace App; use Contracts\\Subject as Entry; /** @return array{Entry: string} */ function consume() {}', 1];
        yield 'literal generic argument is not a type' => ['namespace App; use Contracts\\Subject as Entry; /** @return array<string, "Entry"> */ function consume() {}', 1];
        yield 'unbalanced generic is not a type' => ['namespace App; use Contracts\\Subject as Entry; /** @return array<int, Entry */ function consume() {}', 1];
        yield 'dangling union is not a type' => ['namespace App; use Contracts\\Subject as Entry; /** @return Entry| */ function consume() {}', 1];
        yield 'default expression is not a type' => ['namespace App; use Contracts\\Subject as Entry; /** @method static void consume(string $entry = Entry::class) */ class Registry {}', 1];
        yield 'function import is not a class alias' => ['namespace App; use function Contracts\\Subject as Entry; /** @return Entry */ function consume() {}', 1];
        yield 'constant import is not a class alias' => ['namespace App; use const Contracts\\Subject as Entry; /** @return Entry */ function consume() {}', 1];
        yield 'another namespace cannot donate imports' => ['namespace A { use Contracts\\Subject as Entry; } namespace B { /** @return Entry */ function consume() {} }', 1];
        yield 'later import is not earlier declaration context' => ['namespace App; /** @return Entry */ function consume() {} use Contracts\\Subject as Entry;', 1];
        yield 'template name shadows class alias' => ['namespace App; use Contracts\\Subject as Entry; /** @template Entry */ class Registry { /** @return Entry */ function entry() {} }', 1];
        yield 'function template does not shadow sibling' => ['namespace App; use Contracts\\Subject as Entry; /** @template Entry */ function generic() {} /** @return Entry */ function concrete() {}', 0];
        yield 'template prose does not shadow an alias' => ['namespace App; use Contracts\\Subject as Entry; /** Mentions @template Entry for readers. */ class Registry { /** @return Entry */ function entry() {} }', 0];
        yield 'case insensitive class alias' => ['namespace App; use Contracts\\Subject as ENTRY; /** @return entry */ function consume() {}', 0];
        yield 'optional array shape key is not a type' => ['namespace App; use Contracts\\Subject as Entry; /** @return array{Entry?: string} */ function consume() {}', 1];
        yield 'array shape value is a type' => ['namespace App; use Contracts\\Subject as Entry; /** @return array{value: Entry} */ function consume() {}', 0];
        yield 'dangling spaced operator is not a type' => ['namespace App; use Contracts\\Subject as Entry; /** @return Entry | */ function consume() {}', 1];
        yield 'nested generic method parameters' => ['namespace App; use Contracts\\Subject as Entry; /** @method static void consume(array<int, list<Entry>> $entries) */ class Registry {}', 0];
        yield 'class string bound is a type' => ['namespace App; use Contracts\\Subject as Entry; /** @return class-string<Entry> */ function consume() {}', 0];
        yield 'class string has one bound' => ['namespace App; use Contracts\\Subject as Entry; /** @return class-string<int, Entry> */ function consume() {}', 1];
        yield 'class string does not accept array values' => ['namespace App; use Contracts\\Subject as Entry; /** @return class-string<array<Entry>> */ function consume() {}', 1];
        yield 'generic prose is not a type argument' => ['namespace App; use Contracts\\Subject as Entry; /** @return array<int, Entry description> */ function consume() {}', 1];
        yield 'misplaced property template cannot shadow an alias' => ['namespace App; use Contracts\\Subject as Entry; class Registry {
            /**
             * @template Entry
             * @var Entry
             */
            public $entry;
        }', 0];
        yield 'local type alias does not resolve as class import' => ['namespace App; use Contracts\\Subject as Entry; /** @phpstan-type Entry string */ class Registry { /** @return Entry */ function entry() {} }', 1];
        yield 'imported type alias remains a different binding' => ['namespace App; use Contracts\\Subject as Entry; /** @phpstan-import-type Text from External as Entry */ class Registry { /** @return Entry */ function entry() {} }', 1];
        yield 'method reference parameter preserves its type' => ['namespace App; use Contracts\\Subject as Entry; /** @method static void consume(Entry &$entry) */ class Registry {}', 0];
        yield 'method variadic reference preserves its type' => ['namespace App; use Contracts\\Subject as Entry; /** @method static void consume(Entry &...$entries) */ class Registry {}', 0];
        yield 'parameter tag reference preserves its type' => ['namespace App; use Contracts\\Subject as Entry; /** @param Entry &$entry */ function consume(&$entry) {}', 0];
        yield 'variadic parameter name is not a type' => ['namespace App; use Contracts\\Subject as Entry; /** @method static void consume(...$Entry) */ class Registry {}', 1];
    }

    private function assertDocumentedConsumer(string $consumer, int $expected, string $selfReference): void
    {
        $root = sys_get_temp_dir().'/soda-mixin-contract-'.bin2hex(random_bytes(6));
        mkdir($root);
        $declarations = $root.'/Contracts.php';
        $delegation = $root.'/Delegation.php';
        file_put_contents($declarations, '<?php namespace Contracts;
            interface Subject { function run(); }
            interface Unused { function run(); }
            /** '.$selfReference.' */ interface Selfish { function run(); }');
        file_put_contents($delegation, '<?php '.$consumer);

        try {
            foreach ([[new NoRedundantLocalInterfaces], RuleCatalog::standard()] as $checks) {
                foreach ([[$declarations, $delegation], [$delegation, $declarations]] as $paths) {
                    $result = (new Runner)->check($paths, $checks);
                    $parse = $result->violations->filter(static fn ($row): bool => $row->rule === 'parse_error');
                    self::assertCount(0, $parse);
                    $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'no_redundant_local_interfaces')->values();
                    foreach (['Contracts\\Unused' => 3, 'Contracts\\Selfish' => 4] as $class => $line) {
                        $control = $findings->filter(static fn ($row): bool => $row->class === $class)->values();
                        self::assertCount(1, $control);
                        self::assertSame(['rule' => 'no_redundant_local_interfaces', 'file' => $declarations,
                            'method'             => null, 'class' => $class, 'line' => $line, 'value' => 1, 'threshold' => 0,
                            'message'            => $class.' has no explicit type consumer in the analyzed project. Declare external consumers in contracts; remove it only if it has no consumers.'], $control[0]->toArray());
                    }
                    self::assertCount($expected, $findings->filter(static fn ($row): bool => $row->class === 'Contracts\\Subject'));
                    self::assertCount($expected + 2, $findings);
                }
            }
        } finally {
            unlink($declarations);
            unlink($delegation);
            rmdir($root);
        }
    }

    public static function mixinConsumers(): iterable
    {
        yield 'class delegates its documented protocol' => ['/** @mixin \\Contracts\\Subject */ class Delegation {}', 0];
        yield 'trait declares its documented protocol' => ['/** @mixin \\Contracts\\Subject */ trait Delegation {}', 0];
        yield 'prose does not declare a mixin' => ['/** Describes \\Contracts\\Subject for readers. */ class Delegation {}', 1];
        yield 'relative qualified type cannot consume a different root type' => ['/** @mixin Foreign\\Contracts\\Subject */ class Delegation {}', 1];
        yield 'quoted class name is not a mixin object type' => ['/** @mixin \'\\Contracts\\Subject\' */ class Delegation {}', 1];
        yield 'function is not a mixin declaration owner' => ['/** @mixin \\Contracts\\Subject */ function delegate() {}', 1];
    }

    public static function methodConsumers(): iterable
    {
        yield 'Pennant facade declares a static return contract' => ['/** @method static \\Contracts\\Subject resolve() */ class Delegation {}', 0];
        yield 'instance return contract' => ['/** @method \\Contracts\\Subject resolve() */ class Delegation {}', 0];
        yield 'trait return contract' => ['/** @method static \\Contracts\\Subject resolve() */ trait Delegation {}', 0];
        yield 'parameter contract' => ['/** @method static void replace(\\Contracts\\Subject $subject) */ class Delegation {}', 0];
        yield 'nested callback parameter contract' => ['/** @method static void replace(callable(\\Contracts\\Subject): void $callback) */ class Delegation {}', 0];
        yield 'second parameter after a default' => ['/** @method static void replace(string $text = "literal", ?\\Contracts\\Subject $subject = null) */ class Delegation {}', 0];
        yield 'return type does not consume a default expression' => ['/** @method static \\Contracts\\Subject resolve(string $type = \\Contracts\\Unused::class) */ class Delegation {}', 0];
        yield 'return type does not consume its description' => ['/** @method static \\Contracts\\Subject resolve() Describes \\Contracts\\Unused here. */ class Delegation {}', 0];
        yield 'return description is not a signature type' => ['/** @method static void resolve() Describes \\Contracts\\Subject here. */ class Delegation {}', 1];
        yield 'quoted default is not a parameter type' => ['/** @method static void resolve(string $type = "\\Contracts\\Subject") */ class Delegation {}', 1];
        yield 'computed default is not a parameter type' => ['/** @method static void resolve(string $type = \\Contracts\\Subject::class) */ class Delegation {}', 1];
        yield 'interpolated default punctuation is not a parameter boundary' => ['/** @method static void resolve(string $text = ",{$provider->value(\\Contracts\\Subject::class)}") */ class Delegation {}', 1];
        yield 'parameter after interpolation remains a type' => ['/** @method static void resolve(string $text = "${label}", \\Contracts\\Subject $subject) */ class Delegation {}', 0];
        yield 'relative return suffix is not the qualified contract' => ['/** @method static Foreign\\Contracts\\Subject resolve() */ class Delegation {}', 1];
        yield 'quoted return is not a type contract' => ['/** @method static "\\Contracts\\Subject" resolve() */ class Delegation {}', 1];
        yield 'missing parameter close is not a signature' => ['/** @method static \\Contracts\\Subject resolve( */ class Delegation {}', 1];
        yield 'function tag is misplaced' => ['/** @method static \\Contracts\\Subject resolve() */ function delegate() {}', 1];
        yield 'native method tag is misplaced' => ['class Delegation { /** @method static \\Contracts\\Subject resolve() */ function delegate() {} }', 1];
        yield 'native parameter type remains a consumer' => ['function delegate(\\Contracts\\Subject $subject) {}', 0];
    }
}
