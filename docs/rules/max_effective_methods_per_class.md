# max_effective_methods_per_class

📝 Limits the method count of a class including the methods contributed by its traits.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Moving methods into traits does not shrink a class; it only hides its size from simple metrics. Readers, reviewers and testers still face the same callable surface, split across files. The rule counts the complete method set of each class — its own concrete methods plus all methods contributed by composed traits, with duplicates and trait diamonds deduplicated — and reports classes above the limit. Keep the complete owning class within its method limit; trait extraction does not reduce this measurement.

## Examples

```php
// ❌ 42 effective methods: 4 declared here, 38 contributed by the trait
trait HandlesBilling
{
    public function billCustomer(): void {}
    public function applyTax(): void {}
    public function applyDiscount(): void {}
    public function roundTotal(): void {}
    public function splitLines(): void {}
    public function mergeLines(): void {}
    public function validateTotals(): void {}
    public function recalculateTotals(): void {}
    public function formatCurrency(): void {}
    public function resolveCurrency(): void {}
    public function applyExchangeRate(): void {}
    public function applyRounding(): void {}
    public function detectDuplicates(): void {}
    public function normalizeLines(): void {}
    public function prorateDiscount(): void {}
    public function prorateTax(): void {}
    public function applyCredit(): void {}
    public function applyDebit(): void {}
    public function capturePayment(): void {}
    public function releasePayment(): void {}
    public function voidPayment(): void {}
    public function refundPayment(): void {}
    public function schedulePayment(): void {}
    public function cancelPayment(): void {}
    public function linkOrder(): void {}
    public function unlinkOrder(): void {}
    public function resolveCustomer(): void {}
    public function resolveAddress(): void {}
    public function resolveSeller(): void {}
    public function resolveBuyer(): void {}
    public function snapshotTotals(): void {}
    public function restoreSnapshot(): void {}
    public function markPaid(): void {}
    public function markOverdue(): void {}
    public function markSent(): void {}
    public function markDraft(): void {}
    public function archive(): void {}
    public function logHistory(): void {}
}

final class InvoiceService
{
    use HandlesBilling;

    public function total(): int {}
    public function tax(): int {}
    public function net(): int {}
    public function gross(): int {} // 42 effective methods total
}
```

```php
// ✅ the composed surface stays within the limit
final class InvoiceService
{
    use HandlesBilling;

    public function total(): int {}
}
```

## Options

- `limit` (default `40`): maximum accepted effective method count per class, counted after composing traits and deduplicating method names. The limit must be positive.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxEffectiveMethodsPerClass;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxEffectiveMethodsPerClass(40),
    ]);
```

## Related rules

- [max_methods_per_class](max_methods_per_class.md) — bounds the declared method count without trait composition.
- [max_effective_class_length](max_effective_class_length.md) — applies the same composition-aware counting to source lines.
- [max_traits_per_class](max_traits_per_class.md) — bounds the number of traits a class composes.

