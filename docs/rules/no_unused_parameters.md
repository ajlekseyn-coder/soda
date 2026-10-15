# no_unused_parameters

📝 Reports function and method parameters that are never used.

💼 This rule is enabled in the ✅ `standard` config.

## Why

An unused input is either dead weight — a leftover from a changed implementation — or an undocumented contract. Both hurt: readers must reason about a value that never matters, and callers pay for arguments that do nothing. The rule reports unused parameters and advises removing them when the signature is owned locally. It is deliberately conservative: positions required by an implemented interface or parent method, callback positions before the last used slot (e.g. stream and array-callback signatures), promoted constructor properties, and explicitly configured external contracts are preserved. A parameter that is only overwritten before being read counts as unused because the incoming value never survives to a read.

PHP's fixed magic-method positions are also preserved; `__invoke` and ordinary
constructors retain their own signatures. Reads through `func_get_args()`,
`func_get_arg()`, `compact()` and included code count within their owning callable.
An exit in a `try` block, switch case or match arm does not hide reads in
alternative paths or `finally`. Match conditions short-circuit within an arm;
`default` is selected after the explicit conditions even when written first.

Anonymous classes retain declared parent and interface contracts. Their identities
are scoped to the source file, and additional parameters beyond the inherited
signature remain eligible for diagnostics.

Inherited signatures from loaded PHP internal classes and interfaces (such as
`ArrayAccess`) are also preserved. Only internal metadata is inspected; application
autoloaders are never invoked and loaded application classes do not supply contracts.

## Examples

```php
// ❌
function operation($input, $mode): int
{
    return $mode === 'count' ? 1 : 0; // $input is never read
}

// ✅
function operation($input, $mode): int
{
    return $mode === 'count' ? strlen($input) : 0;
}
```

```php
// ❌
function handle(Request $request, string $guard): Response
{
    return new Response($request->all()); // $guard is never read
}

// ✅
function handle(Request $request): Response
{
    return new Response($request->all());
}
```

```php
// ✅ Preserved: the position is required by the interface contract
interface Contract
{
    public function run($input);
}

class Worker implements Contract
{
    public function run($input) {}
}
```

## Options

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `contracts` | `list<string>` | `[]` | Exact qualified method or function names (e.g. `App\Worker::run`) whose signatures are fixed by external consumers. Unused parameters on these callables are never reported. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Usage\NoUnusedParameters;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoUnusedParameters(contracts: [
            'App\Contracts\Handler::handle',
        ]),
    ]);
```

## Related rules

- [unused_methods](unused_methods.md) — the method-level counterpart: private and protected methods that are never called.
- [no_reassigned_parameters](no_reassigned_parameters.md) — flags inputs that are read but rebound instead of being kept stable.
