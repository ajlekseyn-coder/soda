# no_repeated_type_dispatch

📝 Centralize repeated dispatch on the same type or discriminator family behind one operation boundary.

💼 This rule is enabled in the ✅ `standard` config.

## Why

When two or more methods of the same class each dispatch on the same type set (`instanceof` checks, `match` on `::class`, `get_class` switches) or the same scalar discriminator family, adding a new variant means hunting down every dispatch site. The rule groups identical dispatch families per class and reports them when they appear in at least `minMethods` distinct methods, and advises centralizing the type-specific operation instead of repeating the dispatch. Dispatch inside a single method does not count, and nested callables do not contribute to their owner.

## Examples

```php
// ❌ The A|B family is dispatched in two methods
final class Handler
{
    public function print(object $item): void
    {
        if ($item instanceof A) {}
        if ($item instanceof B) {}
    }

    public function save(object $other): void
    {
        if ($other instanceof B) {}
        if ($other instanceof A) {}
    }
}
```

```php
// ❌ Equivalent class selectors across match/switch count as one family
final class Handler
{
    public function print(object $item): int
    {
        return match ($item::class) {
            Domain\First::class => 1,
            Domain\Second::class => 2,
        };
    }

    public function save(object $item): void
    {
        switch (get_class($item)) {
            case Domain\First::class: break;
            case Domain\Second::class: break;
        }
    }
}
```

```php
// ❌ Repeated scalar discriminator family
final class Handler
{
    public function print(object $item): string
    {
        return match ($item->kind) {
            'pdf' => $this->printPdf($item),
            'csv' => $this->printCsv($item),
        };
    }

    public function save(object $item): string
    {
        return match ($item->kind) {
            'csv' => $this->saveCsv($item),
            'pdf' => $this->savePdf($item),
        };
    }
}
```

## Options

| Option | Default | Description |
| --- | --- | --- |
| `minMethods` | `2` | Minimum number of distinct methods that must dispatch the same family before it is reported. Must be at least `2`. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoRepeatedTypeDispatch;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoRepeatedTypeDispatch(minMethods: 3),
    ]);
```

## Related rules

- [no_mode_parameters](no_mode_parameters.md) — flags a parameter that selects distinct operations, the data-driven sibling of type dispatch.
- [no_dynamic_invocation](no_dynamic_invocation.md) — flags runtime-selected callables, the other way of hiding which operation will run.
