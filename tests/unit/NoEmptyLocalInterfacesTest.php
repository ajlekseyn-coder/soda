<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use PHPUnit\Framework\TestCase;

final class NoEmptyLocalInterfacesTest extends TestCase
{
    public function testUnknownAndNativeAncestorsAreNotEmptyContracts(): void
    {
        $this->withProject(function (string $root): void {
            $path = $this->write($root, 'src/Contracts.php', '<?php namespace App;
interface FailoverableException extends \\Throwable {}
interface Response extends \\Illuminate\\Contracts\\Support\\Responsable {}
interface Marker { const LABEL = "marker"; }');
            $findings = $this->findings([$path]);
            self::assertSame(['App\\Marker'], array_column($findings, 'class'));
            self::assertSame($path, $findings[0]->file);
            self::assertSame(4, $findings[0]->line);
            self::assertSame(1, $findings[0]->value);
            self::assertSame(0, $findings[0]->threshold);
        });
    }

    public function testNativeEmptyContractsRemainReportableWithoutAutoloadingUnknownParents(): void
    {
        $this->withProject(function (string $root): void {
            $path = $this->write($root, 'src/Contracts.php', '<?php namespace App;
interface IterableMarker extends \\Traversable {}
interface Unresolved extends \\Missing\\Protocol {}');
            $autoload = static function (string $name): void {
                throw new \LogicException('Unexpected autoload: '.$name);
            };
            spl_autoload_register($autoload);

            try {
                self::assertSame(['App\\IterableMarker'], array_column($this->findings([$path]), 'class'));
            } finally {
                spl_autoload_unregister($autoload);
            }
        });
    }

    public function testLocalAliasesAndCyclesAreIndependentOfFileOrder(): void
    {
        $this->withProject(function (string $root): void {
            $base = $this->write($root, 'src/Base.php', '<?php namespace Contracts;
interface ParentContract { function run(); }
interface EmptyParent {}
interface UnknownParent extends \\Missing\\Protocol {}
interface CycleA extends CycleB {}
interface CycleB extends CycleA {}
interface OpenA extends OpenB {}
interface OpenB extends OpenA, \\Missing\\Protocol {}
interface MethodA extends MethodB {}
interface MethodB extends MethodA { function run(); }');
            $child = $this->write($root, 'src/Child.php', '<?php namespace App;
use Contracts\\ParentContract as Base;
interface Child extends Base {}
interface EmptyChild extends \\Contracts\\EmptyParent {}
interface UnknownChild extends \\Contracts\\UnknownParent {}');
            foreach ([[$base, $child], [$child, $base]] as $paths) {
                $names = array_column($this->findings($paths), 'class');
                sort($names);
                self::assertSame(['App\\EmptyChild', 'Contracts\\CycleA', 'Contracts\\CycleB', 'Contracts\\EmptyParent'], $names);
            }
        });
    }

    public function testInterfacePropertiesProvideAContract(): void
    {
        $this->withProject(function (string $root): void {
            $path = $this->write($root, 'src/Properties.php', '<?php namespace App;
interface Named { public string $name { get; } }
interface Renamable extends Named { public string $name { get; set; } }
interface Child extends Named {}
interface Marker {}');
            self::assertSame(['App\\Marker'], array_column($this->findings([$path]), 'class'));
        });
    }

    public function testComposerAncestorsAreReadWithoutExecutingSourceOrAutoload(): void
    {
        $this->withProject(function (string $root): void {
            $this->write($root, 'composer.json', json_encode(['autoload' => ['psr-4' => ['Contracts\\' => 'contracts/']]], JSON_THROW_ON_ERROR));
            $this->write($root, 'vendor/composer/installed.json', json_encode(['packages' => [[
                'name'     => 'vendor/api', 'install-path' => '../vendor/api',
                'autoload' => ['psr-4' => ['Vendor\\Api\\' => 'src/']],
            ]]], JSON_THROW_ON_ERROR));
            $this->write($root, 'vendor/autoload.php', '<?php throw new \\RuntimeException("Do not execute");');
            $this->write($root, 'contracts/LocalBase.php', '<?php namespace Contracts; interface LocalBase extends \\Vendor\\Api\\Response {}');
            $this->write($root, 'vendor/vendor/api/src/Response.php', '<?php namespace Vendor\\Api; file_put_contents(__DIR__."/executed", "bad"); interface Response extends Base {}');
            $this->write($root, 'vendor/vendor/api/src/Base.php', '<?php namespace Vendor\\Api; interface Base { function toResponse(); }');
            $this->write($root, 'vendor/vendor/api/src/EmptyMarker.php', '<?php namespace Vendor\\Api; interface EmptyMarker { const LABEL = "marker"; }');
            $this->write($root, 'vendor/vendor/api/src/Named.php', '<?php namespace Vendor\\Api; interface Named { public string $name { get; } }');
            $this->write($root, 'vendor/vendor/api/src/Wrong.php', '<?php namespace Unrelated; interface Wrong {}');
            $this->write($root, 'vendor/vendor/api/src/NotInterface.php', '<?php namespace Vendor\\Api; class NotInterface {}');
            $this->write($root, 'vendor/vendor/api/src/Unresolved.php', '<?php namespace Vendor\\Api; interface Unresolved extends Missing {}');
            $first = $this->write($root, 'src/Response.php', '<?php namespace App;
use Contracts\\LocalBase as Base;
interface Response extends Base {}
interface Marker extends \\Vendor\\Api\\EmptyMarker {}
interface Named extends \\Vendor\\Api\\Named {}');
            $second = $this->write($root, 'src/Unknown.php', '<?php namespace App;
interface Wrong extends \\Vendor\\Api\\Wrong {}
interface Invalid extends \\Vendor\\Api\\NotInterface {}
interface Unresolved extends \\Vendor\\Api\\Unresolved {}
interface EmptyControl {}');
            foreach ([[$first, $second], [$second, $first]] as $paths) {
                $names = array_column($this->findings($paths), 'class');
                sort($names);
                self::assertSame(['App\\EmptyControl', 'App\\Marker'], $names);
            }
            self::assertFileDoesNotExist($root.'/vendor/vendor/api/src/executed');
        });
    }

    private function findings(array $paths): array
    {
        $violations = (new Runner)->check($paths, Soda::configure()->with(RuleCatalog::standard())->checks())->violations;
        self::assertCount(0, $violations->filter(fn ($violation): bool => $violation->rule === 'parse_error'));

        return $violations->filter(fn ($violation): bool => $violation->rule === 'no_empty_local_interfaces')->values()->all();
    }

    private function withProject(callable $test): void
    {
        $root = tempnam(sys_get_temp_dir(), 'soda-empty-interface-');
        unlink($root);
        mkdir($root);

        try {
            $test($root);
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    private function write(string $root, string $relative, string $source): string
    {
        $path = $root.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }
        file_put_contents($path, $source);

        return $path;
    }
}
