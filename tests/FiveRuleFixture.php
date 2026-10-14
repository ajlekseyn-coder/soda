<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\FactCollector;
use Cosmira\Soda\Contracts\Check;

final class FiveRuleFixture
{
    public static function findings(Check $rule, string $source): array
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-five-rule-');
        file_put_contents($path, $source);

        try {
            $facts = (new FactCollector)->collect($path, $rule->requiredAnalyses());

            return iterator_to_array($rule->checkFile($facts));
        } finally {
            unlink($path);
        }
    }
}
