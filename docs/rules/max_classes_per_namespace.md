# max_classes_per_namespace

📝 Limits the number of classes and traits that share one namespace.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A namespace holding dozens of types stops being a meaningful grouping: readers scan an undifferentiated list of names, and unrelated concerns pile up under one umbrella. The rule evaluates the project after all declarations are collected and reports each namespace whose type count exceeds the limit. It advises splitting the namespace into cohesive domain capabilities whose names explain their responsibility.

## Examples

```php
// ❌ one dumping-ground namespace
namespace App\Support; // 16+ classes and traits: validators, helpers, DTOs, services...
```

```php
// ✅ namespaces named after a capability
namespace App\Billing\Invoicing;   // Invoice, InvoiceLine, InvoiceRepository
namespace App\Billing\Payments;    // Payment, PaymentGateway, PaymentReceipt
namespace App\Shipping;            // Shipment, Carrier, DeliveryEstimate
```

Counting happens after all declarations are collected and is exact per namespace, across all analyzed files: classes and traits declared anywhere under `App\Support` add up (traits count like classes). Nested namespaces such as `App\Support\Sub` are counted separately. At the default `limit` of `15`, the ❌ case fires from the 16th type in the namespace.

## Options

- `limit` (default `15`): maximum accepted number of types per namespace. A non-positive limit disables the check.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxClassesPerNamespace;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxClassesPerNamespace(15),
    ]);
```

## Related rules

- [max_namespace_depth](max_namespace_depth.md) — bounds how deep the namespace tree goes, the horizontal counterpart of this rule.
- [max_classes_per_file](max_classes_per_file.md) — caps the number of types sharing a file.
