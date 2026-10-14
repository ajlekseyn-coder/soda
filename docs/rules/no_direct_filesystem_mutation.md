# no_direct_filesystem_mutation

📝 Reports direct file writes, deletes, moves, and directory creation calls in application code.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Calling `file_put_contents`, `unlink`, `rename`, or `mkdir` from business code binds behavior to the local filesystem: effects cannot be seen in the signature, failures surface as PHP warnings instead of typed errors, and tests need a real disk. The rule reports each call at its source position and advises moving writes, deletes, moves, and provisioning behind an injected storage port so the intent is explicit and swappable.

## Examples

```php
// ❌
final class CheckpointRepository
{
    public function save(string $checkpoint): string
    {
        file_put_contents(storage_path('checkpoints.txt'), $checkpoint);

        return 'saved:'.$checkpoint;
    }
}

// ✅
final class CheckpointRepository
{
    public function __construct(private readonly Storage $storage) {}

    public function save(string $checkpoint): string
    {
        $this->storage->write('checkpoints.txt', $checkpoint);

        return 'saved:'.$checkpoint;
    }
}
```

```php
// ❌
public function provision(): string
{
    $path = sys_get_temp_dir().'/tenant-'.getmypid();
    mkdir($path);

    return basename($path);
}

// ✅
public function provision(StorageProvisioner $provisioner): string
{
    return $provisioner->createTenantRoot();
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoDirectFilesystemMutation;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoDirectFilesystemMutation(),
    ]);
```

## Related rules

- [no_subprocess_execution](no_subprocess_execution.md) — flags shell commands, the other hidden infrastructure effect.
- [no_environment_reads](no_environment_reads.md) — moves environment access behind an injected configuration boundary.
- [no_global_state](no_global_state.md) — reports process-wide state that hides inputs from the signature.
