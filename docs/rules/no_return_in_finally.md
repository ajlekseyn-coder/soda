# no_return_in_finally

📝 Forbids `return` statements executed inside `finally` blocks.

💼 This rule is enabled in the ✅ `standard` config.

## Why

In PHP a return inside `finally` discards the pending outcome of the `try` or `catch`: it replaces an already computed return value and even suppresses a thrown exception. The rule reports every explicit return belonging to a `finally` in the same lexical callable — returns inside nested closures, functions or anonymous classes belong to those callables and are not attributed to the surrounding cleanup. Keep cleanup in `finally` and return from the normal control flow.

## Examples

```php
// ❌ the finally return replaces the computed result
function load(): string
{
    try {
        return 'original';
    } finally {
        return 'replacement'; // 'original' is silently lost
    }
}

// ✅ cleanup runs, the outcome stands
function load(): string
{
    try {
        return 'original';
    } finally {
        cleanup();
    }
}
```

```php
// ❌ the finally return also swallows the exception
function run(): string
{
    try {
        throw new RuntimeException('failed');
    } finally {
        return 'replacement'; // the exception never surfaces
    }
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoReturnInFinally;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoReturnInFinally,
    ]);
```

## Related rules

- [no_empty_finally_blocks](no_empty_finally_blocks.md) — flags finally blocks that carry no cleanup at all.
- [no_error_suppression](no_error_suppression.md) — targets the broader pattern of silencing failures.
- [max_try_catch_blocks](max_try_catch_blocks.md) — bounds how many try blocks one method may carry.
