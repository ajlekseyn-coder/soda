# no_catch_all_exceptions

📝 Make broad `catch (Throwable)` / `catch (Exception)` blocks end with an explicit outcome: return a value, rethrow, or translate the failure.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A broad catch that neither returns a value nor throws silently continues as if nothing failed. Callers cannot distinguish success from a swallowed failure, and invariant violations turn into corrupted downstream state. The rule reports catch blocks for `Throwable` or `Exception` (specific exception types are allowed) whose body has no explicit outcome, and advises returning an explicit fallback value, propagating, or translating the failure instead of silently continuing.

## Examples

```php
// ❌ Logs and continues — the caller cannot tell success from failure
try {
    $this->reserve($invoice);

    return 'reserved:'.$invoice;
} catch (Throwable $error) {
    report($error);
}

// ✅ Explicit fallback value
try {
    $this->reserve($invoice);

    return 'reserved:'.$invoice;
} catch (Throwable $error) {
    report($error);

    return null;
}
```

```php
// ❌ Empty catch
try {
    work();
} catch (Exception $error) {
}

// ✅ Cleanup, then rethrow or translate
try {
    work();
} catch (Exception $error) {
    cleanup();

    throw new ServiceException('Work failed', 0, $error);
}
```

```php
// ✅ Accepted: every branch of the conditional outcome is explicit
try {
    work();
} catch (Throwable $error) {
    if (recoverable($error)) {
        return null;
    }

    throw $error;
}
```

```php
// ✅ Accepted: a specific exception type may log and continue
try {
    work();
} catch (RuntimeException $error) {
    report($error);
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoCatchAllExceptions;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoCatchAllExceptions(),
    ]);
```

## Related rules

- [max_empty_catch_blocks](max_empty_catch_blocks.md) — caps the number of empty catch bodies per callable.
- [no_redundant_rethrow](no_redundant_rethrow.md) — flags catch blocks that only rethrow what they caught.
- [no_error_suppression](no_error_suppression.md) — flags the `@` operator, the other common way of hiding failures.
