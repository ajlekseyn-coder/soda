<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Complexity\NoAssignmentInReturn;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\TestCase;

final class LaravelReadabilityPolicyTest extends TestCase
{
    public function testUnchangedLaravelMethodsPreserveVisibleStateAndExposeHiddenLocals(): void
    {
        $source = file_get_contents($this->fixture('laravel-methods.php'));
        $rule = array_values(array_filter(RuleCatalog::standard(), fn ($check): bool => $check->id() === 'no_assignment_in_return'))[0];
        $findings = FiveRuleFixture::findings($rule, $source);
        self::assertCount(1, $findings);
        self::assertStringContainsString('$tokenEntropy = Str::random(40)', $source);
        $line = explode("\n", $source)[$findings[0]->line - 1];
        self::assertStringContainsString('return sprintf(', $line);
        self::assertCount(8, FiveRuleFixture::findings(new NoAssignmentInReturn(allowLazyInitialization: false, allowOwnerStateReturn: false, allowArrowStateUpdates: false), $source));
    }

    public function testExcerptsMatchPinnedMethodSyntaxAndSourceProvenance(): void
    {
        $provenance = json_decode(file_get_contents($this->fixture('laravel-methods.json')), true, flags: JSON_THROW_ON_ERROR);
        $packages = json_decode(file_get_contents(__DIR__.'/../../docs/research/laravel-audit/packages.json'), true, flags: JSON_THROW_ON_ERROR)['packages'];
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse(file_get_contents($this->fixture('laravel-methods.php')));
        $finder = new NodeFinder;
        $printer = new Standard;
        self::assertCount(5, $provenance['examples']);
        foreach ($provenance['examples'] as $example) {
            $package = array_values(array_filter($packages, fn ($package): bool => $package['name'] === $example['package']))[0];
            self::assertSame($package['version'], $example['version']);
            self::assertSame($package['source_commit'], $example['source_commit']);
            $hashes = array_column($package['files'], 'sha256', 'path');
            self::assertSame($hashes[$example['path']], $example['source_sha256']);
            $class = $finder->findFirst($nodes, fn ($node): bool => $node instanceof Stmt\Class_ && $node->name->toString() === $example['fixture_class']);
            $method = $class->getMethod($example['method']);
            self::assertSame($example['method_code_sha256'], hash('sha256', $printer->prettyPrint([$method])));
        }
    }

    private function fixture(string $name): string
    {
        return __DIR__.'/../fixtures/readability/'.$name;
    }
}
