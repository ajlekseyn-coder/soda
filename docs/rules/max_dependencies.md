# max_dependencies

📝 Limits the number of constructor dependencies of a class.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A constructor that accepts many collaborators is a sign that the class coordinates too many concerns: it must know when and how to use each one, and every test needs the full set of doubles. The rule counts the constructor parameters of each class and reports classes above the limit. Review which dependencies belong to the same operation; extract only an independently useful responsibility, because another forwarding service does not remove coupling.

## Examples

```php
// ❌ nine collaborators wired into one constructor
final class CheckoutService
{
    public function __construct(
        private CartRepository $carts,
        private ProductCatalog $catalog,
        private PricingCalculator $pricing,
        private DiscountResolver $discounts,
        private TaxCalculator $taxes,
        private PaymentGateway $payments,
        private InventoryReservation $inventory,
        private NotificationSender $notifications,
        private AuditLogger $audit,
    ) {}
}
```

```php
// ✅ pricing concerns move to an object with one clear operation
final class CheckoutService
{
    public function __construct(
        private CartRepository $carts,
        private OrderPricer $pricer,
        private PaymentGateway $payments,
        private NotificationSender $notifications,
    ) {}
}
```

## Options

- `limit` (default `8`): maximum accepted number of constructor parameters per class. A non-positive limit disables the check.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxDependencies;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxDependencies(8),
    ]);
```

## Related rules

- [max_efferent_coupling](max_efferent_coupling.md) — counts all distinct types a class references, not only constructor arguments.
- [no_dependency_cycles](no_dependency_cycles.md) — flags circular dependency paths between classes.
- [max_arguments](max_arguments.md) — bounds the parameter lists of individual methods.

