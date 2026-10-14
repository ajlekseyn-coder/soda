# prefer_continue

📝 Reports trailing loop conditions that wrap the remainder of the iteration body.

💼 This rule is enabled in the ✅ `standard` config.

## Why

When the last statement of a loop body is an `if` without `else`, the interesting iterations sit one indentation level deeper without gaining an alternative branch. Negating the condition and ending the guarded branch with `continue` keeps uninteresting iterations cheap to skip and the main path unindented. The rule reports the trailing `if` when its body does not already end with `return`, `throw`, `break`, or `continue` — those exits already end the iteration, so an early `continue` would flatten nothing.

`else` and `elseif` branches keep both paths meaningful and stay outside the report, as does an `if` that is not the last statement of the loop body.

## Examples

```php
// ❌ the main path lives behind two levels
public function save(iterable $items): void
{
    foreach ($items as $item) {
        $this->mark($item);

        if ($item->isActive) {
            $this->store($item);
            $this->index($item);
        }
    }
}

// ✅ the skip is explicit, the main path is flat
public function save(iterable $items): void
{
    foreach ($items as $item) {
        if (! $item->isActive) {
            continue;
        }

        $this->mark($item);
        $this->store($item);
        $this->index($item);
    }
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\PreferContinue;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new PreferContinue(),
    ]);
```

## Related rules

- [max_control_nesting](max_control_nesting.md) — caps the nesting this rule removes.
- [prefer_combined_guards](prefer_combined_guards.md) — merges consecutive guards instead of wrapping the remainder.
- [no_else_branches](no_else_branches.md) — forbids the alternative branches this rule avoids by construction.
