<?php

declare(strict_types=1);

namespace Cosmira\Soda\Verification;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Design\FragmentedOwnerBehavior;
use Cosmira\Soda\Rules\Design\RepeatedConfigurationSequence;
use Cosmira\Soda\Rules\Design\RepeatedForeignStateDecision;
use Cosmira\Soda\Rules\Design\RepeatedForeignStateUpdate;
use Cosmira\Soda\Rules\Design\RepeatedOwnerConcern;
use Cosmira\Soda\Rules\Design\ScatteredOwnerConcern;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[Group('performance')]
final class OwnerDesignPerformanceTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testOptionalDesignRulesReleaseAstAndStayWithinProjectBudgets(): void
    {
        $directory = sys_get_temp_dir().'/soda-owner-performance-'.uniqid();
        mkdir($directory, 0700);
        $files = [];
        for ($index = 0; $index < 300; $index++) {
            $file = $directory.'/Owner'.$index.'.php';
            file_put_contents($file, '<?php class Owner'.$index.' { private bool $archived = false; public function archive(): void { $this->archived = true; } public function restore(): void { $this->archived = false; } public function isActive(): bool { return ! $this->archived; } }');
            $files[] = $file;
        }

        $config = Soda::configure()->with([
            new FragmentedOwnerBehavior,
            new RepeatedOwnerConcern,
            new ScatteredOwnerConcern,
            new RepeatedConfigurationSequence,
            new RepeatedForeignStateDecision,
            new RepeatedForeignStateUpdate,
        ]);
        $before = memory_get_usage(true);
        $start = hrtime(true);

        try {
            $result = (new Runner)->check($files, $config->checks());
            $this->assertCount(1, $result->violations);
            $this->assertSame(300, $result->violations->first()->value);
            $this->assertLessThan(8.0, (hrtime(true) - $start) / 1_000_000_000);
            $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        } finally {
            array_map(unlink(...), $files);
            rmdir($directory);
        }
    }

    #[RunInSeparateProcess]
    public function testRepeatedStateUpdatesStayWithinProjectBudgets(): void
    {
        $directory = sys_get_temp_dir().'/soda-update-performance-'.uniqid();
        mkdir($directory, 0700);
        $owner = $directory.'/Wallet.php';
        file_put_contents($owner, '<?php final class Wallet { private int $balance = 100; public function balance(): int { return $this->balance; } public function setBalance(int $value): void { $this->balance = $value; } }');
        $files = [$owner];
        for ($index = 0; $index < 300; $index++) {
            $file = $directory.'/Consumer'.$index.'.php';
            file_put_contents($file, '<?php function consumer'.$index.'(Wallet $wallet, int $amount): void { $wallet->setBalance($wallet->balance() - $amount); }');
            $files[] = $file;
        }

        $before = memory_get_usage(true);
        $start = hrtime(true);

        try {
            $result = (new Runner)->check($files, Soda::configure()->with([new RepeatedForeignStateUpdate])->checks());
            $this->assertCount(1, $result->violations);
            $this->assertSame(300, $result->violations->first()->value);
            $this->assertLessThan(8.0, (hrtime(true) - $start) / 1_000_000_000);
            $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        } finally {
            array_map(unlink(...), $files);
            rmdir($directory);
        }
    }
}
