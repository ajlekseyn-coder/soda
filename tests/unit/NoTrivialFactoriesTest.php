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
