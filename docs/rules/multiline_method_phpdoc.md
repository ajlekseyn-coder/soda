# multiline_method_phpdoc

📝 Requires methods to be documented with a multiline PHPDoc block that contains a written description before any tags.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A method's signature says what it accepts and returns, but not why the operation exists or what contract it upholds. Tags such as `@param` and `@return` alone restate the signature and explain nothing. The rule reports each selected method that lacks a multiline PHPDoc (`/**` … `*/`, at least three lines) with a prose description before the first `@` tag. Ordinary `//` comments do not count. Interface methods are checked as well, because they define the contract callers rely on.

## Examples

```php
// ❌
public function deliver(): void {}

/** One line is not a description. */
public function send(): void {}

// ✅
/**
 * Queue the message for delivery by the configured transport.
 */
public function deliver(): void {}
```

```php
// ❌ Tags alone restate the signature.
/**
 * @param string $recipient
 * @return bool
 */
public function notify(string $recipient): bool {}

// ✅ Description first, tags after.
/**
 * Tell one recipient that the report is ready.
 *
 * @param string $recipient
 * @return bool Whether the notification was accepted.
 */
public function notify(string $recipient): bool {}
```

## Options

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$visibilities` | `list<'public'\|'protected'\|'private'>` | `['public']` | Which member visibilities require documentation. Pass `['public', 'protected', 'private']` to cover all methods. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Documentation\MultilineMethodPhpDoc;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MultilineMethodPhpDoc(['public']),
    ]);
```

## Related rules

- [multiline_property_phpdoc](multiline_property_phpdoc.md) — the same requirement for properties.
- [multiline_constant_phpdoc](multiline_constant_phpdoc.md) — the same requirement for constants.
- [no_consecutive_block_comments](no_consecutive_block_comments.md) — keeps description and tags in one block instead of adjacent ones.
