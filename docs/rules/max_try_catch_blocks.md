# max_try_catch_blocks

📝 Limits how many `try` blocks a single method may contain.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Each `try` block interleaves error recovery with the core behavior of the method, and several of them in one body mix unrelated recovery strategies. Failures are easier to reason about when they are caught at one boundary and translated once. The rule counts the `try` statements belonging to the method itself — try blocks inside nested closures or anonymous classes belong to those callables — and reports methods exceeding the limit.

## Examples

```php
// ❌ three try blocks in one method
function sync(User $user): void
{
    try {
        $profile = $this->api->profile($user);
    } catch (Timeout $e) {
        $this->log->warning($e);
    }

    try {
        $this->api->avatar($user);
    } catch (Timeout $e) {
        $this->log->warning($e);
    }

    try {
        $this->api->settings($user);
    } catch (Timeout $e) {
        $this->log->warning($e);
    }
}

// ✅ failures handled at one boundary
function sync(User $user): void
{
    try {
        $this->uploadAll($user);
    } catch (Timeout $e) {
        $this->log->warning($e);
    }
}
```

## Options

- `limit` (default `2`): maximum accepted number of try blocks per method. A non-positive limit disables the check.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\MaxTryCatchBlocks;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxTryCatchBlocks(2),
    ]);
```

## Related rules

- [no_empty_finally_blocks](no_empty_finally_blocks.md) — flags finally blocks that carry no cleanup at all.
- [no_redundant_rethrow](no_redundant_rethrow.md) — reviews catches whose only action is rethrowing the exception.
- [no_catch_all_exceptions](no_catch_all_exceptions.md) — targets overly broad exception handlers.
