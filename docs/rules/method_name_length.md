# method_name_length

📝 Keeps declared method names within inclusive length bounds so each method names one clear action.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A method name that needs many clauses (`fetchAndValidateAndStoreUserInvoiceData`) describes several operations at once and usually means the method itself does; splitting its responsibilities yields shorter, clearer names. A name shorter than the configured minimum is too cryptic to communicate intent (the default minimum is `1`, so short names are only reported when you raise it). The rule measures the byte length of every declared method name and reports names outside the bounds. Laravel migration hooks are exempt, because `up`, `down`, and similar names are fixed by the framework.

## Examples

```php
// ❌
final class Dispatcher
{
    public function fetchAndValidateAndStoreUserInvoiceData(): void {}
}

// ✅
final class Dispatcher
{
    public function deliver(): void {}
}
```

```php
// ❌ 36 characters exceed the default maximum of 32
public function convertAllPendingInvoicesToPdfFormat(): void {}

// ✅ within the default bounds
public function export(): void {}
```

## Options

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$min` | `int` | `1` | Minimum accepted identifier length, inclusive. |
| `$max` | `int` | `32` | Maximum accepted identifier length, inclusive. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Naming\MethodNameLength;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MethodNameLength(min: 1, max: 32),
    ]);
```

## Related rules

- [class_name_length](class_name_length.md) — applies the same bounds to class-like names.
- [variable_name_length](variable_name_length.md) — applies the same idea to local identifiers.
- [boolean_methods_without_prefix](boolean_methods_without_prefix.md) — requires predicate prefixes on boolean-returning methods.
