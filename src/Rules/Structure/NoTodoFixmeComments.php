<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Structure;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;

final class NoTodoFixmeComments extends Check
{
    /**
     * Return the stable identifier used in diagnostics.
     */
    #[\Override]
    public function id(): string
    {
        return 'max_todo_fixme_comments';
    }

    /**
     * Request source comment facts before this rule runs.
     *
     * @return list<string>
     */
    #[\Override]
    public function requiredAnalyses(): array
    {
        return ['comments'];
    }

    /**
     * Report recorded maintenance markers remaining in source comments at their original source locations.
     *
     * @return iterable<Violation>
     */
    #[\Override]
    public function checkFile(FileFacts $file): iterable
    {
        foreach ($file->metrics['todoFixme'] ?? [] as $index => $entry) {
            yield new Violation($this->id(), $file->path, $index + 1, 0,
                line: $entry['line'] ?? null,
                message: sprintf('%s comment: %s', $entry['kind'] ?? 'TODO', $entry['text'] ?? ''),
            );
        }
    }
}
