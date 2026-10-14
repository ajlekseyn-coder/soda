# max_todo_fixme_comments

📝 Reports TODO and FIXME comments left in source code.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A TODO or FIXME comment is an anonymous promise: it records that someone intended to do something, without an owner, a deadline or a discussion thread. Such markers accumulate silently and mislead readers into assuming the surrounding code is final. The rule reports every recorded maintenance marker at its source location. Resolve the debt now or track it externally with context; do not leave an anonymous promise in the code.

## Examples

```php
// ❌ unfinished work parked in the source
public function retry(Job $job): void
{
    // TODO remove fallback once the queue is stable
    $this->queue->push($job);
}

public function handle(Event $event): void
{
    // FIXME handle retry errors
    $this->handler->dispatch($event);
}
```

```php
// ✅ the work is done, or tracked with context outside the code
public function retry(Job $job): void
{
    $this->queue->push($job);
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\NoTodoFixmeComments;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoTodoFixmeComments(),
    ]);
```

## Related rules

- [max_commented_out_code_lines](max_commented_out_code_lines.md) — flags the other common way of parking unfinished work in comments.
- [no_consecutive_block_comments](no_consecutive_block_comments.md) — flags walls of comments that often accumulate around deferred work.

