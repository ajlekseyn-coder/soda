# no_untyped_parameters

📝 Declare a native type on every parameter, using `mixed` to preserve an untyped inherited contract.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Removing a parameter type must not be a way to bypass type-based restrictions: an untyped parameter silently widens the accepted inputs and hides the callable's contract from readers, static analysis, and IDEs. The rule reports every parameter declaration without a native type — in methods, functions, closures, arrow functions, variadics, by-reference parameters, and interface contracts. Declaring `mixed` preserves an untyped inherited or trait signature without narrowing its accepted inputs.

## Examples

```php
// ❌ Contracts and callables without declared types
interface Contract
{
    public function run($input);
}

class Worker
{
    public function run($input = null) {}
}

$closure = function ($input) {};
$arrow = fn ($input) => $input;
```

```php
// ✅ Explicit types on every parameter
function work(int|string $id, ?string $label, mixed ...$values): void {}
```

```php
// ❌ Untyped parent signature
class ParentModel
{
    public function setTable($table) {}
}

// ✅ mixed keeps the inherited untyped contract without narrowing it
trait TableSelection
{
    public function setTable(mixed $table): static
    {
        return $this;
    }
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoUntypedParameters;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoUntypedParameters(),
    ]);
```

## Related rules

- [no_boolean_parameters](no_boolean_parameters.md) — flags boolean parameters, the narrowest and most ambiguous declared contract.
- [no_implicit_arguments](no_implicit_arguments.md) — requires an explicit callable contract instead of arguments that arrive implicitly.
