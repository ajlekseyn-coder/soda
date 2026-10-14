# no_environment_reads

📝 Reports `env()`, `getenv()`, `putenv()`, and `$_ENV`/`$_SERVER` access outside explicitly configured configuration boundaries.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Reading environment variables deep inside domain code hides a configuration input: the value can differ per process, tests need `putenv` scaffolding, and nothing in the signature reveals the dependency. The rule reports environment reads and writes at their source position and advises reading them once at the application boundary and injecting typed configuration instead. Paths you designate as configuration boundaries are exempt.

## Examples

```php
// ❌ Domain policy reads the environment on every call
final class DeploymentAwareDomainPolicy
{
    public function mode(): string
    {
        return getenv('DEMO_MODE') ?: 'safe';
    }
}

// ✅ Read at the boundary, inject typed configuration
final class DeploymentAwareDomainPolicy
{
    public function __construct(private readonly string $mode) {}

    public function mode(): string
    {
        return $this->mode;
    }
}
```

```php
// ❌
$tenant = $_SERVER['DEMO_TENANT'] ?? 'public';

// ✅
public function __construct(private readonly string $tenant) {}
```

## Options

- `ignorePathPrefixes` (default `[]`) — fnmatch-style path prefixes allowed to read environment values, e.g. `config/` directories. Application and package code outside these prefixes is still reported.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoEnvironmentReads;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoEnvironmentReads(ignorePathPrefixes: ['config/', 'packages/*/config/']),
    ]);
```

## Related rules

- [no_process_configuration_mutation](no_process_configuration_mutation.md) — flags runtime writes to ini, cwd, locale, and timezone.
- [no_global_state](no_global_state.md) — flags the other process-wide state channels.

### Package configuration masks

Directory prefixes can contain glob masks. A wildcard matches a single path
segment; descendants of the matched directory are also excluded:

```php
new NoEnvironmentReads(ignorePathPrefixes: [
    __DIR__.'/config',
    __DIR__.'/packages/*/config',
]);
```

This allows environment access in each package's configuration, including nested
configuration files. It does not allow access in package source files, sibling
`configuration` directories, or an extra directory level between package and
`config`. Prefix matching remains case-sensitive; literal prefixes retain their
existing behavior.
