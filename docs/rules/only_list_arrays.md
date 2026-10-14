# only_list_arrays

📝 Discourages structured array usage; only list arrays are allowed.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Arrays that carry domain meaning — chained lookups like `$cfg['db']['host']`, or string-keyed payload shapes — hide their structure in syntax. Every consumer must repeat the same key chains, and nothing enforces the shape at runtime. The rule checks array-access syntax and advises a value object for repeated domain interpretation or invariants; transport payloads and one-off arrays do not automatically need wrappers. Method and function `array` type declarations are not checked — the usage in the body matters. Laravel-style request and resource files (`*Request.php`, `*FormRequest.php`, `*Resource.php`, `*JsonResource.php`) are skipped by default.

Two strictness levels are available:

- `Pragmatic` (default) — reports chained array access only (`$x[$a][$b]`, `$cfg['a']['b']`). Single-level `$row['id']` and `array{...}` PHPDoc shapes are allowed.
- `Strict` — also reports single string-literal keys at any depth and `array{...}` shapes in docblocks.

## Examples

```php
// ❌
$config = $container['settings']['database']['host'];

// ✅
$host = $settings->databaseHost();
```

```php
// ✅ Accepted in Pragmatic mode: a single string key is not a nested chain
$id = $row['id'];
```

In `Strict` mode the same single string-literal key is reported too, along with `array{...}` shapes in docblocks; enable it with `ListOnlyArrayStrictness::Strict`.

```php
// ✅ Accepted in Pragmatic mode: list-style iteration
foreach ($rows as $row) {
    echo $row[0];
}
```

## Options

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `ignorePathPatterns` | `list<string>` | `[]` | Extra fnmatch patterns matched against the full file path or basename, merged with the built-in request/resource patterns. |
| `ignorePathPrefixes` | `list<string>` | `[]` | Normalized path prefixes: a file is skipped when its resolved path is under one of them. |
| `strictness` | `ListOnlyArrayStrictness` | `ListOnlyArrayStrictness::Pragmatic` | Which nested array accesses to report. Use `ListOnlyArrayStrictness::Strict` for maximum discipline. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Usage\ListOnlyArrayStrictness;
use Cosmira\Soda\Rules\Usage\OnlyListArraysAllowed;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new OnlyListArraysAllowed(
            ignorePathPrefixes: ['legacy/'],
            strictness: ListOnlyArrayStrictness::Pragmatic,
        ),
    ]);
```

## Related rules

- [no_numeric_array_index](no_numeric_array_index.md) — reviews documented positional tuples, the numeric-index counterpart of this rule.
- [representation_churn](representation_churn.md) — flags repeated conversions between array and object representations of the same data.
