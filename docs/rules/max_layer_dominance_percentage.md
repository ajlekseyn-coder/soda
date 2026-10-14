# max_layer_dominance_percentage

📝 Reports directories where one architectural role dominates beyond a configured percentage without the directory being named for that role.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A feature directory where most classes are named `*Service` or `*Controller` suggests the code is organized by technical layer instead of by capability, which scatters one feature across the codebase. The rule classifies each class by its name suffix, aggregates roles per directory, and reports directories where the dominant non-plain role exceeds the threshold while the directory name does not already carry that role. This is a naming and directory convention, not a dependency analysis: inspect actual responsibilities and call paths, because renaming a folder alone does not improve architecture.

## Examples

```text
// ❌ a feature directory dominated by Service-named classes
src/Order/
    PlaceOrder.php          // plain
    OrderValidator.php      // plain
    PricingService.php      // Service
    NotificationService.php // Service
    AuditService.php        // Service
```

```text
// ✅ the directory is an intentional layer, or roles are mixed by design
src/Service/
    PricingService.php
    NotificationService.php
    AuditService.php
```

## Options

- `threshold` (default `50`): minimum percentage of files carrying the dominant role before a directory is reported. A non-positive threshold disables the check.
- `min_files` (default `4`): minimum number of PHP files in a directory before its roles are evaluated, so small directories are not judged on noise.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxLayerDominancePercentage;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxLayerDominancePercentage(50, 4),
    ]);
```

## Related rules

- [max_namespace_depth](max_namespace_depth.md) — discourages namespaces that mirror the directory tree.
- [max_classes_per_namespace](max_classes_per_namespace.md) — bounds how many types share one namespace.

