<?php

declare(strict_types=1);

namespace Cosmira\Soda\Commands;

use Cosmira\Soda\Config\RuleCatalog;
use Illuminate\Console\Command;

final class ListRulesCommand extends Command
{
    /**
     * Console signature accepted by the list-rules command.
     */
    protected $signature = 'list:rules';

    /**
     * Summary shown by `soda list`.
     */
    protected $description = 'List built-in quality rules';

    /**
     * Execute the console command and return its exit status.
     */
    public function handle(): int
    {
        $rows = [];

        foreach (RuleCatalog::definitions() as $id => $row) {
            $row['id'] = $id;

            $rows[] = [
                $row['id'],
                $row['section'],
                $row['severity'],
                ($row['standard'] ?? true) ? 'standard' : 'opt-in',
                $row['default'],
                $row['label'],
                $row['advice'],
            ];
        }

        $this->table(['Rule id', 'Section', 'Severity', 'Selection', 'Default', 'Label', 'How to improve'], $rows);

        return self::SUCCESS;
    }
}
