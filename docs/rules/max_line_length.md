# max_line_length

📝 Limits the length of physical source lines.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Lines that run far past the editor width are hard to scan and diff, and they usually hide nested expressions or long argument lists. The rule counts the bytes of each physical line (trailing carriage returns are ignored) and reports every line longer than the limit at its line number. Format long lines using the project style — consistent indentation, logical breakpoints, and one argument or array item per line in multiline lists. Do not insert arbitrary line breaks just to satisfy the limit.

## Examples

```php
// ❌ one line carrying a long call chain
$result = $this->repository->findMatchingActiveSubscribersForSegment($segment, $filter, $limit, $offset, $sort);
```

```php
// ✅ break at logical points, one item per line
$result = $this->repository->findMatchingSubscribers(
    segment: $segment,
    filter: $filter,
    limit: $limit,
    offset: $offset,
);
```

## Options

- `limit` (default `100`): maximum accepted number of bytes per physical source line. A non-positive limit disables the check.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxLineLength;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxLineLength(100),
    ]);
```

## Related rules

- [max_file_loc](max_file_loc.md) — bounds the overall file size; line length limits keep individual lines readable.
- [max_arguments](max_arguments.md) — long lines often hide long parameter lists; formatting is not a substitute for fewer arguments.

