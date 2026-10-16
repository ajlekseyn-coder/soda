<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Tests\ParsesPhpSnippets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaxControlNestingTest extends TestCase
{
    use ParsesPhpSnippets;

    #[DataProvider('pipelineRegressions')]
    public function testPipelinePreservesValidCodeAndDetectsControl(string $valid, string $invalid): void
    {
        $this->assertRulePipeline('max_control_nesting', $valid, $invalid);
    }

    public static function pipelineRegressions(): iterable
    {
        yield 'Prompts: else is an alternative, not another nesting level' => [
            'function map($callback, array $values) { try { if ($callback) {} else { foreach ($values as $value) { consume($value); } } } catch (Exception $error) {} }',
            'function map($callback, array $values) { try { if ($callback) {} else { foreach ($values as $value) { if ($value) { consume($value); } } } } catch (Exception $error) {} }',
        ];
        yield 'Alternative catch body starts at the same depth as try' => [
            'function handle() { try {} catch (Exception $error) { foreach (items() as $item) { if ($item) { consume($item); } } } }',
            'function handle() { try {} catch (Exception $error) { foreach (items() as $item) { if ($item) { while (ready()) { consume($item); } } } } }',
        ];
    }
}
