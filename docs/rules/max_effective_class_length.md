# max_effective_class_length

📝 Limits the source length of a class counting the source of all composed traits once.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Splitting a large class into traits moves lines into other files but keeps the same maintenance cost in one place: the class still owns every behavior its traits provide. The rule sums the source lines of the class and of each trait it composes (trait diamonds are counted once) and reports classes whose effective length exceeds the limit. Count source from all composed traits once; moving source to a trait does not remove its maintenance cost.

## Examples

```php
// ❌ 520 effective source lines: the class plus its traits exceed the limit
final class ReportBuilder
{
    use FormatsColumns;
    use FormatsTotals;
    use ExportsDocuments;

    public function build(): Report {}
}
```

```php
// ✅ each type stays within the limit on its own
final class ReportBuilder
{
    use FormatsColumns;

    public function build(): Report {}
}

final class ReportExporter
{
    use ExportsDocuments;

    public function export(Report $report): Document {}
}
```

## Options

- `limit` (default `500`): maximum accepted logical source lines for a class, summed over the class body and each composed trait exactly once (blank lines and comments do not count). A composed total of 501 lines is reported; a 500-line total passes. The limit must be positive.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxEffectiveClassLength;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxEffectiveClassLength(500),
    ]);
```

## Related rules

- [max_class_length](max_class_length.md) — bounds the declared source length of a single class.
- [max_effective_methods_per_class](max_effective_methods_per_class.md) — applies the same composition-aware counting to the method surface.
- [max_traits_per_class](max_traits_per_class.md) — bounds the number of traits a class composes.

