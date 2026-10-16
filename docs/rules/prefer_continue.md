# prefer_continue

📝 Reports trailing conditions that add a level around nested control flow.

💼 This rule is enabled in the ✅ `standard` config.

## Why

An early `continue` can flatten nested work inside a loop. The rule reports a
trailing `if` without alternatives only when its body contains another executable
control structure in the same scope. Flat work is accepted, whether it contains
one call or several. Multiline calls and callback bodies do not add nesting here.
Branches that already exit on all proven paths need no extra guard. Ambiguous
`goto` and `finally` cases are left alone.

Keep preceding statements before the new guard. Preserve evaluation order and
`break`/`continue` levels. An `if` does not introduce a loop or switch level.

A conditional append or `unset()` can stay in a positive branch. Two related
operations, such as collecting and removing a driver, are also flat work; the
author may use a guard without requiring the same form for every loop. A search
that returns its first match already exits and does not need an inverted guard.

## Examples

```php
// ❌ nested work behind a trailing condition
foreach ($items as $item) {
    $mark($item);

    if ($item['active']) {
        foreach ($item['parts'] as $part) {
            $store($part);
        }
    }
}
```

```php
// ✅ mark still runs for every item
foreach ($items as $item) {
    $mark($item);

    if (! $item['active']) {
        continue;
    }

    foreach ($item['parts'] as $part) {
        $store($part);
    }
}
```

```php
// ✅ both flat styles are accepted
foreach ($items as $item) {
    if ($item->active) {
        store($item);
    }
}

foreach ($items as $item) {
    if (! $item->active) {
        continue;
    }

    store($item);
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\PreferContinue;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([new PreferContinue]);
```

## Related rules

- [max_control_nesting](max_control_nesting.md) measures nesting depth.
- [no_else_branches](no_else_branches.md) reports redundant alternatives after exits.
