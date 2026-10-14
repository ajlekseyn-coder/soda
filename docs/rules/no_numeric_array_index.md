# no_numeric_array_index

📝 Review repeated positional reads of an explicitly documented array tuple.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A parameter annotated as a closed scalar shape such as `array{string, int}` is a positional tuple: its fields have meaning that only the numeric index reveals. When a callable reads several of these fixed positions, `$row[0].$row[1]` reads as noise and every reader must consult the docblock to know what the data is. The rule reports such callables and advises named fields — a shape like `array{name: string, age: int}` or a value object — when that clarifies meaning. Ordinary list access (`$items[0]`) and arrays without a documented tuple shape are accepted.

## Examples

```php
// ❌
/** @param array{string, int} $row */
function render(array $row): string
{
    return $row[0].$row[1];
}

// ✅
/** @param array{name: string, age: int} $row */
function render(array $row): string
{
    return $row['name'].(string) $row['age'];
}
```

```php
// ✅ Accepted: plain list access without a tuple contract
function first(array $row): string
{
    return $row[0];
}
```

```php
// ✅ Accepted: a single positional read does not establish a tuple pattern
/** @param array{string, int} $row */
function label(array $row): string
{
    return $row[0];
}
```

The rule only fires when a callable reads two or more documented positions in straight-line scalar code. Writes, references, dynamic indexes, conditional evaluation, or escaping the array to another function suppress the report because the reads are then not provably positional tuple access.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Usage\NoNumericArrayIndex;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoNumericArrayIndex(),
    ]);
```

## Related rules

- [only_list_arrays](only_list_arrays.md) — the sibling array-usage policy for nested and associative array access.
- [representation_churn](representation_churn.md) — flags repeated conversions between alternative representations of the same data, a common companion of tuple-style arrays.
