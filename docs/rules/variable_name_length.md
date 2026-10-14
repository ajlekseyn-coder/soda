# variable_name_length

📝 Keeps variable names within inclusive length bounds to avoid both cryptic abbreviations and sentence-like identifiers.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A one-character name like `$x` forces the reader to reconstruct meaning from usage, while a sentence-length name like `$resultOfProcessingThePendingInvoiceRows` clutters every line it appears on. A concise domain name in between reads fastest. The rule measures the byte length of every declared variable name and reports names outside the bounds. `$this` and superglobals are always exempt, and dynamic variable names (`$$name`) are not treated as declarations.

## Examples

```php
// ❌
$x = 1;
$variableNameThatDescribesTheWholeCalculation = 2;

// ✅
$attempt = 1;
$totalRetries = 2;
```

```php
// ❌ Too short to carry meaning (with min: 3).
foreach ($rows as $r) {}

// ✅
foreach ($rows as $row) {}
```

## Options

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$min` | `int` | `2` | Minimum accepted identifier length, inclusive. |
| `$max` | `int` | `16` | Maximum accepted identifier length, inclusive. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Naming\VariableNameLength;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new VariableNameLength(min: 2, max: 16),
    ]);
```

## Related rules

- [method_name_length](method_name_length.md) — applies the same bounds to method names.
- [avoid_redundant_naming](avoid_redundant_naming.md) — removes words already supplied by the enclosing context.
