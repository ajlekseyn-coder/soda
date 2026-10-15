# max_commented_out_code_lines

📝 Reports source code that has been disabled by turning it into comments.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Commented-out code is dead weight that still looks alive: readers must parse it, wonder why it is there, and decide whether it is safe to delete. Version control already preserves every previous state of the file, so keeping the code as a comment adds risk without adding information. The rule reports each comment line that contains code and advises deleting dead code and trusting version control; preserve an explanation only when it documents why.

Only ordinary PHP comments are inspected. Docblocks, text inside string literals and inline examples enclosed in backticks within explanatory prose remain documentation. A reported line must parse as PHP syntax: a statement, declaration or an expression fragment such as an array item or chained call. Unfinished argument lists and declaration or control headers can continue on following comment lines. Words such as `class`, `interface` and `function` inside explanatory prose do not establish disabled code.

Findings belong to the source file, with the original physical comment line and no callable or type owner. Their `value` is the one-based position among that file's findings; the threshold is zero. Removing a prose false positive can therefore renumber later findings without changing their source locations or messages.

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
// We will resolve the class name before invoking it.
// call $plan->setKey() if setKey method exists
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
