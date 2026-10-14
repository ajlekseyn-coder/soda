# no_unneeded_ternary

📝 Reports full ternaries whose branches are opposite boolean literals.

💼 This rule is enabled in the ✅ `standard` config.

## Why

`$ready ? true : false` restates the condition itself; the ternary adds syntax and a second evaluation question where a cast or a negation says the same thing. The rule reports full ternaries selecting `true`/`false` literals in opposite order — same literals on both sides and non-literal branches stay accepted — and suggests `(bool) (condition)` for `$cond ? true : false` and `! (condition)` for `$cond ? false : true`. Preserve boolean conversion and evaluate the condition once when removing opposite literal branches; drop the cast only when the condition is known to return bool.

## Examples

```php
// ❌ the ternary restates the condition
$result = isReady() ? true : false;
$inverse = $unknown ? false : true;

// ✅ conversion and negation say it directly
$result = (bool) isReady();
$inverse = ! $unknown;
```

```php
// ✅ accepted: non-literal branches and matching literals
$price = $unknown ? 1 : 0;
$state = $unknown ? true : true;
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoUnneededTernary;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoUnneededTernary,
    ]);
```

## Related rules

- [no_nested_ternary](no_nested_ternary.md) — flags decisions hidden inside the result branches of a ternary.
- [no_identical_branches](no_identical_branches.md) — flags ternary branches that produce the same expression.
- [boolean_methods_without_prefix](boolean_methods_without_prefix.md) — keeps the predicates feeding such conditions clearly named.
