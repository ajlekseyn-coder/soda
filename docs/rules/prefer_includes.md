# prefer_includes

📝 Reports strpos comparisons that ask the substring question.

💼 This rule is enabled in the ✅ `standard` config.

## Why

`strpos($text, $seed) !== false` answers a yes-or-no question with a position and a sentinel. `str_contains($text, $seed)` answers the same question directly, and `mb_str_contains()` does it for multibyte haystacks. The rule reports `strpos()` and `mb_strpos()` compared with `false` using `===`, `!==`, `==`, or `!=`, on either side of the comparison.

Position checks that mean something other than membership — extracting an offset, comparing two positions, checking that the match starts at zero — are outside the policy.

## Examples

```php
// ❌ a sentinel comparison
if (strpos($template, '{{total}}') !== false) {
    $template = $this->fill($template);
}

// ✅ the question reads as a question
if (str_contains($template, '{{total}}')) {
    $template = $this->fill($template);
}
```

```php
// ✅ position still matters here and stays
if (strpos($line, 'ERROR') === 0) {
    $this->report($line);
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Usage\PreferIncludes;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new PreferIncludes(),
    ]);
```

## Related rules

- [prefer_string_starts_ends_with](prefer_string_starts_ends_with.md) — applies the same question shape to prefixes and suffixes.
- [no_repeated_string_literals](no_repeated_string_literals.md) — names the searched value when it repeats across calls.
