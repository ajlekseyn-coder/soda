# no_mode_parameters

📝 Reports parameters whose value selects between distinct operations inside a callable.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A parameter used as a mode selector turns one callable into several: readers must trace every branch to know what `$mode` actually triggers, and each new operation grows the dispatch instead of the interface. The rule analyses control flow per parameter and reports one finding per parameter whose value dispatches distinct operations — via `switch`, `match`, or `if` chains — and advises exposing separate named operations instead.

## Examples

```php
// ❌ One parameter, two different operations
function execute(string $mode): void
{
    switch ($mode) {
        case 'new':
            create();
            break;
        case 'old':
            update();
            break;
    }
}

// ✅ Separate named operations
final class ReportService
{
    public function create(ReportDraft $draft): Report {}

    public function update(Report $report): Report {}
}
```

```php
// ✅ Accepted: the selector picks data, not distinct operations
return match ($locale) {
    'en' => 'Hello',
    'fr' => 'Bonjour',
};
```

A branch that only throws a constructed exception validates the selector; it does not add a working operation. Calls evaluated before the throw or inside the exception's constructor arguments still count, as do exception factory calls. Returning a constructed object remains an operation.

```php
// ✅ One supported operation with input validation
return match ($protocol) {
    'pusher' => makePusherRouter(),
    default => throw new InvalidArgumentException('Unsupported protocol'),
};
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoModeParameters;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoModeParameters(),
    ]);
```

## Related rules

- [no_boolean_parameters](no_boolean_parameters.md) — flags boolean flags that select behavior the same way.
- [no_repeated_type_dispatch](no_repeated_type_dispatch.md) — flags the same dispatch pattern repeated across methods rather than inside one.
