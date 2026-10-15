# no_complex_control_conditions

📝 Requires explicit grouping when a condition mixes logical families.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Mixing AND (`&&`, `and`), OR (`||`, `or`) and XOR (`xor`) without grouping makes
readers reconstruct PHP precedence. Parenthesize each child expression that uses
a different logical family. Parentheses around the whole condition are not enough.
Keep the original evaluation order: changing the grouping can change behavior.

Fluent receivers, calls in arguments, comparisons, ternaries and `match` are
accepted. The rule does not require a temporary boolean or a named query.
Callback bodies are visited independently. A lone XOR and homogeneous chains are
accepted. When source positions are unavailable, absence of grouping cannot be
proven and the rule stays silent.

## Examples

```php
// ❌ implicit precedence
if ($active && $verified || $admin) {
    $grant();
}
```

```php
// ✅ the same expression with its existing precedence made explicit
if (($active && $verified) || $admin) {
    $grant();
}
```

`$active && ($verified || $admin)` is also clearly grouped, but means something
different. It is not an equivalent replacement for the first example.

```php
// ✅ natural predicates do not need intermediary variables
if ($data->parentGroups()->isNotEmpty()) {
    attachParents();
}

if ($user->hasRole(config('auth.admin_role'))) {
    grantAccess();
}

// ✅ rebuild the query and reevaluate the changing name on every iteration
while (Person::query()->where('samaccountname', $name)->exists()) {
    $name = nextName();
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoComplexControlConditions;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([new NoComplexControlConditions]);
```

## Related rules

- [max_boolean_conditions](max_boolean_conditions.md) bounds the number of operands.
- [no_assignment_in_condition](no_assignment_in_condition.md) reports hidden writes.
- [no_nested_ternary](no_nested_ternary.md) handles nested ternary expressions separately.
