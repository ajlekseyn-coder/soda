<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use PHPUnit\Framework\TestCase;

final class DocumentationSemanticsTest extends TestCase
{
    public function testAssignmentExamplePreservesEveryFalseyValueAndItsSingleRead(): void
    {
        [$before, $after] = $this->examples('no_assignment_in_condition');
        foreach ([null, false, 0, 0.0, '', '0', [], true, 1, 'ready', [1]] as $input) {
            $run = static function (string $code) use ($input): array {
                $trace = [];
                $next = static function () use (&$trace, $input): mixed {
                    $trace[] = 'read';

                    return $input;
                };
                $consume = static function ($value) use (&$trace): void {
                    $trace[] = ['consume', $value];
                };
                eval($code);

                return $trace;
            };
            $expected = $input ? ['read', ['consume', $input]] : ['read'];
            self::assertSame($expected, $run($before));
            self::assertSame($expected, $run($after));
        }
    }

    public function testAssignmentLoopKeepsInitialStateAndUpdateOrder(): void
    {
        // Both loop examples are in one fence, separated by their explanatory comment.
        [, , $loops] = $this->examples('no_assignment_in_condition');
        [$before, $after] = explode('// ✅', $loops);
        $after = substr($after, strpos($after, "\n") + 1);
        foreach ([[0, [1, 2, -3]], [2, [1, -3]]] as [$initial, $steps]) {
            $run = static function (string $code) use ($initial, $steps): array {
                $offset = $initial;
                $trace = [];
                $step = static function () use (&$steps, &$trace): int {
                    $value = array_shift($steps);
                    $trace[] = ['step', $value];

                    return $value;
                };
                $consume = static function ($value) use (&$trace): void {
                    $trace[] = ['consume', $value];
                };
                eval($code);

                return [$offset, $trace];
            };
            self::assertSame($run($before), $run($after));
        }
    }

    public function testContinueExampleStillMarksInactiveItems(): void
    {
        [$before, $after] = $this->examples('prefer_continue');
        $run = static function (string $code): array {
            $items = [['active' => false, 'parts' => ['skip']], ['active' => true, 'parts' => ['one', 'two']]];
            $trace = [];
            $mark = static function ($item) use (&$trace): void {
                $trace[] = ['mark', $item['active']];
            };
            $store = static function ($part) use (&$trace): void {
                $trace[] = ['store', $part];
            };
            eval($code);

            return $trace;
        };
        $expected = [['mark', false], ['mark', true], ['store', 'one'], ['store', 'two']];
        self::assertSame($expected, $run($before));
        self::assertSame($expected, $run($after));
    }

    public function testGroupingExamplePreservesTruthTableAndShortCircuitCalls(): void
    {
        [$before, $after] = $this->examples('no_complex_control_conditions');
        foreach ([false, true] as $active) {
            foreach ([false, true] as $verified) {
                foreach ([false, true] as $admin) {
                    $run = static function (string $code) use ($active, $verified, $admin): array {
                        $trace = [];
                        $predicate = static function (string $name, bool $value) use (&$trace): bool {
                            $trace[] = $name;

                            return $value;
                        };
                        $grant = static function () use (&$trace): void {
                            $trace[] = 'grant';
                        };
                        $code = str_replace(['$active', '$verified', '$admin'], [
                            '$predicate("active", $active)', '$predicate("verified", $verified)', '$predicate("admin", $admin)',
                        ], $code);
                        eval($code);

                        return $trace;
                    };
                    $expected = ['active'];
                    if ($active) {
                        $expected[] = 'verified';
                    }
                    if (! ($active && $verified)) {
                        $expected[] = 'admin';
                    }
                    if (($active && $verified) || $admin) {
                        $expected[] = 'grant';
                    }
                    self::assertSame($expected, $run($before));
                    self::assertSame($expected, $run($after));
                }
            }
        }
    }

    private function examples(string $rule): array
    {
        $source = file_get_contents(__DIR__.'/../../docs/rules/'.$rule.'.md');
        preg_match_all('/```php\n(.*?)\n```/s', $source, $matches);
        // The assignment guide has before/after in the same fence.
        if ($rule === 'no_assignment_in_condition') {
            [$before, $after] = explode('// ✅', $matches[1][0]);
            $after = substr($after, strpos($after, "\n") + 1);

            return [$before, $after, $matches[1][1]];
        }

        return $matches[1];
    }
}
