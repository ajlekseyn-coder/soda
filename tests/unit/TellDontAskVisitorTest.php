<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Analysis\Structure\TellDontAskVisitor;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Structure\NoAskThenTellPatterns;
use Cosmira\Soda\Tests\ParsesPhpSnippets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TellDontAskVisitorTest extends TestCase
{
    use ParsesPhpSnippets;

    private function parseAndCollect(string $code): array
    {
        $visitor = new TellDontAskVisitor();
        $this->traversePhpFile($code, $visitor);

        return $visitor->violations();
    }

    public function testCollectsAskThenTellPattern(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class Order {
    public function finalize(): void {
        if ($this->customer->isActive()) {
            $this->customer->notify();
        }
    }
}
PHP;

        $result = $this->parseAndCollect($code);

        $this->assertCount(1, $result);
        $this->assertSame(5, $result[0]['line']);
        $this->assertSame('$this->customer', $result[0]['receiver']);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('notify', $result[0]['command']);
        $this->assertSame('App\Order::finalize', $result[0]['method']);
    }

    public function testIgnoresWhenCommandTargetsDifferentReceiver(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class Order {
    public function finalize(Customer $customer, Logger $logger): void {
        if ($customer->isActive()) {
            $logger->info('active');
        }
    }
}
PHP;

        $this->assertSame([], $this->parseAndCollect($code));
    }

    // ---------------- Новые тесты ----------------

    public function testMultipleConditions(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class Order {
    public function finalize(): void {
        if ($this->customer->isActive() && $this->customer->hasEmail()) {
            $this->customer->sendEmail();
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('sendEmail', $result[0]['command']);
    }

    public function testElseCondition(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class Payment {
    public function process(): void {
        if ($this->account->isVerified()) {
            $this->account->debit(100);
        } else {
            $this->account->notifyUnverified();
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(2, $result);
        $this->assertSame('isVerified', $result[0]['question']);
        $this->assertSame('debit', $result[0]['command']);
        $this->assertSame('notifyUnverified', $result[1]['command']);
    }

    public function testIgnoresWhenQuestionAndCommandTargetDifferentObjectsViaChain(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class Cart {
    public function checkout(): void {
        if ($this->user->profile()->isAdult()) {
            $this->user->allowPurchase();
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertSame([], $result);
    }

    public function testLocalVariableQuestion(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class Invoice {
    public function send(): void {
        $active = $customer->isActive();
        if ($active) {
            $customer->sendInvoice();
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('sendInvoice', $result[0]['command']);
        $this->assertSame('$customer', $result[0]['receiver']);
    }

    public function testIgnoresPlainAssignmentsWithoutCrashing(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class Example {
    public function render(object $user, string $line): void {
        $line = trim($line);
        if ($user->isActive()) {
            $user->notify();
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('notify', $result[0]['command']);
    }

    public function testNestedConditions(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class Subscription {
    public function renew(): void {
        if ($user->isActive()) {
            if ($user->hasValidPaymentMethod()) {
                $user->charge();
            }
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(2, $result);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('charge', $result[1]['command']);
    }

    public function testMultipleCommandsAfterQuestion(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class Order {
    public function finalize(): void {
        if ($customer->isActive()) {
            $customer->notify();
            $customer->logActivity();
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(2, $result);
        $this->assertSame('notify', $result[0]['command']);
        $this->assertSame('logActivity', $result[1]['command']);
    }

    public function testLoopWithQuestion(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class Group {
    public function notifyAll(): void {
        foreach ($members as $member) {
            if ($member->isSubscribed()) {
                $member->sendNotification();
            }
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('isSubscribed', $result[0]['question']);
        $this->assertSame('sendNotification', $result[0]['command']);
    }

    public function testControllerArgumentQuestion(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Http\Controllers;
class UserController {
    public function updateProfile(User $user): void {
        if ($user->isActive()) {
            $user->sendProfileUpdateEmail();
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('$user', $result[0]['receiver']);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('sendProfileUpdateEmail', $result[0]['command']);
    }

    public function testMultipleObjectsQuestion(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class Order {
    public function finalize(): void {
        if ($customer->isActive()) {
            $customer->notify();
        }
        if ($supplier->isAvailable()) {
            $supplier->ship();
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(2, $result);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('notify', $result[0]['command']);
        $this->assertSame('isAvailable', $result[1]['question']);
        $this->assertSame('ship', $result[1]['command']);
    }

    public function testMethodWithReturnQuestion(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class Report {
    public function generate(): string {
        if ($user->isAdmin()) {
            $user->notifyAdmin();
        }
        return 'done';
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('isAdmin', $result[0]['question']);
        $this->assertSame('notifyAdmin', $result[0]['command']);
    }

    public function testChainedMethodCalls(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class Order {
    public function finalize(): void {
        if ($order->getCustomer()->isActive()) {
            $order->getCustomer()->notify();
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('$order->getCustomer()', $result[0]['receiver']);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('notify', $result[0]['command']);
    }

    public function testLoopWithPropertyAccess(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class OrdersProcessor {
    public function process(): void {
        foreach ($orders as $order) {
            if ($order->customer->isActive()) {
                $order->customer->notify();
            }
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('$order->customer', $result[0]['receiver']);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('notify', $result[0]['command']);
    }

    public function testStaticMethodCalls(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class CustomerHelper {
    public function notifyCustomer(): void {
        if (Customer::isActive($customerId)) {
            Customer::notify($customerId);
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('Customer', $result[0]['receiver']);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('notify', $result[0]['command']);
    }

    public function testLogicalExpressionInIf(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class UserManager {
    public function notify(): void {
        if ($user->isActive() || $user->isAdmin()) {
            $user->notify();
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('$user', $result[0]['receiver']);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('notify', $result[0]['command']);
    }

    public function testTernaryOperator(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class UserManager {
    public function notify(): void {
        $user->isActive() ? $user->notify() : null;
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('$user', $result[0]['receiver']);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('notify', $result[0]['command']);
    }

    public function testClosureFunctionCall(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class UserManager {
    public function notifyAll(array $users): void {
        array_map(fn($u) => $u->isActive() ? $u->notify() : null, $users);
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertSame([], $result);
    }

    public function testObjectMayManageItsOwnState(): void
    {
        foreach ([
            'if ($this->isStale()) { $this->destroy(); }',
            'if (self::isStale()) { self::destroy(); }',
            'if (static::isStale()) { static::destroy(); }',
        ] as $body) {
            self::assertSame([], $this->parseAndCollect('<?php class File { function log() { '.$body.' } }'));
        }
    }

    public function testConsumedConditionalValuesDoNotProveCommands(): void
    {
        foreach ([
            'return $user->hasName() ? $user->name() : null;',
            '$value = $user->hasName() ? $user->name() : null;',
            'consume($user->hasName() ? $user->name() : null);',
            '$value = ["name" => $user->hasName() ? $user->name() : null];',
        ] as $body) {
            self::assertSame([], $this->parseAndCollect('<?php function read($user) { '.$body.' }'));
        }
    }

    public function testVariableAssignmentThenCommand(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class UserManager {
    public function notify(User $user): void {
        $active = $user->isActive();
        $active && $user->notify();
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('$user', $result[0]['receiver']);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('notify', $result[0]['command']);
    }

    public function testGuardedDataForwardingDoesNotProveStateDecision(): void
    {
        foreach ([
            '$settings = $engine->configure($input); if ($settings) { $engine->update($settings); }',
            '$settings = $engine->configure($input); $settings && $engine->update(settings: $settings);',
            '$settings = $engine->configure($input); if ($settings) { $engine->update($settings); } if ($engine->isReady()) { $other->update(); }',
        ] as $body) {
            self::assertSame([], $this->parseAndCollect('<?php function sync($engine, $other, $input) { '.$body.' }'));
        }
    }

    public function testForwardingExclusionAppliesOnlyToTheMatchingCommand(): void
    {
        $result = $this->parseAndCollect('<?php function sync($engine, $input) {
            $settings = $engine->configure($input);
            if ($settings) { $engine->update($settings); $engine->reset(); }
        }');
        self::assertCount(1, $result);
        self::assertSame('reset', $result[0]['command']);
    }

    public function testReadingPartOfAResultDoesNotForwardTheResult(): void
    {
        $result = $this->parseAndCollect('<?php function sync($engine) {
            $state = $engine->state();
            if ($state) { $engine->update($state["enabled"]); }
        }');
        self::assertCount(1, $result);
    }

    public function testCreatingAFirstClassCallableDoesNotExecuteACommand(): void
    {
        self::assertSame([], $this->parseAndCollect('<?php function sync($engine) {
            if ($engine->isReady()) { $engine->update(...); }
        }'));
    }

    public function testCreatingAFirstClassCallableDoesNotAskAQuestion(): void
    {
        $source = '<?php ';
        foreach ([
            'if ($service->isReady(...)) { $service->notify(); }',
            '$ready = $service->isReady(...); if ($ready) { $service->notify(); }',
            '$ready = $service->isReady(...); $alias = $ready; if ($alias) { $service->notify(); }',
        ] as $index => $body) {
            $function = 'function target'.$index.'($service) { '.$body.' }';
            self::assertSame([], $this->parseAndCollect('<?php '.$function));
            $source .= $function;
        }
        $source .= 'function genuine($service) { if ($service->isReady()) { $service->notify(); } }';
        $path = tempnam(sys_get_temp_dir(), 'soda-callable-question-');
        self::assertIsString($path);
        file_put_contents($path, $source);

        try {
            foreach ([[new NoAskThenTellPatterns], RuleCatalog::standard()] as $checks) {
                $result = (new Runner)->check([$path], $checks);
                self::assertSame([], $result->violations->filter(static fn ($row): bool => $row->rule === 'parse_error')->all());
                $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'max_ask_then_tell_patterns')->values();
                self::assertCount(1, $findings);
                self::assertSame('genuine', $findings[0]->method);
            }
        } finally {
            unlink($path);
        }
    }

    public function testEscapedIndexedRootDoesNotCompareUnknownReceiverKeys(): void
    {
        $source = <<<'PHP'
<?php
function target(array &$services) {
    if ($services['first']->isReady()) { $services['first']->notify(); }
}
function genuine(array $services) {
    if ($services['first']->isReady()) { $services['first']->notify(); }
}
PHP;
        $path = tempnam(sys_get_temp_dir(), 'soda-escaped-receiver-');
        self::assertIsString($path);
        file_put_contents($path, $source);

        try {
            foreach ([[new NoAskThenTellPatterns], RuleCatalog::standard()] as $checks) {
                $result = (new Runner)->check([$path], $checks);
                self::assertSame([], $result->violations->filter(static fn ($row): bool => $row->rule === 'parse_error')->all());
                $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'max_ask_then_tell_patterns')->values();
                self::assertCount(1, $findings);
                self::assertSame('genuine', $findings[0]->method);
                self::assertSame('Asked $services[\'first\'] via isReady(), then told it via notify()', $findings[0]->message);
                self::assertStringNotContainsString("\0", $findings[0]->message);
            }
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('receiverIdentities')]
    public function testReceiverIdentity(string $question, string $command, int $expected): void
    {
        $result = $this->parseAndCollect('<?php const FIRST = "first"; const SECOND = "second"; function target($app, array $services, $key, $other, $event) { if ('.$question.') { '.$command.'; } }');
        self::assertCount($expected, $result);
        if ($expected !== 0) {
            self::assertSame('target', $result[0]['method']);
            self::assertSame('isReady', $result[0]['question']);
            self::assertSame('notify', $result[0]['command']);
            if (str_contains($question, '[')) {
                self::assertStringContainsString('[', $result[0]['receiver']);
            }
        }
    }

    #[DataProvider('receiverIdentities')]
    public function testStandardRunnerPreservesReceiverIdentity(string $question, string $command, int $expected): void
    {
        $root = sys_get_temp_dir().'/soda-array-receivers-'.bin2hex(random_bytes(6));
        mkdir($root);
        $target = $root.'/Target.php';
        $control = $root.'/Control.php';
        file_put_contents($target, '<?php const FIRST = "first"; const SECOND = "second"; function target($app, array $services, $key, $other, $event) { if ('.$question.') { '.$command.'; } }');
        file_put_contents($control, '<?php function genuine($service) { if ($service->isReady()) { $service->notify(); } }');

        try {
            foreach ([[new NoAskThenTellPatterns], RuleCatalog::standard()] as $checks) {
                foreach ([[$target, $control], [$control, $target]] as $paths) {
                    $result = (new Runner)->check($paths, $checks);
                    self::assertSame([], $result->violations->filter(static fn ($row): bool => $row->rule === 'parse_error')->all());
                    $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'max_ask_then_tell_patterns')->values();
                    self::assertCount($expected + 1, $findings);
                    $genuine = $findings->filter(static fn ($row): bool => $row->method === 'genuine')->values();
                    self::assertCount(1, $genuine);
                    self::assertSame($control, $genuine[0]->file);
                    self::assertSame('Asked $service via isReady(), then told it via notify()', $genuine[0]->message);
                    self::assertSame(1, $genuine[0]->value);
                    self::assertSame(0, $genuine[0]->threshold);
                    self::assertSame(1, $genuine[0]->line);
                    $targets = $findings->filter(static fn ($row): bool => $row->method === 'target')->values();
                    self::assertCount($expected, $targets);
                    foreach ($targets as $finding) {
                        self::assertStringNotContainsString("\0", $finding->message);
                        if ($question === '$services[$key]->isReady()') {
                            self::assertSame('Asked $services[$key] via isReady(), then told it via notify()', $finding->message);
                        }
                    }
                }
            }
        } finally {
            unlink($target);
            unlink($control);
            rmdir($root);
        }
    }

    #[DataProvider('receiverBindingLoops')]
    public function testForeachReceiverBindings(string $body): void
    {
        $source = '<?php function target(array $services, $key) { '.$body.' } function genuine($service) { if ($service->isReady()) { $service->notify(); } }';
        $path = tempnam(sys_get_temp_dir(), 'soda-receiver-foreach-');
        self::assertIsString($path);
        file_put_contents($path, $source);

        try {
            foreach ([[new NoAskThenTellPatterns], RuleCatalog::standard()] as $checks) {
                $result = (new Runner)->check([$path], $checks);
                self::assertSame([], $result->violations->filter(static fn ($row): bool => $row->rule === 'parse_error')->all());
                $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'max_ask_then_tell_patterns')->values();
                self::assertCount(1, $findings);
                self::assertSame('genuine', $findings[0]->method);
                self::assertSame('Asked $service via isReady(), then told it via notify()', $findings[0]->message);
            }
        } finally {
            unlink($path);
        }
    }

    public static function receiverBindingLoops(): iterable
    {
        yield 'iterable is evaluated before key binding' => ['foreach (($ready = $services[$key]->isReady()) ? ["second" => 1] : [] as $key => $value) { if ($ready) { $services[$key]->notify(); } }'];
        yield 'later iteration can change key before loop exit' => ['$ready = null; foreach ($services as $key => $service) { if ($key === "second") { break; } $ready = $services[$key]->isReady(); } if ($ready) { $services[$key]->notify(); }'];
    }

    public static function receiverIdentities(): iterable
    {
        yield 'queried container commanded element' => ['$app->bound("events")', '$app["events"]->notify()', 0];
        yield 'queried element commanded container' => ['$app["events"]->isReady()', '$app->notify()', 0];
        yield 'different literal offsets' => ['$services["first"]->isReady()', '$services["second"]->notify()', 0];
        yield 'different integer offsets' => ['$services[0]->isReady()', '$services[1]->notify()', 0];
        yield 'different constant offsets' => ['$services[FIRST]->isReady()', '$services[SECOND]->notify()', 0];
        yield 'different class name offsets' => ['$services[First::class]->isReady()', '$services[Second::class]->notify()', 0];
        yield 'class name offset values preserve case' => ['$services[Service::class]->isReady()', '$services[service::class]->notify()', 0];
        yield 'different nested offsets' => ['$services["group"]["first"]->isReady()', '$services["group"]["second"]->notify()', 0];
        yield 'different nesting depths' => ['$services["group"]->isReady()', '$services["group"]["first"]->notify()', 0];
        yield 'property and indexed element' => ['$app->events->isReady()', '$app->events["nested"]->notify()', 0];
        yield 'different elements retain distinct property chains' => ['$services["first"]->owner->isReady()', '$services["second"]->owner->notify()', 0];
        yield 'dynamic offsets do not prove identical receivers' => ['$services[$key]->isReady()', '$services[$other]->notify()', 0];
        yield 'unchanged variable offset remains genuine' => ['$services[$key]->isReady()', '$services[$key]->notify()', 1];
        yield 'changed variable offset does not prove identical receivers' => ['$services[$key]->isReady()', '$key = $other; $services[$key]->notify()', 0];
        yield 'changed nested variable offset does not prove identical receivers' => ['$services[$key]["first"]->isReady()', '$key = $other; $services[$key]["first"]->notify()', 0];
        yield 'effectful offsets do not prove identical receivers' => ['$services[nextKey()]->isReady()', '$services[nextKey()]->notify()', 0];
        yield 'direct receiver remains genuine' => ['$app->isReady()', '$app->notify()', 1];
        yield 'same literal offset remains genuine' => ['$services["first"]->isReady()', '$services["first"]->notify()', 1];
        yield 'literal quote spelling preserves identity' => ['$services["first"]->isReady()', "\$services['first']->notify()", 1];
        yield 'same integer offset remains genuine' => ['$services[0]->isReady()', '$services[0]->notify()', 1];
        yield 'same nested offset remains genuine' => ['$services["group"]["first"]->isReady()', '$services["group"]["first"]->notify()', 1];
        yield 'same class name offset remains genuine' => ['$services[Service::class]->isReady()', '$services[Service::class]->notify()', 1];
        yield 'same property element remains genuine' => ['$app->events["first"]->isReady()', '$app->events["first"]->notify()', 1];
        yield 'different factory selector arguments' => ['$app->get("first")->isReady()', '$app->get("second")->notify()', 0];
        yield 'different factory selector methods' => ['$app->first()->isReady()', '$app->second()->notify()', 0];
        yield 'literal and class name factory selectors differ' => ['$app->make("config")->isReady()', '$app->make(Service::class)->notify()', 0];
        yield 'same literal factory selector remains genuine' => ['$app->get("first")->isReady()', '$app->get("first")->notify()', 1];
        yield 'effectful factory arguments do not prove identical receivers' => ['$app->get(nextKey())->isReady()', '$app->get(nextKey())->notify()', 0];
        yield 'different static factory methods' => ['Registry::first()->isReady()', 'Registry::second()->notify()', 0];
        yield 'different static factory arguments' => ['Registry::get("first")->isReady()', 'Registry::get("second")->notify()', 0];
        yield 'static class and returned service differ' => ['Registry::isReady()', 'Registry::get("first")->notify()', 0];
        yield 'same static factory selector remains genuine' => ['Registry::get("first")->isReady()', 'Registry::get("first")->notify()', 1];
        yield 'same static factory without arguments remains genuine' => ['Registry::first()->isReady()', 'Registry::first()->notify()', 1];
        yield 'effectful static factory arguments do not prove identical receivers' => ['Registry::get(nextKey())->isReady()', 'Registry::get(nextKey())->notify()', 0];
    }

    public function testIgnoresCommandsForDifferentReceiverAfterSingleQuestion(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class OrderProcessor {
    public function process(): void {
        if ($customer->isActive()) {
            $customer->notify();
            $supplier->ship();
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('$customer', $result[0]['receiver']);
        $this->assertSame('isActive', $result[0]['question']);
        $this->assertSame('notify', $result[0]['command']);
    }

    public function testInterfaceOrAbstractCall(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class PaymentProcessor {
    public function process(Payment $payment): void {
        if ($payment->getAccount()->isVerified()) {
            $payment->getAccount()->debit();
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('$payment->getAccount()', $result[0]['receiver']);
        $this->assertSame('isVerified', $result[0]['question']);
        $this->assertSame('debit', $result[0]['command']);
    }

    public function testMethodCallWithArguments(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class UserManager {
    public function grant(User $user): void {
        if ($user->hasRole('admin')) {
            $user->grantAccess('dashboard');
        }
    }
}
PHP;
        $result = $this->parseAndCollect($code);
        $this->assertCount(1, $result);
        $this->assertSame('$user', $result[0]['receiver']);
        $this->assertSame('hasRole', $result[0]['question']);
        $this->assertSame('grantAccess', $result[0]['command']);
    }

    public function testAnonymousClassAskThenTellDoesNotLeakIntoOuterMethod(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
class UserManager {
    public function build(): object {
        return new class {
            public function ignored(User $user): void {
                if ($user->isActive()) {
                    $user->notify();
                }
            }
        };
    }
}
PHP;

        $this->assertSame([], $this->parseAndCollect($code));
    }
}
