# namespace_name_length

📝 Keeps each namespace segment within inclusive length bounds so namespaces stay short domain terms.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A namespace segment such as `InfrastructureAndExternalServiceIntegration` describes organizational structure rather than a domain capability, and long segments make every use statement and FQCN noisy. Segments shorter than the minimum (`App\X\Domain`) add no distinction. The rule checks each segment of a declared namespace separately — not the whole qualified name — and reports any segment outside the bounds. Short domain terms keep the namespace a meaningful address for the capability it groups.

## Examples

```php
// ❌
namespace App\X\Domain;

final class Invoice {}

// ✅ Every segment is a short, meaningful domain term.
namespace App\Billing\Domain;

final class Invoice {}
```

```php
// ❌ A sentence instead of a domain term.
namespace Billing\CustomerAccountManagementAndInvoicingServices;

// ✅
namespace Billing\Invoicing;
```

## Options

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$min` | `int` | `3` | Minimum accepted segment length, inclusive. |
| `$max` | `int` | `32` | Maximum accepted segment length, inclusive. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Naming\NamespaceNameLength;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NamespaceNameLength(min: 3, max: 32),
    ]);
```

## Related rules

- [class_name_length](class_name_length.md) — applies the same bounds to class-like names.
- [avoid_redundant_naming](avoid_redundant_naming.md) — removes words that namespaces already supply to class names.
