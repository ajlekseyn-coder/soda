<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Naming\AvoidRedundantNaming;
use Cosmira\Soda\Tests\CheckFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AvoidRedundantNamingTest extends TestCase
{
    public function testUsesConfiguredSimilarityThresholdInViolationLimits(): void
    {
        $violations = CheckFixture::forRule(null, $this->context(['avoid_redundant_naming' => 90]));

        $this->assertCount(1, $violations);
        $this->assertSame('avoid_redundant_naming', $violations[0]->rule);
        $this->assertSame('/project/src/File.php', $violations[0]->file);
        $this->assertSame(10, $violations[0]->line);
        $this->assertSame('PostItemCollection', $violations[0]->class);
        $this->assertSame(['value' => 100, 'threshold' => 90], ['value' => $violations[0]->value, 'threshold' => $violations[0]->threshold]);
        $this->assertSame('Redundant naming: PostItemCollection → PostCollection (100%)', $violations[0]->message);
    }

    public function testReturnsNoViolationsWhenRuleIsDisabled(): void
    {
        $violations = CheckFixture::forRule(null, $this->context([]));

        $this->assertTrue($violations->isEmpty());
    }

    #[DataProvider('namingBoundaries')]
    public function testNamesKeepNativeContractsAndDistinctDeclaredTargets(string $declarations, string $target, int $expected, ?array $methods = null): void
    {
        $root = sys_get_temp_dir().'/soda-naming-boundaries-'.bin2hex(random_bytes(6));
        mkdir($root);
        $declaration = $root.'/Declarations.php';
        $subject = $root.'/Subject.php';
        $control = $root.'/Control.php';
        file_put_contents($declaration, '<?php namespace App; '.$declarations);
        file_put_contents($subject, '<?php namespace App; '.$target);
        file_put_contents($control, '<?php namespace App; class GenuinePostCollection { public function clearGenuinePost() {} }');

        try {
            foreach ([[new AvoidRedundantNaming], RuleCatalog::standard()] as $checks) {
                foreach ([[$declaration, $subject, $control], [$control, $subject, $declaration]] as $paths) {
                    $result = (new Runner)->check($paths, $checks);
                    self::assertSame([], $result->violations->filter(static fn ($row): bool => $row->rule === 'parse_error')->all());
                    $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'avoid_redundant_naming')->values();
                    $genuine = $findings->filter(static fn ($row): bool => $row->file === $control)->values();
                    self::assertCount(1, $genuine);
                    self::assertSame('App\GenuinePostCollection', $genuine[0]->class);
                    self::assertSame('clearGenuinePost', $genuine[0]->method);
                    self::assertSame(1, $genuine[0]->line);
                    self::assertSame(100, $genuine[0]->value);
                    self::assertSame(80, $genuine[0]->threshold);
                    self::assertSame('Redundant naming: clearGenuinePost → clear (100%)', $genuine[0]->message);
                    $subjectFindings = $findings->filter(static fn ($row): bool => $row->file === $subject)->values();
                    self::assertCount($expected, $subjectFindings);
                    if ($methods !== null) {
                        self::assertSame($methods, $subjectFindings->map(static fn ($row): ?string => $row->method)->all());
                    }
                    self::assertCount($expected + 1, $findings);
                }
            }
        } finally {
            unlink($declaration);
            unlink($subject);
            unlink($control);
            rmdir($root);
        }
    }

    public static function namingBoundaries(): iterable
    {
        yield 'implemented interface fixes the name' => ['interface Required { public function clearPost(); }', 'class PostCollection implements Required { public function clearPost() {} }', 0];
        yield 'source parent fixes override name' => ['class Base { public function clearPost() {} }', 'class PostCollection extends Base { public function clearPost() {} }', 0];
        yield 'abstract trait requirement fixes implementing name' => ['trait Required { abstract public function clearPost(); }', 'class PostCollection { use Required; public function clearPost() {} }', 0];
        yield 'existing public destination cannot be redeclared' => ['', 'class PostCollection { public function clear() {} public function clearPost() {} }', 0];
        yield 'destination names are case insensitive' => ['', 'class PostCollection { public function CLEAR() {} public function clearPost() {} }', 0];
        yield 'private destination still occupies its method name' => ['', 'class PostCollection { private function clear() {} public function clearPost() {} }', 0];
        yield 'existing class destination cannot be redeclared' => ['class PostCollection {}', 'class PostItemCollection {}', 0];
        yield 'fallback does not supply an all collection marker' => ['', 'class Settings { public function getFallbackLocale() { return "en"; } }', 0];
        yield 'callback does not supply an all collection marker' => ['', 'class Settings { public function getCallback() { return fn () => null; } }', 0];
        yield 'untyped fluent return is not a collection contract' => ['', 'class Settings { public function allOnQueue() { return $this; } }', 0];
        yield 'untyped predicate is not a collection contract' => ['', 'class Settings { public function allAreActive() { return true; } }', 0];
        yield 'whole listener token is not list collection marker' => ['', 'class Settings { public function getListeners(): array { return []; } }', 0];
        yield 'native scalar return is not a collection contract' => ['', 'class Settings { public function allString(): string { return \'records\'; } }', 0];
        yield 'visible inherited destination is already occupied' => ['class Base { public function clear($input) {} }', 'class PostCollection extends Base { public function clearPost() {} }', 0];
        yield 'private parent destination does not occupy child scope' => ['class Base { private function clear($input) {} }', 'class PostCollection extends Base { public function clearPost() {} }', 1];
        yield 'owned repeated class context remains reported' => ['', 'class PostCollection { public function clearPost() {} }', 1];
        yield 'explicit collection marker remains reported' => ['', 'class Settings { public function getAllRecords(): array { return []; } }', 1];
        yield 'unrelated complete contract does not fix another name' => ['interface Required { public function touch(); }', 'class PostCollection implements Required { public function touch() {} public function clearPost() {} }', 1];
        yield 'unresolved native interface cannot prove a free iterable rename' => ['', 'class Settings implements UnknownContract { public function listContents(): iterable { return []; } }', 0];
        yield 'known interface fixes the iterable method name' => ['interface Required { public function listContents(): iterable; }', 'class Settings implements Required { public function listContents(): iterable { return []; } }', 0];
        yield 'standalone native iterable marker remains reported' => ['', 'class Settings { public function listContents(): iterable { return []; } }', 1];
        yield 'anonymous method and following method keep their direct owner' => ['interface Required { public function clearPost(): void; }', 'class PostCollection implements Required { public function clearPost(): void {} public function nested() { return new class { public function clearPost(): void {} }; } public function removePost(): void {} }', 1, ['removePost']];
        yield 'abstract inherited destination remains available to an implementation' => ['interface Required { public function clear($input): void; }', 'abstract class PostCollection implements Required { public function clearPost(): void {} }', 1, ['clearPost']];
        yield 'route type does not make the middleware action redundant' => ['class Route {}', 'class State { public function attachMiddlewareToRoute(Route $route): void {} }', 0];
        yield 'type input does not make the scope return role redundant' => ['class Type {}', 'class Scope { public function addReturnType(Type $returnType, int $lineNumber): void {} }', 0];
        yield 'resolved type alias does not make the method return role redundant' => ['class Type {}', 'use App\\Type as TypeContract; class MethodResult { public function addReturnType(TypeContract $type, int $lineNumber): void {} }', 0];
        yield 'complete method prefix of a concrete input type remains redundant' => ['class MethodResult {}', 'class Result { public function addMethod(MethodResult $method): void {} }', 1, ['addMethod']];
        yield 'complete user prefix of a concrete input type remains redundant' => ['class UserProfileData {}', 'class State { public function addUser(UserProfileData $user): void {} }', 1, ['addUser']];
        yield 'complete union member prefix remains redundant' => ['class PaymentMethod {}', 'class Customer { public function addPaymentMethod(PaymentMethod|string $paymentMethod): void {} }', 1, ['addPaymentMethod']];
        yield 'full parameter suffix and class context remain redundant' => ['class Post {}', 'class PostCollection { public function addPost(Post $post): void {} }', 1, ['addPost']];
        yield 'native override attribute remains respected' => ['class Base { public function clearPost() {} }', 'class PostCollection extends Base { #[\Override] public function clearPost() {} }', 0];
    }

    /**
     * @param array<string, int> $rules
     */
    private function context(array $rules): array
    {
        $core = [
            '/project/src/File.php' => [
                'file_loc'      => 10,
                'classes_count' => 1,
                'classes'       => [],
                'methods'       => [],
                'namespaces'    => [],
                'naming'        => [
                    'classes' => [
                        ['class' => 'App\PostItemCollection', 'line' => 10],
                    ],
                    'methods' => [],
                ],
            ],
        ];

        $fileMetrics = $core;

        return [CheckFixture::checks($rules, []), $fileMetrics];
    }
}
