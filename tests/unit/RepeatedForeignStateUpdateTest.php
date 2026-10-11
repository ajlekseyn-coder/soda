<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\FactCollector;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Config\SodaInitFileEmitter;
use Cosmira\Soda\Rules\Design\RepeatedForeignStateUpdate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StateUpdateBefore\Wallet;

final class RepeatedForeignStateUpdateTest extends TestCase
{
    public function testCrossFileUpdateReportsFieldExpressionAndAllConsumers(): void
    {
        $rows = $this->findings([$this->wallet(), ...$this->consumers('$wallet->setBalance($wallet->balance() - $amount);')]);
        $this->assertCount(1, $rows);
        $row = $rows[0]->toArray();
        $this->assertSame('repeated_foreign_state_update', $row['rule']);
        $this->assertSame(3, $row['value']);
        $this->assertSame(2, $row['threshold']);
        $this->assertSame(3, $row['line']);
        $this->assertStringContainsString('Domain\\Wallet::$balance', (string) $row['message']);
        $this->assertStringContainsString('Expression: $wallet->balance() - $amount', (string) $row['message']);
        $this->assertStringContainsString('App\\third at ', (string) $row['message']);
        $this->assertStringContainsString('debit($amount)', (string) $row['message']);
        $this->assertStringContainsString('No atomicity is inferred', (string) $row['message']);
        $this->assertSame($row, json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testRenamedParametersAndProvenAliasesShareOneReceiver(): void
    {
        $rows = $this->findings([
            $this->wallet(),
            '<?php function first(\\Domain\\Wallet $wallet, int $amount): void { $wallet->setBalance($wallet->balance() - $amount); }',
            '<?php function second(int $value, \\Domain\\Wallet $account): void { $account->setBalance($account->balance() - $value); }',
            '<?php function third(\\Domain\\Wallet $owner, int $sum): void { $alias = $owner; $alias->setBalance($owner->balance() - $sum); }',
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]->value);
    }

    #[DataProvider('supportedUpdates')]
    public function testSupportedArithmeticAndNamedInput(string $statement): void
    {
        $this->assertCount(1, $this->findings([$this->wallet(), ...$this->consumers($statement)]));
    }

    public static function supportedUpdates(): \Iterator
    {
        yield 'named input' => ['$wallet->setBalance(value: $wallet->balance() - $amount);'];
        yield 'literal' => ['$wallet->setBalance($wallet->balance() - 1);'];
        yield 'nested arithmetic' => ['$wallet->setBalance(($wallet->balance() - $amount) * 2);'];
        yield 'multiple reads same field' => ['$wallet->setBalance($wallet->balance() + $wallet->balance());'];
        yield 'unary calculation' => ['$wallet->setBalance(-$wallet->balance());'];
        yield 'division' => ['$wallet->setBalance($wallet->balance() / 2);'];
        yield 'modulus' => ['$wallet->setBalance($wallet->balance() % 2);'];
        yield 'power' => ['$wallet->setBalance($wallet->balance() ** 2);'];
        yield 'nested branch' => ['if ($amount > 0) { $wallet->setBalance($wallet->balance() - $amount); }'];
    }

    #[DataProvider('unsupportedUpdates')]
    public function testUnknownEffectsBindingsAndDifferentReceiversAreExcluded(string $statement): void
    {
        $this->assertSame([], $this->findings([$this->wallet(), ...$this->consumers($statement)]));
    }

    public static function unsupportedUpdates(): \Iterator
    {
        yield 'another receiver same class' => ['$wallet->setBalance($other->balance() - $amount);'];
        yield 'another field' => ['$wallet->setBalance($wallet->limit() - $amount);'];
        yield 'mixed fields' => ['$wallet->setBalance($wallet->balance() - $wallet->limit());'];
        yield 'plain copy' => ['$wallet->setBalance($wallet->balance());'];
        yield 'literal write' => ['$wallet->setBalance(100);'];
        yield 'getter arguments' => ['$wallet->setBalance($wallet->balance(1) - $amount);'];
        yield 'unknown getter' => ['$wallet->setBalance($wallet->unknown() - $amount);'];
        yield 'unknown setter' => ['$wallet->unknown($wallet->balance() - $amount);'];
        yield 'getter with effects' => ['$wallet->setBalance($wallet->touch() - $amount);'];
        yield 'external function' => ['$wallet->setBalance($wallet->balance() - amount());'];
        yield 'property read outside contract' => ['$wallet->setBalance($wallet->balance() - $other->value);'];
        yield 'computed local' => ['$value = $amount * 2; $wallet->setBalance($wallet->balance() - $value);'];
        yield 'wrong argument count' => ['$wallet->setBalance($wallet->balance() - $amount, 1);'];
        yield 'wrong named argument' => ['$wallet->setBalance(amount: $wallet->balance() - $amount);'];
        yield 'unpack' => ['$wallet->setBalance(...[$wallet->balance() - $amount]);'];
        yield 'nullsafe setter' => ['$wallet?->setBalance($wallet->balance() - $amount);'];
        yield 'nullsafe getter' => ['$wallet->setBalance($wallet?->balance() - $amount);'];
        yield 'dynamic setter' => ['$wallet->{$amount}($wallet->balance() - $amount);'];
        yield 'dynamic getter' => ['$wallet->setBalance($wallet->{$amount}() - $amount);'];
        yield 'receiver reassigned later' => ['$wallet->setBalance($wallet->balance() - $amount); $wallet = $other;'];
        yield 'alias reassigned later' => ['$alias = $wallet; $alias->setBalance($alias->balance() - $amount); $alias = $other;'];
        yield 'alias established later' => ['$alias->setBalance($alias->balance() - $amount); $alias = $wallet;'];
        yield 'reference alias' => ['$alias =& $wallet; $wallet->setBalance($wallet->balance() - $amount);'];
        yield 'unknown call may rebind alias source' => ['$alias = $wallet; rebind($wallet); $alias->setBalance($wallet->balance() - $amount);'];
        yield 'input passed by unknown contract' => ['change($amount); $wallet->setBalance($wallet->balance() - $amount);'];
        yield 'destructuring rebind' => ['[$wallet] = [$other]; $wallet->setBalance($wallet->balance() - $amount);'];
        yield 'foreach rebind' => ['foreach ([$other] as $wallet) { $wallet->setBalance($wallet->balance() - $amount); }'];
        yield 'unset binding' => ['unset($wallet); $wallet->setBalance($wallet->balance() - $amount);'];
        yield 'closure capture' => ['$callback = function () use ($wallet, $amount) { $wallet->setBalance($wallet->balance() - $amount); };'];
        yield 'reference capture' => ['$callback = function () use (&$wallet) {}; $wallet->setBalance($wallet->balance() - $amount);'];
        yield 'arrow capture' => ['$callback = fn () => $wallet->setBalance($wallet->balance() - $amount);'];
        yield 'input mutation' => ['$amount++; $wallet->setBalance($wallet->balance() - $amount);'];
        yield 'string conversion' => ['$wallet->setBalance($wallet->balance() . $amount);'];
        yield 'cast' => ['$wallet->setBalance((int) ($wallet->balance() - $amount));'];
    }

    #[DataProvider('ownerBoundaries')]
    public function testUnprovenOwnerContractsAreExcluded(string $search, string $replacement): void
    {
        $owner = str_replace($search, $replacement, $this->wallet());
        $this->assertSame([], $this->findings([$owner, ...$this->consumers('$wallet->setBalance($wallet->balance() - $amount);')]));
    }

    public static function ownerBoundaries(): \Iterator
    {
        yield 'overridable accessors' => ['final class Wallet', 'class Wallet'];
        yield 'inheritance' => ['final class Wallet', 'final class Wallet extends External'];
        yield 'trait composition' => ['private int $balance', 'use Unknown; private int $balance'];
        yield 'interface contract' => ['final class Wallet', 'final class Wallet implements External'];
        yield 'getter by reference' => ['function balance()', 'function &balance()'];
        yield 'static getter' => ['public function balance()', 'public static function balance()'];
        yield 'private getter' => ['public function balance()', 'private function balance()'];
        yield 'getter calculation' => ['return $this->balance;', 'return $this->balance + 1;'];
        yield 'setter calculation' => ['$this->balance = $value;', '$this->balance = $value + 1;'];
        yield 'setter validates' => ['$this->balance = $value;', 'if ($value < 0) { throw new \\RuntimeException; } $this->balance = $value;'];
        yield 'setter another field' => ['$this->balance = $value;', '$this->limit = $value;'];
        yield 'setter two writes' => ['$this->balance = $value;', '$this->balance = $value; $this->limit = $value;'];
        yield 'setter by reference' => ['int $value', 'int &$value'];
        yield 'setter variadic' => ['int $value', 'int ...$value'];
        yield 'setter private' => ['public function setBalance', 'private function setBalance'];
        yield 'setter static' => ['public function setBalance', 'public static function setBalance'];
        yield 'readonly state' => ['private int $balance', 'private readonly int $balance'];
        yield 'unknown state type' => ['private int $balance', 'private $balance'];
        yield 'nullable numeric state' => ['private int $balance', 'private ?int $balance'];
        yield 'object state' => ['private int $balance', 'private Number $balance'];
        yield 'undeclared state' => ['private int $balance = 100;', ''];
    }

    public function testFinalAccessorsFluentSetterAndPromotedNumericStateAreSupported(): void
    {
        $owner = str_replace('final class Wallet', 'class Wallet', $this->wallet());
        $owner = str_replace('public function', 'final public function', $owner);
        $owner = str_replace('private int $balance = 100;', 'public function __construct(private int $balance = 100) {}', $owner);
        $owner = str_replace('setBalance(int $value): void { $this->balance = $value; }', 'setBalance(int $value): self { $this->balance = $value; return $this; }', $owner);
        $this->assertCount(1, $this->findings([$owner, ...$this->consumers('$wallet->setBalance($wallet->balance() - $amount);')]));
    }

    public function testFloatAndNumericUnionContractsAreSupported(): void
    {
        foreach (['float', 'int|float'] as $type) {
            $owner = str_replace('int', $type, $this->wallet());
            $consumers = array_map(fn (string $source): string => str_replace('int $amount', $type.' $amount', $source), $this->consumers('$wallet->setBalance($wallet->balance() - $amount);'));
            $this->assertCount(1, $this->findings([$owner, ...$consumers]));
        }
    }

    public function testThresholdCountsDistinctConsumersAndKeepsDifferentFormulasSeparate(): void
    {
        $consumers = $this->consumers('$wallet->setBalance($wallet->balance() - $amount);');
        $this->assertSame([], $this->findings([$this->wallet(), $consumers[0], $consumers[1]]));
        $this->assertCount(1, $this->findings([$this->wallet(), $consumers[0], $consumers[1]], new RepeatedForeignStateUpdate(minConsumers: 2)));
        $this->assertSame([], $this->findings([$this->wallet(), ...$consumers], new RepeatedForeignStateUpdate(minConsumers: 4)));
        $repeated = str_replace('$wallet->setBalance($wallet->balance() - $amount);', str_repeat('$wallet->setBalance($wallet->balance() - $amount);', 3), $consumers[0]);
        $this->assertSame([], $this->findings([$this->wallet(), $repeated]));
        foreach ([[' - $amount', ' + $amount'], [' - $amount', ' - 2']] as [$search, $replacement]) {
            $this->assertSame([], $this->findings([$this->wallet(), $consumers[0], $consumers[1], str_replace($search, $replacement, $consumers[2])]));
        }

        $this->assertSame([], $this->findings([$this->wallet(), $this->wallet(), ...$consumers]));
        $this->assertSame([], $this->findings($consumers));
    }

    public function testInputSharingTypesAndOperandOrderRemainSignificant(): void
    {
        $one = $this->consumers('$wallet->setBalance($wallet->balance() - ($amount + $amount));');
        $two = str_replace('$amount + $amount', '$amount + $extra', $one[2]);
        $two = str_replace('int $amount', 'int $amount, int $extra', $two);
        $this->assertSame([], $this->findings([$this->wallet(), $one[0], $one[1], $two]));
        $differentType = str_replace('int $amount', 'float $amount', $one[2]);
        $this->assertSame([], $this->findings([$this->wallet(), $one[0], $one[1], $differentType]));
        $differentOrder = str_replace('$wallet->balance() - ($amount + $amount)', '($amount + $amount) - $wallet->balance()', $one[2]);
        $this->assertSame([], $this->findings([$this->wallet(), $one[0], $one[1], $differentOrder]));
    }

    public function testRegistrationAndInitEnableStateUpdate(): void
    {
        $rule = new RepeatedForeignStateUpdate;
        $definition = RuleCatalog::definitions()[$rule->id()];
        $this->assertTrue($definition['standard'] ?? true);
        $this->assertSame('warning', $definition['severity']);
        $this->assertArrayHasKey($rule->id(), RuleCatalog::standardDefinitions());
        $this->assertStringContainsString('new RepeatedForeignStateUpdate(', SodaInitFileEmitter::emit());
    }

    public function testInvalidThresholdFailsImmediately(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RepeatedForeignStateUpdate(minConsumers: 1);
    }

    public function testFactsAreOptionalAndContainNoAstObjects(): void
    {
        $files = $this->files([$this->wallet(), ...$this->consumers('$wallet->setBalance($wallet->balance() - $amount);')]);

        try {
            $collector = new FactCollector;
            $this->assertSame([], $collector->collect($files[1], [])->metrics['ownerDesign']);
            $facts = $collector->collect($files[1], ['ownerDesign']);
            $this->assertCount(1, $facts->metrics['ownerDesign']['updates']);
            $evidence = $facts->metrics['ownerDesign'];
            array_walk_recursive($evidence, function (mixed $value): void {
                $this->assertIsNotObject($value);
            });
        } finally {
            array_map(unlink(...), $files);
        }
    }

    public function testNamedOwnerOperationPreservesBehaviorAndRemovesTheFinding(): void
    {
        $directory = __DIR__.'/../fixtures/design/';
        require_once $directory.'state-update-before.php';
        require_once $directory.'state-update-after.php';
        $before = new Wallet;
        $after = new \StateUpdateAfter\Wallet;
        foreach (['checkout', 'renewal', 'withdrawal'] as $operation) {
            $beforeOperation = 'StateUpdateBefore\\'.$operation;
            $afterOperation = 'StateUpdateAfter\\'.$operation;
            $beforeOperation($before, 15);
            $afterOperation($after, 15);
            $this->assertSame($before->balance(), $after->balance());
        }

        $config = Soda::configure()->with([new RepeatedForeignStateUpdate]);
        $this->assertCount(1, (new Runner)->check([$directory.'state-update-before.php'], $config)->violations);
        $this->assertCount(0, (new Runner)->check([$directory.'state-update-after.php'], $config)->violations);
    }

    private function wallet(): string
    {
        return <<<'PHP'
<?php namespace Domain;
final class Wallet {
    private int $balance = 100;
    private int $limit = 200;
    public function balance(): int { return $this->balance; }
    public function limit(): int { return $this->limit; }
    public function touch(): int { return ++$this->balance; }
    public function setBalance(int $value): void { $this->balance = $value; }
}
PHP;
    }

    private function consumers(string $statement): array
    {
        return array_map(fn (string $name): string => "<?php namespace App;\nfunction ".$name."(\\Domain\\Wallet \$wallet, int \$amount, \\Domain\\Wallet \$other): void {\n".$statement."\n}", ['first', 'second', 'third']);
    }

    private function findings(array $sources, ?RepeatedForeignStateUpdate $rule = null): array
    {
        $files = $this->files($sources);

        try {
            return (new Runner)->check($files, Soda::configure()->with([$rule ?? new RepeatedForeignStateUpdate]))->violations->all();
        } finally {
            array_map(unlink(...), $files);
        }
    }

    private function files(array $sources): array
    {
        return array_map(function (string $source): string {
            $file = tempnam(sys_get_temp_dir(), 'soda-update-');
            $this->assertIsString($file);
            file_put_contents($file, $source);

            return $file;
        }, $sources);
    }
}
