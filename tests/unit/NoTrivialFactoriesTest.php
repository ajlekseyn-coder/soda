<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Structure\NoTrivialFactories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoTrivialFactoriesTest extends TestCase
{
    #[DataProvider('creationHooks')]
    public function testObservedVirtualCreationHook(string $declaration, int $count): void
    {
        self::assertCount($count, $this->findings('<?php '.$declaration));
    }

    public static function creationHooks(): iterable
    {
        yield 'later selector assignment cannot invalidate an earlier call' => ['class Builder { function build($driver) { $method = "create".$driver."Driver"; if (method_exists($this, $method)) { return $this->$method(); } $method = "fallback".$driver; return $this->$method(); } protected function createSyncDriver() { return new Product; } }', 0];
        yield 'multiple preceding assignments remain ambiguous' => ['class Builder { function build($driver) { $method = "create".$driver."Driver"; $method = input(); return $this->$method(); } protected function createSyncDriver() { return new Product; } }', 1];
        yield 'a future assignment cannot establish an earlier selector' => ['class Builder { function build() { $this->$method(); $method = "createSyncDriver"; } protected function createSyncDriver() { return new Product; } }', 1];
        yield 'another receiver does not establish the current hook' => ['class Builder { function build($other, $driver) { $method = "create".$driver."Driver"; return $other->$method(); } protected function createSyncDriver() { return new Product; } }', 1];
        yield 'protected instance hook' => ['class Builder { function build($value) { return $this->product($value); } protected function product($value) { return new Product($value); } }', 0];
        yield 'public instance hook' => ['class Builder { function build($value) { return $this->product($value); } public function product($value) { return new Product($value); } }', 0];
        yield 'trait creation hook' => ['trait Builder { function build($value) { return $this->product($value); } protected function product($value) { return new Product($value); } }', 0];
        yield 'late static creation hook' => ['trait Dispatchable { static function dispatch($value) { return static::pending($value); } protected static function pending($value) { return new Pending($value); } }', 0];
        yield 'trait self call binds to its consumer' => ['trait Builder { static function build($value) { return self::product($value); } protected static function product($value) { return new Product($value); } }', 0];
        yield 'private trait factory can be replaced by the consumer' => ['trait Builder { function build($value) { return $this->product($value); } private function product($value) { return new Product($value); } } class CustomBuilder { use Builder; public function product($value) { return new CustomProduct($value); } }', 0];
        yield 'final trait factory can be replaced in composition' => ['trait Builder { function build($value) { return $this->product($value); } final protected function product($value) { return new Product($value); } } class CustomBuilder { use Builder; protected function product($value) { return new CustomProduct($value); } }', 0];
        yield 'enum is closed to overrides' => ['enum Builder { case Default; function build($value) { return $this->product($value); } public function product($value) { return new Product($value); } }', 1];
        yield 'case insensitive dispatch' => ['class Builder { function build($value) { return $this->PRODUCT($value); } protected function product($value) { return new Product($value); } }', 0];
        yield 'nullsafe current receiver' => ['class Builder { function build($value) { return $this?->product($value); } protected function product($value) { return new Product($value); } }', 0];
        yield 'unobserved conventional name' => ['class Builder { protected function newProduct($value) { return new Product($value); } }', 1];
        yield 'foreign receiver' => ['class Builder { function build($service, $value) { return $service->product($value); } protected function product($value) { return new Product($value); } }', 1];
        yield 'late static binding can select another private-name implementation' => ['class Builder { static function build($value) { return static::product($value); } private static function product($value) { return new Product($value); } }', 0];
        yield 'self preserves private static binding' => ['class Builder { static function build($value) { return self::product($value); } private static function product($value) { return new Product($value); } }', 1];
        yield 'private method is not a virtual hook' => ['class Builder { function build($value) { return $this->product($value); } private function product($value) { return new Product($value); } }', 1];
        yield 'final method is not a virtual hook' => ['class Builder { function build($value) { return $this->product($value); } final protected function product($value) { return new Product($value); } }', 1];
        yield 'final class closes dispatch' => ['final class Builder { function build($value) { return $this->product($value); } protected function product($value) { return new Product($value); } }', 1];
        yield 'self fixes static dispatch' => ['class Builder { static function build($value) { return self::product($value); } protected static function product($value) { return new Product($value); } }', 1];
        yield 'foreign static receiver' => ['class Builder { static function build($value) { return Other::product($value); } protected static function product($value) { return new Product($value); } }', 1];
        yield 'nested closure cannot donate dispatch' => ['class Builder { function build($value) { return function () use ($value) { return $this->product($value); }; } protected function product($value) { return new Product($value); } }', 1];
        yield 'nested class cannot donate dispatch' => ['class Builder { function build() { return new class { function run($value) { return $this->product($value); } }; } protected function product($value) { return new Product($value); } }', 1];
    }

    public function testStandardRunnerPreservesOnlyTheObservedOverridableHook(): void
    {
        $source = <<<'PHP'
<?php
class Builder {
    public function build($value) { return $this->product($value); }
    protected function product($value) { return new Product($value); }
    public function unused($value) { return new Product($value); }
}
class CustomBuilder extends Builder {
    protected function product($value) { return new CustomProduct($value); }
}
PHP;
        $findings = $this->findings($source, RuleCatalog::standard());
        self::assertCount(1, $findings);
        self::assertSame('unused', $findings[0]->method);
        self::assertSame(5, $findings[0]->line);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
    }

    #[DataProvider('localPackageContracts')]
    public function testLocalComposerContractWithoutInstalledMetadata(array|string $manifest, string $parent, string $child, int $count): void
    {
        $this->withLocalPackage($manifest, $parent, $child, function (array $files, string $root) use ($count): void {
            $result = (new Runner)->check([$files[0]], [new NoTrivialFactories]);
            self::assertCount($count, $result->violations);
            self::assertFileDoesNotExist($root.'/src/executed');
        });
    }

    public static function localPackageContracts(): iterable
    {
        $parent = 'namespace App; file_put_contents(__DIR__."/executed", "bad"); class BaseBuilder { public function build($value) { return $this->product($value); } protected function product($value) { return new Product($value); } }';
        $child = 'namespace App; class Builder extends BaseBuilder { protected function product($value) { return new CustomProduct($value); } }';
        yield 'package PSR-4' => [['autoload' => ['psr-4' => ['App\\' => 'src/']]], $parent, $child, 0];
        yield 'multiple PSR-4 roots' => [['autoload' => ['psr-4' => ['App\\' => ['absent/', 'src/']]]], $parent, $child, 0];
        yield 'development PSR-4' => [['autoload-dev' => ['psr-4' => ['App\\' => 'src/']]], $parent, $child, 0];
        yield 'trait contract' => [['autoload' => ['psr-4' => ['App\\' => 'src/']]], str_replace('class BaseBuilder', 'trait BaseBuilder', $parent), 'namespace App; class Builder { use BaseBuilder; protected function product($value) { return new CustomProduct($value); } }', 0];
        yield 'malformed metadata' => ['{not JSON', $parent, $child, 1];
        yield 'scalar metadata' => ['"not a package"', $parent, $child, 1];
        yield 'unregistered namespace' => [['autoload' => ['psr-4' => ['Other\\' => 'src/']]], $parent, $child, 1];
        yield 'unrelated declaration' => [['autoload' => ['psr-4' => ['App\\' => 'src/']]], str_replace('namespace App;', 'namespace Other;', $parent), $child, 1];
        yield 'private parent method is fixed' => [['autoload' => ['psr-4' => ['App\\' => 'src/']]], str_replace('protected function product', 'private function product', $parent), $child, 1];
    }

    public function testStandardRunnerResolvesPackageHooksInEitherFileOrder(): void
    {
        $parent = 'namespace App; throw new \\RuntimeException("Do not execute"); class BaseBuilder { function build($value) { return $this->product($value); } protected function product($value) { return new Product($value); } }';
        $child = 'namespace App; class Builder extends BaseBuilder { protected function product($value) { return new CustomProduct($value); } public function unused($value) { return new Product($value); } }';
        $this->withLocalPackage(['autoload' => ['psr-4' => ['App\\' => 'src/'], 'files' => ['autoload.php']]], $parent, $child, function (array $files): void {
            foreach ([$files, array_reverse($files)] as $paths) {
                $result = (new Runner)->check($paths, RuleCatalog::standard());
                $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'no_trivial_factories')->values();
                self::assertCount(1, $findings);
                self::assertSame('unused', $findings[0]->method);
            }
        });
    }

    public function testClosestPackageDoesNotBorrowAnotherRootNamespace(): void
    {
        $parent = 'namespace App; class BaseBuilder { protected function product($value) { return new Product($value); } }';
        $child = 'namespace App; class Builder extends BaseBuilder { protected function product($value) { return new Product($value); } }';
        $this->withLocalPackage(['autoload' => ['psr-4' => ['App\\' => 'src/']]], $parent, $child, function (array $files, string $root): void {
            mkdir($root.'/nested/src', recursive: true);
            $manifest = $root.'/nested/composer.json';
            $path = $root.'/nested/src/Builder.php';
            file_put_contents($manifest, json_encode(['autoload' => ['psr-4' => ['Other\\' => 'src/']]], JSON_THROW_ON_ERROR));
            file_put_contents($path, file_get_contents($files[0]));

            try {
                self::assertCount(1, (new Runner)->check([$path], [new NoTrivialFactories])->violations);
            } finally {
                unlink($path);
                unlink($manifest);
                rmdir($root.'/nested/src');
                rmdir($root.'/nested');
            }
        });
    }

    public function testAnUnregisteredProjectArchiveDoesNotInventAContract(): void
    {
        $child = 'namespace App; class Builder extends BaseBuilder { protected function product($value) { return new Product($value); } }';
        $this->withLocalPackage([], 'namespace Other; class BaseBuilder {}', $child, function (array $files, string $root): void {
            $path = $root.'/backup.zip';
            $archive = new \PharData($path);
            $archive['BaseBuilder.php'] = '<?php namespace App; class BaseBuilder { protected function product($value) {} }';
            unset($archive);

            try {
                self::assertCount(1, (new Runner)->check([$files[0]], [new NoTrivialFactories])->violations);
            } finally {
                unlink($path);
            }
        });
    }

    #[DataProvider('enclosingPackages')]
    public function testStandardRunnerResolvesAnExplicitlyBundledPackage(array $outer, array $inner, int $count, bool $signature = false): void
    {
        $parent = 'namespace App; throw new \RuntimeException("Do not execute"); class BaseBuilder { function build($driver) { $method = "create".$driver."Driver"; if (method_exists($this, $method)) { return $this->$method(); } $method = "fallback".$driver; return $this->$method(); } }';
        $child = 'namespace App\Feature; class Builder extends \App\BaseBuilder { protected function createSyncDriver() { return new Product; } public function unused($value) { return new Product($value); } }';
        if ($signature) {
            $parent = 'namespace App; throw new \\RuntimeException("Do not execute"); interface BaseBuilder { public function createSyncDriver(); }';
            $child = str_replace(['extends \\App\\BaseBuilder', 'protected function'], ['implements \\App\\BaseBuilder', 'public function'], $child);
        }

        $this->withLocalPackage($outer, $parent, $child, function (array $files, string $root) use ($inner, $count): void {
            $directory = $root.'/src/Feature';
            mkdir($directory);
            $manifest = $directory.'/composer.json';
            $path = $directory.'/Builder.php';
            file_put_contents($manifest, json_encode($inner, JSON_THROW_ON_ERROR));
            file_put_contents($path, file_get_contents($files[0]));

            try {
                $result = (new Runner)->check([$path], RuleCatalog::standard());
                $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'no_trivial_factories')->values();
                self::assertCount($count, $findings);
                self::assertContains('unused', $findings->pluck('method')->all());
                self::assertFileDoesNotExist($root.'/src/executed');
            } finally {
                unlink($path);
                unlink($manifest);
                rmdir($directory);
            }
        });
    }

    public static function enclosingPackages(): iterable
    {
        $outer = ['name' => 'example/bundle', 'autoload' => ['psr-4' => ['App\\' => 'src/'], 'files' => ['autoload.php']]];
        $inner = ['name' => 'example/feature', 'autoload' => ['psr-4' => ['App\\Feature\\' => '']]];
        yield 'bundle supplies a sibling interface signature' => [$outer + ['replace' => ['example/feature' => 'self.version']], $inner, 1, true];
        yield 'bundle declares the nested package it replaces' => [$outer + ['replace' => ['example/feature' => 'self.version']], $inner, 1];
        yield 'require alone does not provide source' => [$outer + ['require' => ['example/feature' => '*']], $inner, 2];
        yield 'an unrelated outer project is not a source provider' => [$outer, $inner, 2];
        yield 'replacement names must match' => [$outer + ['replace' => ['example/other' => '*']], $inner, 2];
        yield 'unnamed nested metadata cannot claim a replacement' => [$outer + ['replace' => ['example/feature' => '*']], ['autoload' => $inner['autoload']], 2];
        yield 'the enclosing prefix must resolve the ancestor' => [
            ['replace' => ['example/feature' => '*'], 'autoload' => ['psr-4' => ['Other\\' => 'src/']]], $inner, 2,
        ];
    }

    private function withLocalPackage(array|string $manifest, string $parent, string $child, callable $test): void
    {
        $root = sys_get_temp_dir().'/soda-package-hooks-'.bin2hex(random_bytes(6));
        mkdir($root.'/src', recursive: true);
        $composer = $root.'/composer.json';
        $autoload = $root.'/autoload.php';
        $base = $root.'/src/BaseBuilder.php';
        $own = $root.'/src/Builder.php';
        file_put_contents($composer, is_array($manifest) ? json_encode($manifest, JSON_THROW_ON_ERROR) : $manifest);
        file_put_contents($autoload, '<?php throw new \\RuntimeException("Autoloader must not execute");');
        file_put_contents($base, '<?php '.$parent);
        file_put_contents($own, '<?php '.$child);

        try {
            $test([$own, $base], $root);
        } finally {
            foreach ([$composer, $autoload, $base, $own, $root.'/src/executed'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($root.'/src');
            rmdir($root);
        }
    }

    private function findings(string $source, ?array $checks = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-virtual-factory-');
        self::assertIsString($path);
        file_put_contents($path, $source);

        try {
            $result = (new Runner)->check([$path], $checks ?? [new NoTrivialFactories]);

            return $result->violations->filter(static fn ($row): bool => $row->rule === 'no_trivial_factories')->values()->all();
        } finally {
            unlink($path);
        }
    }
}
