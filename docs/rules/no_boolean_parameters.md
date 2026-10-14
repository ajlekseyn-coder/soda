# no_boolean_parameters

📝 Replace boolean parameters that select behavior with named methods or readonly value state.

💼🚫 This rule is **not** enabled by default. Add it to your `soda.php` config to enable it.

## Why

A boolean parameter forces every call site to be read as `send(true)` or `send(false)` to know what happens, and it usually means one callable performs two different jobs. The rule reports parameters declared as `bool`/`true`/`false` (including nullable and union types) and parameters with a boolean default, and advises separating behavior selected by a mode flag into named methods. Boolean state is data: the rule recognizes direct readonly initialization and does not treat it as a flag. Initialization counts as readonly state when the parameter is promoted onto a `readonly` property or into a `readonly` class, or when the constructor assigns it directly to a declared `readonly` property. A promoted `bool` on a mutable class, or an assignment to a property that is not declared `readonly`, is still reported.

## Examples

```php
// ❌ The caller cannot see what the flag does
final readonly class Sender
{
    public function send(bool $audit): void {}
}

(new Sender())->send(true);

// ✅ Separate the behaviors
final readonly class Sender
{
    public function send(): void {}

    public function sendWithAudit(): void {}
}
```

```php
// ❌ Promoted boolean state on a mutable class is still a flag
final class Preference
{
    public function __construct(public bool $enabled) {}
}

// ✅ Boolean data inside a readonly value object is accepted
final readonly class Preference
{
    public function __construct(private bool $enabled) {}
}
```

```php
// ❌ Ordinary constructor argument, not recognized readonly state
final readonly class Preference
{
    public function __construct(bool $enabled) {}
}

// ✅ Explicit assignment to a declared readonly property is accepted
final class InheritedPreference
{
    private readonly bool $enabled;

    public function __construct(bool $enabled)
    {
        $this->enabled = $enabled;
    }
}
```

```php
// ❌ Boolean defaults and literal true/false types hide flags too
function dispatch(true $first, $third = false): void {}

// ✅ Non-boolean defaults stay untouched
function paginate(?int $perPage = null): Paginator {}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoBooleanParameters;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoBooleanParameters(),
    ]);
```

## Related rules

- [no_mode_parameters](no_mode_parameters.md) — flags the multi-valued variant: a parameter that dispatches distinct operations.
- [no_untyped_parameters](no_untyped_parameters.md) — requires a declared type on every parameter.
