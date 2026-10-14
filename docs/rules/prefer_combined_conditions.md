# prefer_combined_conditions

📝 Reports two simple nested conditions that guard the same block.

💼 This rule is enabled in the ✅ `standard` config.

## Why

When an `if` contains only another `if`, two simple questions can share one block
without the extra indentation. Combine them as `(outer) && (inner)`: evaluate the
outer condition first and the inner condition only when the outer one is truthy.
Parentheses preserve each original expression's precedence. PHP's low-precedence
`and` operator is not the suggested replacement.

Both conditions must be simple predicates, values or comparisons supported by
Soda's condition vocabulary. Compound boolean logic and nested calculations remain
accepted so this check does not demand increasingly long conditions. Either
`else` or `elseif`, additional work in the outer block, an empty inner block or
comments attached to the inner `if` also keep the two blocks separate.

Each matching pair produces one finding at the inner `if` line, with `value: 1`
and `threshold: 0`. Nested callables are inspected independently. The rule makes
no automatic source edits. Keep a meaningful domain distinction when separate
steps communicate more clearly; fewer nesting levels alone do not prove that a
rewrite improves comprehension.

## Examples

```php
// ❌ two blocks guard the same operation
if (isReady()) {
    if (isValid()) {
        save();
    }
}

// ✅ one block expresses the combined decision
if (isReady() && isValid()) {
    save();
}
```

```php
// ✅ accepted: preparation belongs to the outer branch
if (isReady()) {
    prepare();

    if (isValid()) {
        save();
    }
}

// ✅ accepted: an explanation preserves a separate decision
if (isReady()) {
    // Permission is checked separately for auditing.
    if (hasPermission()) {
        save();
    }
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\PreferCombinedConditions;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new PreferCombinedConditions,
    ]);
```

## Related rules

- [prefer_combined_guards](prefer_combined_guards.md) — combines adjacent guards with the same exit using `||`.
- [no_complex_control_conditions](no_complex_control_conditions.md) — keeps mixed decisions and nested computations explicit.
- [max_control_nesting](max_control_nesting.md) — measures nesting beyond this specific simplification.
