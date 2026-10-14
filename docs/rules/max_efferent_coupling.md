# max_efferent_coupling

📝 Limits the number of distinct external types a class references.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Efferent coupling (Ce) counts the distinct outside types a class depends on: every one of them is a reason the class may change and a dependency that must be understood and stubbed in tests. The rule reports classes whose outgoing dependency count exceeds the limit. Review which dependencies belong to this operation; remove unnecessary indirection, and introduce a boundary only when it represents a meaningful collaboration, not merely to lower the count.

## Examples

```php
// ❌ one class touching many outside types
final class ReportService
{
    public function __construct( // 16 dependencies total
        private ReportRepository $reports,
        private CustomerRepository $customers,
        private InvoiceRenderer $renderer,
        private PdfConverter $pdf,
        private Mailer $mailer,
        private EventDispatcher $events,
        private AuditLogger $audit,
        private Clock $clock,
        private Translator $translator,
        private CacheStore $cache,
        private ConfigRepository $config,
        private HttpClient $http,
        private QueueDispatcher $queue,
        private Storage $storage,
        private LoggerFactory $logger,
        private Validator $validator,
    ) {}
}
```

```php
// ✅ the report operation depends on fewer collaborators
final class ReportService
{
    public function __construct(
        private ReportRepository $reports,
        private ReportMailer $mailer,
    ) {}
}
```

## Options

- `limit` (default `15`): maximum accepted number of distinct external types referenced by a class, counted across its parent, interfaces, traits, properties, method signatures and body. A non-positive limit disables the check.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxEfferentCoupling;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxEfferentCoupling(15),
    ]);
```

## Related rules

- [max_dependencies](max_dependencies.md) — bounds the constructor dependencies, a subset of the references counted here.
- [no_dependency_cycles](no_dependency_cycles.md) — flags circular dependency paths between classes.
- [forbidden_dependencies](forbidden_dependencies.md) — excludes specific dependency pairs regardless of count.

