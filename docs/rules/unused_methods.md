# unused_methods

📝 Flags private and protected methods that are never called in the project.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A method that no code calls is dead code: it still has to be read, tested, and kept consistent with the rest of the class, but contributes nothing. The rule analyses method usage across all configured project files, including calls through inheritance and trait composition, and reports private and protected methods that are never invoked. Protected methods that clearly belong to a supertype contract are exempt, as are magic methods. The advice is to delete the method; if it is an intended extension point, express that contract explicitly instead of leaving it as uncallable code.

## Examples

```php
// ❌
class Importer
{
    public function run(): void
    {
        $this->load();
    }

    private function load(): void {}

    private function validate(): void {} // never called
}

// ✅
class Importer
{
    public function run(): void
    {
        $this->load();
    }

    private function load(): void {}
}
```

```php
// ✅ Exempted: PHPUnit lifecycle methods are skipped by default
class ImporterTest extends TestCase
{
    protected function setUp(): void {}
}
```

Calls from any configured project file count as usage, so a method used only from tests inside the configured paths is not reported.

## Options

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `ignore` | `list<string>` | `[]` | Extra private method names to never flag, merged with the built-in lifecycle exceptions `setUp`, `tearDown`, `setUpBeforeClass`, and `tearDownAfterClass`. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Usage\NoUnusedMethods;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoUnusedMethods(ignore: ['seedDatabase', 'actingAs']),
    ]);
```

## Related rules

- [no_unused_private_state](no_unused_private_state.md) — flags private properties and constants that are never read.
- [no_unused_parameters](no_unused_parameters.md) — flags parameters that are never used, the signature-level sibling of dead methods.
