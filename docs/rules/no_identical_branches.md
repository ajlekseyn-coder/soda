# no_identical_branches

📝 Reports full ternary branches and match arms that produce the same expression.

💼 This rule is enabled in the ✅ `standard` config.

## Why

When two branches of a decision return exactly the same result, the decision itself is in question: either a condition is redundant, or the arms were meant to differ. The rule compares the printed syntax of full ternary branches and explicit match arms, ignoring formatting and comments, and reports each duplicated group at the second occurrence. Default match arms and shorthand ternaries (`?:`, `??`) stay outside the comparison. Consider consolidating identical results — while preserving condition evaluation, match selection and unmatched-value behavior.

## Examples

```php
// ❌ both branches send the same call
$result = $ready ? send($message) : send($message);

// ✅ the decision is gone
send($message);
```

```php
// ❌ three arms with one shared body
$result = match ($channel) {
    1 => send($message),
    2 => send($message),
    3 => send($message),
    default => queue($message),
};

// ✅ grouped conditions keep one body
$result = match ($channel) {
    1, 2, 3 => send($message),
    default => queue($message),
};
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoIdenticalBranches;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoIdenticalBranches,
    ]);
```

## Related rules

- [no_nested_ternary](no_nested_ternary.md) — flags decisions hidden inside the branches this rule compares.
- [no_unneeded_ternary](no_unneeded_ternary.md) — removes ternaries whose branches are opposite boolean literals.
- [no_repeated_type_dispatch](no_repeated_type_dispatch.md) — targets the statement-level version of repeated dispatch bodies.
