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
        $root = sys_get_temp_dir().'/soda-mixin-contract-'.bin2hex(random_bytes(6));
        mkdir($root);
        $declarations = $root.'/Contracts.php';
        $delegation = $root.'/Delegation.php';
        file_put_contents($declarations, '<?php namespace Contracts;
            interface Subject { function run(); }
            interface Unused { function run(); }
            /** @mixin \\Contracts\\Selfish */ interface Selfish { function run(); }');
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
}
