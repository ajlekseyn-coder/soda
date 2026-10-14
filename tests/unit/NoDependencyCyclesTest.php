<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FactCollector;
use Cosmira\Soda\Analysis\ProjectFacts;
use Cosmira\Soda\Analysis\StronglyConnectedComponents;
use Cosmira\Soda\Rules\Architecture\NoDependencyCycles;
use PHPUnit\Framework\TestCase;

final class NoDependencyCyclesTest extends TestCase
{
    public function testCrossFileCycleHasEvidence(): void
    {
        $findings = $this->findings([
            '<?php namespace Sales; use Billing\\Invoice as Bill; class Order { function pay(Bill $bill) {} }',
            '<?php namespace Billing; class Invoice { function order(\\Sales\\Order $order) {} }',
        ]);
        self::assertCount(1, $findings);
        self::assertSame(2, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertSame(1, $findings[0]->line);
        self::assertStringContainsString('billing, sales', $findings[0]->message);
    }

    public function testOneWayAndInternalDependenciesAreAllowed(): void
    {
        self::assertSame([], $this->findings([
            '<?php namespace Sales; class Order { function pay(\\Billing\\Invoice $bill) {} }',
            '<?php namespace Billing; class Invoice {}',
        ]));
        self::assertSame([], $this->findings(['<?php namespace Sales; class Order { function run(Item $item) {} } class Item { function run(Order $order) {} }']));
    }

    public function testConfiguredPrefixGroupsNestedNamespaces(): void
    {
        $sources = [
            '<?php namespace App\\Sales\\Orders; class Order { function run(\\App\\Sales\\Items\\Item $item) {} }',
            '<?php namespace App\\Sales\\Items; class Item { function run(\\App\\Sales\\Orders\\Order $order) {} }',
        ];
        self::assertCount(1, $this->findings($sources));
        self::assertSame([], $this->findings($sources, ['App\\Sales']));
        self::assertCount(1, $this->findings($sources, ['App\\Sales', 'App\\Sales\\Items']));
    }

    public function testComponentFinderHandlesLongCycleAndResetsBetweenProjects(): void
    {
        $finder = new StronglyConnectedComponents;
        self::assertSame([['a', 'b', 'c'], ['d', 'e']], $finder->collect(['a' => ['b'], 'b' => ['c'], 'c' => ['a'], 'd' => ['e'], 'e' => ['d']]));
        self::assertSame([['x']], $finder->collect(['x' => []]));
    }

    public function testRequiredConstructorCycleWithinOneNamespace(): void
    {
        $sources = [
            '<?php namespace App; final class Checkout { public function __construct(Stock $stock) {} }',
            '<?php namespace App; final class Stock { public function __construct(Checkout $checkout) {} }',
        ];
        $findings = $this->findings($sources);
        self::assertCount(1, $findings);
        self::assertSame('no_dependency_cycles', $findings[0]->rule);
        self::assertSame(2, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('Constructor dependency cycle', $findings[0]->message);
        self::assertStringContainsString('App\\Checkout::__construct($stock)', $findings[0]->message);
        self::assertStringContainsString('App\\Stock::__construct($checkout)', $findings[0]->message);
        self::assertSame(1, $findings[0]->line);
        self::assertCount(1, $this->findings(array_reverse($sources)));
        self::assertCount(1, $this->findings($sources, ['App']));
        self::assertSame([], $this->findings($sources, ['Other']));
    }

    public function testConstructorCycleThroughThreeFilesAndAliases(): void
    {
        $findings = $this->findings([
            '<?php namespace App; use App\\Second as Peer; final class First { public function __construct(Peer $peer) {} }',
            '<?php namespace App; final class Second { public function __construct(Third $next) {} }',
            '<?php namespace App; final class Third { public function __construct(First $first) {} }',
        ]);
        self::assertCount(1, $findings);
        self::assertSame(3, $findings[0]->value);
        foreach (['App\\First', 'App\\Second', 'App\\Third'] as $type) {
            self::assertStringContainsString($type, $findings[0]->message);
        }
    }

    public function testConstructorSelfCycleIsReported(): void
    {
        $findings = $this->findings(['<?php namespace App; final class Chain { public function __construct(self $previous) {} }']);
        self::assertCount(1, $findings);
        self::assertSame(1, $findings[0]->value);
        self::assertStringContainsString('App\\Chain::__construct($previous)', $findings[0]->message);
    }

    public function testConstructorCyclesDoNotDuplicateModuleFindings(): void
    {
        $findings = $this->findings([
            '<?php namespace First; final class A { public function __construct(\\Second\\B $b) {} }',
            '<?php namespace Second; final class B { public function __construct(\\First\\A $a) {} }',
        ]);
        self::assertCount(1, $findings);
        self::assertStringContainsString('Mutually dependent modules', $findings[0]->message);
    }

    public function testPossibleConstructionBreaksRemainAccepted(): void
    {
        foreach ([
            '?A $a', 'A $a = new A()', 'A ...$items', 'A|\\stdClass $a', 'A &$a', '\\Closure $factory',
        ] as $parameter) {
            self::assertSame([], $this->findings([
                '<?php namespace App; final class A { public function __construct(B $b) {} }',
                '<?php namespace App; final class B { public function __construct('.$parameter.') {} }',
            ]), $parameter);
        }
        foreach ([
            'class B { public function __construct(A $a) {} }',
            'abstract class B { public function __construct(A $a) {} }',
            'interface B {}',
            'final class B { private function __construct(A $a) {} }',
            'final class B extends Base { public function __construct(A $a) {} }',
            'final class B { use Concern; public function __construct(A $a) {} }',
        ] as $declaration) {
            self::assertSame([], $this->findings([
                '<?php namespace App; final class A { public function __construct(B $b) {} }',
                '<?php namespace App; '.$declaration,
            ]), $declaration);
        }
    }

    public function testUnusedImportsMethodParametersAndUnknownClassesDoNotCreateConstructionEdges(): void
    {
        self::assertSame([], $this->findings([
            '<?php namespace App; use App\\B; final class A { public function run(B $b) {} }',
            '<?php namespace App; final class B { public function __construct(A $a) {} }',
        ]));
        self::assertSame([], $this->findings([
            '<?php namespace App; final class A { public function __construct(External $dependency) {} }',
        ]));
    }

    public function testGroupingModulesCannotHideARequiredConstructorCycle(): void
    {
        $sources = [
            '<?php namespace App\\Sales; final class Checkout { public function __construct(\\App\\Sales\\Stock\\Inventory $stock) {} }',
            '<?php namespace App\\Sales\\Stock; final class Inventory { public function __construct(\\App\\Sales\\Checkout $checkout) {} }',
        ];
        $moduleFindings = $this->findings($sources);
        self::assertCount(1, $moduleFindings);
        self::assertStringContainsString('Mutually dependent modules', $moduleFindings[0]->message);
        $constructionFindings = $this->findings($sources, ['App\\Sales']);
        self::assertCount(1, $constructionFindings);
        self::assertStringContainsString('Constructor dependency cycle', $constructionFindings[0]->message);
    }

    public function testIndependentCyclesAndPromotedParametersKeepTheirOwnEvidence(): void
    {
        $findings = $this->findings([
            '<?php namespace App; final class A { public function __construct(public B $b) {} }',
            '<?php namespace App; final class B { public function __construct(public A $a) {} }',
            '<?php namespace App; final class C { public function __construct(D $d, External $external) {} }',
            '<?php namespace App; final class D { public function __construct(C $c) {} }',
        ]);
        self::assertCount(2, $findings);
        self::assertSame([2, 2], array_column($findings, 'value'));
        self::assertStringContainsString('App\\A::__construct($b)', $findings[0]->message);
        self::assertStringNotContainsString('App\\C', $findings[0]->message);
        self::assertStringContainsString('App\\C::__construct($d)', $findings[1]->message);
        self::assertStringNotContainsString('External', $findings[1]->message);
    }

    public function testDuplicateClassDeclarationsAcrossFilesDoNotProveConstruction(): void
    {
        self::assertSame([], $this->findings([
            '<?php namespace App; final class A { public function __construct(B $b) {} }',
            '<?php namespace App; final class A { public function __construct() {} }',
            '<?php namespace App; final class B { public function __construct(A $a) {} }',
        ]));
        self::assertSame([], $this->findings([
            '<?php namespace App; final class A { public function __construct(B $b) {} }',
            '<?php namespace App; final class B { public function __construct() {} }',
        ]));
    }

    private function findings(array $sources, array $modules = []): array
    {
        $rule = new NoDependencyCycles($modules);
        $project = new ProjectFacts;
        foreach ($sources as $source) {
            $path = tempnam(sys_get_temp_dir(), 'soda-cycle-');
            file_put_contents($path, $source);

            try {
                $project->add((new FactCollector)->collect($path, $rule->requiredAnalyses()));
            } finally {
                unlink($path);
            }
        }

        return iterator_to_array($rule->checkProject($project));
    }
}
