<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FactCollector;
use Cosmira\Soda\Analysis\ProjectFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Usage\NoUnusedPrivateState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoUnusedPrivateStateTest extends TestCase
{
    public function testWritesAndDecorativeConstantsDoNotCountAsReads(): void
    {
        $findings = $this->findings(['<?php class Worker {
            private const MARKER = 1;
            private int $count;
            function __construct(private string $unused) { $this->count = 0; }
            function reset() { $this->count = 0; }
        }']);
        self::assertCount(3, $findings);
        self::assertSame([3, 4, 2], array_column($findings, 'line'));
        self::assertSame('Worker', $findings[0]->class);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
    }

    public function testReadsAndPublicTransportStateAreAccepted(): void
    {
        self::assertSame([], $this->findings(['<?php class Counter {
            private const START = 1;
            private int $value;
            public string $label;
            function __construct() { $this->value = self::START; }
            function next() { return ++$this->value; }
        }']));
    }

    public function testTraitAndOwnerReadsResolveAcrossFiles(): void
    {
        self::assertSame([], $this->findings([
            '<?php namespace App; trait Values { private int $stored; function value() { return $this->value; } }',
            '<?php namespace App; class Record { use Values; private int $value; function stored() { return $this->stored; } }',
        ]));
    }

    public function testUnusedTraitStateReportsItsDeclarationAndOwner(): void
    {
        $findings = $this->findings(['<?php trait State { private int $unused; }', '<?php class Owner { use State; }']);
        self::assertCount(1, $findings);
        self::assertSame('Owner', $findings[0]->class);
        self::assertSame(1, $findings[0]->line);
    }

    public function testUnrelatedNestedClassCannotReadOuterPrivateState(): void
    {
        $findings = $this->findings(['<?php class Outer { private int $value; function nested() { return new class { function read() { return $this->value; } }; } }']);
        self::assertCount(1, $findings);
        self::assertSame('Outer', $findings[0]->class);
    }

    public function testUnrelatedReceiverDoesNotProtectDecorativeState(): void
    {
        self::assertCount(1, $this->findings(['<?php class Owner { private int $value; function inspect(Foreign $other) { return $other->value; } }']));
        self::assertCount(1, $this->findings(['<?php class Owner { private static int $value; function inspect() { return Foreign::$value; } }']));
        self::assertSame([], $this->findings(['<?php class Owner { private int $value; function inspect(self $other) { return $other->value; } }']));
        self::assertSame([], $this->findings(['<?php class Owner { private int $value; function inspect() { $alias = $this; return $alias->value; } }']));
    }

    public function testReceiverAliasesRespectOverwritesAndClosureCapture(): void
    {
        self::assertCount(1, $this->findings(['<?php class Owner { private int $value; function inspect(Foreign $other) { $alias = $this; $alias = $other; return $alias->value; } }']));
        self::assertSame([], $this->findings(['<?php class Owner { private int $value; function inspect() { $alias = $this; return function() use($alias) { return $alias->value; }; } }']));
        self::assertCount(1, $this->findings(['<?php class Owner { private int $value; function inspect() { $alias = $this; return function($alias) { return $alias->value; }; } }']));
    }

    public function testConditionalAliasesAndRightHandReadsRetainUsedState(): void
    {
        foreach ([
            'if (ready()) { $alias = $this; } return $alias->value;',
            '$alias = $this; $alias = $alias->value; return $alias;',
        ] as $body) {
            self::assertSame([], $this->findings(['<?php class Owner { private int $value; function inspect() { '.$body.' } }']));
        }
    }

    public function testArrayElementWritesDoNotInventAReadButIndicesCanReadState(): void
    {
        foreach (['$this->items[] = 1;', '$this->items[0][1] = 1;'] as $body) {
            self::assertCount(1, $this->findings(['<?php class Owner { private array $items = []; function fill() { '.$body.' } }']));
        }
        $findings = $this->findings(['<?php class Owner { private array $items = []; private int $index = 0; function fill() { $this->items[$this->index] = 1; } }']);
        self::assertCount(1, $findings);
        self::assertStringContainsString('property items', $findings[0]->message);
    }

    public function testDiscardedTraitReadersDoNotProtectUnusedPrivateState(): void
    {
        $trait = '<?php trait State { private int $value; function read() { return $this->value; } }';
        self::assertCount(1, $this->findings([$trait, '<?php class Owner { use State; function read() { return 1; } }']));
        self::assertSame([], $this->findings([$trait, '<?php class Owner { use State { read as originalRead; } function read() { return 1; } }']));
        self::assertCount(1, $this->findings([$trait, '<?php trait ConstantValue { function read() { return 1; } } class Owner { use State, ConstantValue { ConstantValue::read insteadof State; } }']));
    }

    public function testParentReadsDoNotProtectShadowedChildPrivateFields(): void
    {
        $findings = $this->findings(['<?php class ParentState { private int $value; function read() { return $this->value; } } class ChildState extends ParentState { private int $value; }']);
        self::assertCount(1, $findings);
        self::assertSame('ChildState', $findings[0]->class);
    }

    #[DataProvider('releases')]
    public function testReleasingRetainedStateCountsAsUse(string $body, int $count): void
    {
        $findings = $this->findings(['<?php class Owner { private $held; private static $shared; function release($name, Foreign $other) { '.$body.' } }']);
        self::assertCount($count, $findings);
    }

    public static function releases(): iterable
    {
        yield 'instance release' => ['$this->held = acquire(); $this->held = null;', 1];
        yield 'static release' => ['self::$shared = acquire(); self::$shared = null;', 1];
        yield 'late static release' => ['static::$shared = acquire(); static::$shared = NULL;', 1];
        yield 'known receiver alias releases a value' => ['$this->held = acquire(); $alias = $this; $alias->held = null;', 1];
        yield 'dynamic release can select an instance slot' => ['$this->held = acquire(); self::$shared = acquire(); $this->{$name} = null; self::$shared = null;', 0];
        yield 'clearing an array element releases its retained value' => ['$this->held = [acquire()]; $this->held[0] = null;', 1];
        yield 'ordinary scalar writes stay detectable' => ['$this->held = 0; self::$shared = 0;', 2];
        yield 'foreign release does not consume this state' => ['$other->held = null; Foreign::$shared = null;', 2];
        yield 'overwritten alias is no longer this owner' => ['$alias = $this; $alias = $other; $alias->held = null;', 2];
        yield 'nested class releases its own storage' => ['$nested = new class { function release() { $this->held = null; self::$shared = null; } };', 2];
    }

    public function testTraitReleaseUsesOnlyTheSelectedMethodBodyInEitherFileOrder(): void
    {
        $trait = '<?php trait Holding { private $value; function retain() { $this->value = acquire(); } function release() { $this->value = null; } }';
        $owner = '<?php class Owner { use Holding; }';
        foreach ([[$trait, $owner], [$owner, $trait]] as $sources) {
            self::assertSame([], $this->findings($sources));
        }
        self::assertCount(1, $this->findings([$trait, '<?php class Owner { use Holding; function release() {} }']));
    }

    public function testStandardRunnerRetainsStorageReleasedByAShutdownCallback(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-state-release-');
        self::assertIsString($path);
        file_put_contents($path, <<<'PHP'
<?php
class Reserve {
    private static ?string $buffer = null;
    private int $unused = 0;
    public function prepare() {
        static::$buffer = str_repeat('x', 32768);
        register_shutdown_function(function () {
            static::$buffer = null;
            emitFailure();
        });
        $this->unused = 1;
    }
}
PHP);

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'no_unused_private_state')->values();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
            self::assertSame('Reserve', $findings[0]->class);
            self::assertSame(4, $findings[0]->line);
            self::assertStringContainsString('property unused', $findings[0]->message);
        } finally {
            unlink($path);
        }
    }

    private function findings(array $sources): array
    {
        $rule = new NoUnusedPrivateState;
        $project = new ProjectFacts;
        foreach ($sources as $source) {
            $path = tempnam(sys_get_temp_dir(), 'soda-state-');
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
