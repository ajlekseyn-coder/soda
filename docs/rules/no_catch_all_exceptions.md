# no_catch_all_exceptions

📝 Make broad `catch (Throwable)` / `catch (Exception)` blocks end with an explicit outcome: return a value, throw, or terminate the process.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A broad catch that neither returns a value, throws, nor terminates the process silently continues as if nothing failed. Callers cannot distinguish success from a swallowed failure, and invariant violations turn into corrupted downstream state. The rule reports catch blocks for `Throwable` or `Exception` (specific exception types are allowed) whose body has no explicit outcome, and advises returning an explicit fallback value, propagating, or translating the failure instead of silently continuing.

Native `exit` and `die` are explicit process boundaries and are accepted. Creating
a closure that might exit later does not terminate the catch. Unreachable
statements after an unconditional outcome do not replace that outcome. A nested
`try` with no `finally` also qualifies when its normal path and every catch
return a value, throw, or terminate the process. Nested blocks containing jumps
are still checked conservatively because a jump can bypass the apparent outcome.

A sole static call can also supply an outcome when the selected source declares
one exact public static method whose body cannot return normally. A native `never`
contract or an unconditional native exit or throw supplies that proof. PHPDoc
alone, generator bodies, dynamic dispatch and ambiguous declarations do not.

A catch may also define its fallback in one ordinary local assignment and use
that unchanged local in the immediately following return. The return may carry
the local directly, in a native array value, or in an explicitly named new
object's arguments. Other arguments must be local values, literals or native
arrays. Intervening statements, conditional definitions, calls or nested
allocations in the return expression, references, colliding array keys and an
enclosing `finally` leave this path unproved. A fallback that is then discarded
does not supply an outcome.

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

### Exact cleanup contracts

Some cleanup methods deliberately preserve an earlier application failure even
when cancellation or error reporting fails. Declare these boundaries explicitly:

```php
new NoCatchAllExceptions(contracts: [
    'App\\Clients\\ServiceClient::endCallbackScope',
    'App\\Clients\\ServiceClient::attemptCleanup',
]);
```

Contracts match the exact declaring class and method, case-insensitively like PHP
method identities. Wildcards are not supported. Neighboring methods, methods in
other classes, overridden methods in subclasses, and nested anonymous-class
methods remain checked. The empty default contract list preserves normal checks.
Only this rule is affected; other checks still analyze the method.
