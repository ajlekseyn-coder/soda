# multiline_constant_phpdoc

📝 Requires class constants to be documented with a multiline PHPDoc block that contains a written description before any tags.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Constants often carry domain rules — limits, defaults, status codes — whose meaning is invisible in the value. A one-line `/** ... */` or a tag-only block does not explain the purpose; it restates the type at best. The rule reports each selected constant that lacks a multiline PHPDoc (`/**` … `*/`, at least three lines) with a prose description before the first `@` tag. Constants declared in one statement share one report per declaration, and one documented declaration covers every constant in it.

## Examples

```php
// ❌
public const RETRY_LIMIT = 3;

/** Only a type restatement. */
public const TIMEOUT_SECONDS = 30;

// ✅
/**
 * The maximum number of delivery attempts per message.
 */
public const RETRY_LIMIT = 3;

/**
 * How long to wait for a response before giving up.
 */
public const TIMEOUT_SECONDS = 30;
```

```php
// ❌ A tag alone does not explain the purpose.
/**
 * @var int
 */
public const RETRY_LIMIT = 3;

// ✅ Description first, tags after.
/**
 * The maximum number of delivery attempts per message.
 *
 * @var int
 */
public const RETRY_LIMIT = 3;
```

## Options

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$visibilities` | `list<'public'\|'protected'\|'private'>` | `['public']` | Which member visibilities require documentation. Pass `['public', 'protected', 'private']` to cover all constants; enum cases are never reported. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Documentation\MultilineConstantPhpDoc;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MultilineConstantPhpDoc(['public']),
    ]);
```

## Related rules

- [multiline_method_phpdoc](multiline_method_phpdoc.md) — the same requirement for methods.
- [multiline_property_phpdoc](multiline_property_phpdoc.md) — the same requirement for properties and promoted constructor parameters.
- [no_consecutive_block_comments](no_consecutive_block_comments.md) — keeps description and tags in one block instead of adjacent ones.
