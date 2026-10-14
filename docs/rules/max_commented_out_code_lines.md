# max_commented_out_code_lines

📝 Reports source code that has been disabled by turning it into comments.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Commented-out code is dead weight that still looks alive: readers must parse it, wonder why it is there, and decide whether it is safe to delete. Version control already preserves every previous state of the file, so keeping the code as a comment adds risk without adding information. The rule reports each comment line that contains code and advises deleting dead code and trusting version control; preserve an explanation only when it documents why.

## Examples

```php
// ❌ disabled code kept as a comment
public function total(): int
{
    // $total = $this->price * $this->quantity;
    // return $total + $this->tax();
    return $this->price * $this->quantity;
}
```

```php
// ✅ delete the dead code; explain why where it matters
public function total(): int
{
    return $this->price * $this->quantity;
}
```

```php
// ✅ accepted: documentation, not disabled code
// Use `new Worker()` to start processing.
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\NoCommentedOutCode;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoCommentedOutCode(),
    ]);
```

## Related rules

- [max_todo_fixme_comments](max_todo_fixme_comments.md) — flags the other common way of parking unfinished work in comments.
- [no_consecutive_block_comments](no_consecutive_block_comments.md) — flags walls of comments that often accumulate around disabled code.
