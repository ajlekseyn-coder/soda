# multiline_property_phpdoc

📝 Requires properties — including promoted constructor parameters — to be documented with a multiline PHPDoc block that contains a written description before any tags.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Properties carry state whose meaning — units, invariants, lifecycle — is rarely obvious from the type declaration alone. A one-line `/** ... */` or a tag-only block restates the type without explaining the purpose. The rule reports each selected property that lacks a multiline PHPDoc (`/**` … `*/`, at least three lines) with a prose description before the first `@` tag. Promoted constructor parameters are treated as property declarations: they accept either their own docblock or a constructor PHPDoc whose `@param` entry names the parameter exactly.

## Examples

```php
// ❌
public string $endpoint;

/** One line is not a description. */
public int $timeout;

// ✅
/**
 * The configured API endpoint requests are sent to.
 */
public string $endpoint;

/**
 * Seconds to wait before the request is abandoned.
 */
public int $timeout;
```

```php
// ❌ A promoted parameter is still a property.
public function __construct(
    private readonly string $endpoint,
) {}

// ✅ The constructor PHPDoc documents the promoted property.
/**
 * Create a client bound to one API endpoint.
 *
 * @param string $endpoint The configured API endpoint requests are sent to.
 */
public function __construct(
    private readonly string $endpoint,
) {}
```

## Options

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$visibilities` | `list<'public'\|'protected'\|'private'>` | `['public']` | Which member visibilities require documentation. Pass `['public', 'protected', 'private']` to cover all properties. Interface properties never exist, so they are not reported. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Documentation\MultilinePropertyPhpDoc;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MultilinePropertyPhpDoc(['public']),
    ]);
```

## Related rules

- [multiline_method_phpdoc](multiline_method_phpdoc.md) — the same requirement for methods.
- [multiline_constant_phpdoc](multiline_constant_phpdoc.md) — the same requirement for constants.
