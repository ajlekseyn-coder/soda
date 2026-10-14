# max_method_length

📝 Limits the source length of a single method.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A method that spans hundreds of lines mixes several scenarios, which makes it hard to name, test and review. The rule reports each method whose source line count exceeds the limit and advises naming a distinct step with a private method when it clarifies the scenario, while keeping the operation with its owner.

## Examples

```php
// ❌ one long method doing parsing, validation and persistence
public function import(string $raw): void
{
    // ... more than 100 lines of parsing ...
    // ... more than 100 lines of validation ...
    // ... more than 100 lines of persistence ...
}
```

```php
// ✅ each step is named and short
public function import(string $raw): void
{
    $rows = $this->parse($raw);
    $this->guard($rows);
    $this->persist($rows);
}
```

## Options

- `limit` (default `100`): maximum accepted logical source lines per method — physical lines containing PHP tokens between the signature and the closing brace, so blank lines and comments do not count. A method with 101 code lines is reported; a 100-line method passes. A non-positive limit disables the check.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxMethodLength;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxMethodLength(100),
    ]);
```

## Related rules

- [max_statements_per_callable](max_statements_per_callable.md) — bounds the statement count of a method regardless of formatting.
- [max_cognitive_complexity](max_cognitive_complexity.md) — flags methods whose control flow is hard to follow.
- [max_class_length](max_class_length.md) — bounds the source length of the class that accumulates long methods.

