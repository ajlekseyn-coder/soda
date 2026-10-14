# no_nested_ternary

📝 Reports a full ternary used directly as a result branch of another full ternary.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Nested ternaries hide a second decision inside the result of the first, and without parentheses the reader must know PHP's left-associative ternary evaluation to guess which branch produces what. The rule reports each outer decision once when a full ternary appears in one of its result branches; shorthand fallbacks (`?:`, `??`), ternaries inside the condition, inside call arguments and inside closures stay accepted. Name the inner decision or use explicit control flow, keeping evaluation order intact.

## Examples

```php
// ❌ the annual decision hides inside the premium branch
$price = $premium ? ($annual ? 100 : 10) : 20;

// ✅ the inner decision gets a name
$annualPrice = $annual ? 100 : 10;
$price = $premium ? $annualPrice : 20;
```

```php
// ✅ accepted: shorthand fallbacks and separate decisions
$price = $premium ?: 20;
$first = $a ?? $b ?? $c;
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoNestedTernary;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoNestedTernary,
    ]);
```

## Related rules

- [no_unneeded_ternary](no_unneeded_ternary.md) — removes ternaries whose branches are opposite boolean literals.
- [no_identical_branches](no_identical_branches.md) — flags ternary branches that produce the same expression.
- [max_boolean_conditions](max_boolean_conditions.md) — bounds the operand count when several decisions share one condition.
