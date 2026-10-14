# max_file_loc

📝 Limits the source length of a single file.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Files beyond several hundred lines accumulate unrelated concerns, become merge hotspots, and hide the primary type behind incidental code. The rule reports each file whose source line count exceeds the limit and advises splitting the file by responsibility, keeping one primary type or cohesive concern per file.

## Examples

```php
// ❌ a 900-line file holding services, helpers and configuration
// src/Legacy/Everything.php
final class Everything
{
    // ... many unrelated responsibilities ...
}
```

```php
// ✅ one primary type per file
// src/Billing/InvoiceService.php
final class InvoiceService
{
    public function create(): Invoice {}
}

// src/Billing/InvoiceRenderer.php
final class InvoiceRenderer
{
    public function render(Invoice $invoice): string {}
}
```

## Options

- `limit` (default `700`): maximum accepted code line count per file — physical lines containing PHP tokens, so blank lines and comments do not count. A file with 701 code lines is reported; a 700-line file passes. A non-positive limit disables the check.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxFileLoc;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxFileLoc(700),
    ]);
```

## Related rules

- [max_classes_per_file](max_classes_per_file.md) — bounds the number of types sharing the file that multiple concerns inflate.
- [max_class_length](max_class_length.md) — bounds the source length of the primary type in the file.
- [max_method_length](max_method_length.md) — bounds the individual methods that often push a file past the limit.

