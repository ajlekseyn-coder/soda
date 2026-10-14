# max_classes_per_file

📝 Limits the number of named classes and traits declared in a single file.

💼 This rule is enabled in the ✅ `standard` config.

## Why

When several types share a file, readers and autoloaders lose the one-to-one mapping between a type name and its location, and unrelated changes start to collide in the same diff. The rule reports each file whose class-or-trait declaration count exceeds the limit and advises moving each class to its own file unless the smaller types are inseparable implementation details.

## Examples

```php
// ❌ three types in one file
<?php

namespace App\Billing;

final class Invoice {}

final class InvoiceLine {}

trait FormatsTotals {}
```

```php
// ✅ src/Billing/Invoice.php — one type per file, matching the PSR-4 path
final class Invoice {}
```

```php
// ✅ src/Billing/InvoiceLine.php — its own file
final class InvoiceLine {}
```

```php
// ✅ src/Billing/FormatsTotals.php — its own file
trait FormatsTotals {}
```

## Options

- `limit` (default `1`): maximum accepted number of named class and trait declarations per file. A non-positive limit disables the check.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxClassesPerFile;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxClassesPerFile(1),
    ]);
```

## Related rules

- [max_file_loc](max_file_loc.md) — bounds the physical size of the file that multiple types inflate.
- [max_classes_per_namespace](max_classes_per_namespace.md) — caps the number of types sharing a namespace.
