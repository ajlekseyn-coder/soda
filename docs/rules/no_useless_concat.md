# no_useless_concat

📝 Reports same-line concatenations whose two operands are string literals.

💼 This rule is enabled in the ✅ `standard` config.

## Why

One literal expresses the same bytes with less syntax than two literals joined
by a concatenation. Single and double quotes are both eligible, including mixed
quote styles and empty strings. When combining them, preserve decoded bytes,
escapes and interpolation behavior.

Multiline concatenations remain accepted because the split can clarify a long
message. Variables, interpolated strings, constants, numeric operands and calls
remain accepted: their evaluation or conversion requires a separate operation.

Each finding uses the concatenation's source line, `value: 1`, and `threshold: 0`.
For `'a' . 'b' . 'c'`, the directly adjacent literal pair produces one finding;
the check does not fold expressions, propagate values or generate a source edit.

## Examples

```php
// ❌ two literals express one message
$message = 'Hello' . ' world';

// ✅ one literal says it directly
$message = 'Hello world';
```

```php
// ✅ accepted: multiline layout and dynamic operands
$query = 'SELECT name '
    . 'FROM users';
$message = 'Hello ' . $name;
$message = "Hello $name" . ' world';
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoUselessConcat;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoUselessConcat,
    ]);
```

## Related rules

- [no_repeated_string_literals](no_repeated_string_literals.md) — identifies repeated literals that may represent one named concept.
- [max_line_length](max_line_length.md) — keeps long messages readable without discarding meaningful multiline layout.
