# no_reassigned_parameters

📝 Rejects direct rebinding of by-value input parameters.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A parameter is the documented name for the caller's input. Assigning to it (`$input = normalize($input)`, `$input ??= fallback()`, or incrementing it) makes the same name refer to two different values over time, which hides the original input and invites bugs when the function is edited. The rule keeps input bindings stable: original input keeps its name, and a transformed value gets its own name. A narrow leading normalization of the same input is accepted by default. By-reference parameters and object/array mutation (`$input->value = 1`, `$input[0] = 1`) are not rebinding and are not reported.

## Examples

```php
// ❌ the parameter is rebound to a new value
function run($input): string
{
    $input = normalize($input);

    return $input;
}

// ✅ the transformed value gets its own name
function run($input): string
{
    $normalized = normalize($input);

    return $normalized;
}
```

```php
// ❌
final class Cart
{
    public function total($amount): int
    {
        $amount += $this->surcharge();

        return $amount;
    }
}

// ✅
final class Cart
{
    public function total($amount): int
    {
        $total = $amount + $this->surcharge();

        return $total;
    }
}
```

```php
// ✅ Accepted: by-reference output parameter is its contract
function fill(array &$output): void
{
    $output = ['ready' => true];
}
```

## Options

`NoReassignedParameters` accepts two constructor arguments:

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `ignoredParameters` | `list<string>` | `[]` | Parameter names (without the `$` prefix) that are allowed to be rebound. Use this for intentional entry normalization. |
| `allowInputNormalization` | `bool` | `true` | When `true`, a leading normalization of the same input using known scalar operations is accepted. Set to `false` to report every write. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Usage\NoReassignedParameters;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoReassignedParameters(
            ignoredParameters: ['path'],
            allowInputNormalization: true,
        ),
    ]);
```

## Related rules

- [no_unused_parameters](no_unused_parameters.md) — the complementary signature rule: inputs that are never read at all.
- [useless_variable](useless_variable.md) — flags copies of other variables that add a name without adding meaning.
