# no_error_suppression

📝 Reports the `@` error-suppression operator.

💼 This rule is enabled in the ✅ `standard` config.

## Why

The `@` operator turns errors and warnings into invisible failures: the surrounding code keeps running with whatever partial state the failed call left behind, and the caller has no way to tell success from a suppressed problem. The rule reports every `@` expression and advises handling the failure, translating it into a typed error, or deliberately propagating it instead of hiding it.

## Examples

```php
// ❌
public function load(): string
{
    $value = @file_get_contents(__DIR__.'/optional.txt');

    return $value === false ? 'fallback' : $value;
}

// ✅ Handle the failure explicitly
public function load(): string
{
    $value = file_get_contents(__DIR__.'/optional.txt');

    return $value === false ? 'fallback' : $value;
}
```

```php
// ❌
@unlink($temporaryFile);

// ✅ Translate the failure into a typed error
if (unlink($temporaryFile) === false) {
    throw new StorageException("Could not remove {$temporaryFile}");
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoErrorSuppression;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoErrorSuppression(),
    ]);
```

## Related rules

- [no_catch_all_exceptions](no_catch_all_exceptions.md) — flags broad catch blocks that silently continue, the exception-based cousin of `@`.
- [max_empty_catch_blocks](max_empty_catch_blocks.md) — caps the number of empty catch bodies per callable.
