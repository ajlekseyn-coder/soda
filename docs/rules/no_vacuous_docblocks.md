# no_vacuous_docblocks

📝 Flags PHPDoc descriptions that add nothing beyond the member's own name.

💼🚫 This rule is **not** enabled by default. Add it to your `soda.php` config to enable it.

## Why

A description built from filler verbs (`stores`, `defines`, `holds`) and generic context nouns (`this instance`, `this policy`) restates the declaration it decorates. Such docblocks train readers to skip comments, so the ones carrying real insight get skipped too. The rule extracts the prose before the first tag, removes the words composing the member's own name and a filler vocabulary, and reports the member when no content word survives. Either describe the member's purpose or delete the comment.

## Examples

```php
// ❌
final class Report
{
    /** Stores signature for this analysis instance. */
    private array $signature;

    /** Defines separator width used by this policy. */
    private const int SEPARATOR_WIDTH = 60;

    /** Initialize the configured values and collaborators for this instance. */
    public function __construct(private readonly array $mounted) {}
}

// ✅
final class Report
{
    /** Byte offsets of every signature entry, indexed by declaration id. */
    private array $signature;

    /** Ruler width printed above each report section. */
    private const int SEPARATOR_WIDTH = 60;

    /** Mount shared services and freeze the immutable run state. */
    public function __construct(private readonly array $mounted) {}
}
```

## Options

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$extraFiller` | `string[]` | `[]` | Additional words treated as carrying no information, merged with the built-in filler vocabulary (`stores`, `defines`, `holds`, `instance`, `policy`, …). |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Documentation\NoVacuousDocblocks;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoVacuousDocblocks(extraFiller: ['metadata', 'payload']),
    ]);
```

## Related rules

- [multiline_property_phpdoc](multiline_property_phpdoc.md) — requires the description to exist; this rule requires it to say something.
- [no_todo_fixme_comments](max_todo_fixme_comments.md) — removes the other class of comment noise.
