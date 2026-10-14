# no_redundant_rethrow

📝 Reviews catch blocks whose only executable statement is rethrowing their bound exception.

💼🚫 This rule is **not** enabled by default. Add it to your `soda.php` config to enable it.

## Why

A final catch that only rethrows its bound variable adds a handler without changing anything; it usually survives from an older design where the block did real work. The rule reports the last catch of a try statement when its only statement is `throw $boundVariable` — comments and empty statements are ignored. Earlier catches are deliberately excluded because a narrow rethrow can shield a later broad handler. This is a review signal, not an automatically safe deletion: keep logging, translation, variable bindings and shielding of later handlers intact when simplifying.

## Examples

```php
// ❌ the final catch adds nothing
try {
    work();
} catch (Exception $error) {
    throw $error;
}

// ✅ the handler is removed; the exception propagates anyway
try {
    work();
} catch (Exception $error) {
    recover($error);
}
```

```php
// ✅ accepted: a narrow rethrow shielding a later broad handler
try {
    work();
} catch (RuntimeException $error) {
    throw $error;
} catch (Exception $error) {
    recover($error);
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoRedundantRethrow;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoRedundantRethrow,
    ]);
```

## Related rules

- [no_empty_finally_blocks](no_empty_finally_blocks.md) — flags finally blocks without executable cleanup.
- [max_try_catch_blocks](max_try_catch_blocks.md) — bounds how many try blocks one method may carry.
- [no_catch_all_exceptions](no_catch_all_exceptions.md) — targets overly broad exception handlers.
