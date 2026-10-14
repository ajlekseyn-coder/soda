# Expressions and local state

These checks use PHP syntax and lexical ownership. They do not infer runtime
values, alias propagation, or application-wide meanings of strings.

| Check | Default selection | Configuration | Finding |
|---|---|---|---|
| `NoUnneededTernary` | Standard | No arguments | Each ternary with opposite literal boolean branches |
| `NoReturnInFinally` | Standard | No arguments | Each explicit return owned by a finally block |
| `NoReassignedParameters` | Standard | `ignoredParameters: []` | Each direct write to a by-value parameter binding |
| `MaxConsecutiveLocalAssignments` | Standard | `limit: 8` | Each maximal direct-body run exceeding the limit |
| `NoRepeatedStringLiterals` | Standard | `minOccurrences: 3, minLength: 3, ignoredStrings: []` | Each repeated literal group in one class-like declaration |

## Configuration

```php
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\MaxConsecutiveLocalAssignments;
use Cosmira\Soda\Rules\Complexity\NoRepeatedStringLiterals;
use Cosmira\Soda\Rules\Usage\NoReassignedParameters;

// Replace defaults when adding project-specific exceptions.
$rules = array_filter(RuleCatalog::standard(), static fn ($rule): bool => ! in_array(
    $rule->id(),
    ['no_reassigned_parameters', 'max_consecutive_local_assignments', 'no_repeated_string_literals'],
    true,
));

return Soda::configure()->withPaths([__DIR__.'/src'])->with([
    ...$rules,
    new NoReassignedParameters(ignoredParameters: ['cursor']),
    new MaxConsecutiveLocalAssignments(8),
    new NoRepeatedStringLiterals(3, minLength: 3, ignoredStrings: ['application/json']),
]);
```

Exceptions are exact and case-sensitive. Parameter names omit the `$` prefix.
All five checks run in `RuleCatalog::standard()` and generated `soda init` configs.
Existing explicit rule lists retain their selected checks. Tune policies with
project judgment: local input normalization,
straight-line data preparation, and repeated framework keys can all be useful.

## NoUnneededTernary

```php
// Reported:
return $value ? true : false;
return $value ? false : true;

// Equivalent boolean results, evaluating the condition once:
return (bool) $value;
return ! $value;
```

Keep the cast unless the condition is already known to be boolean. Returning the
condition directly can change the result type for integers, strings, objects,
arrays or null. Only literal `true` and `false` in opposite branches match,
including case variants and parentheses. Equal branches belong to
`NoIdenticalBranches`. Numeric/string branches, shorthand ternaries, null
coalescing and named constants do not match. There is no automatic rewrite.

## NoReturnInFinally

A return in `finally` overrides a pending return and can suppress a pending
exception. Move the return to the normal control flow and leave cleanup in
`finally`:

```php
try {
    return $reader->read();
} finally {
    $reader->close();
}
```

Returns nested in conditions, loops or nested try/catch inside the finally block
are reported. A closure, arrow function, named function or class declaration
starts an independent owner: its return is not attributed to the enclosing
finally. Its own finally blocks are still checked. File-level finally blocks and
generator returns are covered. This policy does not report `throw`, `yield` or
`break` as returns.

## NoReassignedParameters

Reports direct rebinding of by-value parameters through ordinary, reference,
compound and coalescing assignments; increment/decrement; `unset`; foreach
key/value bindings; catch bindings; destructuring; and global/static bindings.
Every occurrence has its own source location. Repeated targets within one write
produce one finding per parameter.

```php
// Reported:
function render($input) { $input = normalize($input); }
// A separate local preserves the incoming value:
function render($input) { $normalized = normalize($input); }
```

By-reference parameters are intentional output bindings and are exempt. Object
property and array-offset writes are allowed: the check concerns the parameter
binding, not deep immutability. Explicit by-reference closure captures preserve
the outer binding policy; by-value captures, arrows and separately declared
functions/classes have their own scopes. Their own parameters are checked.
Dynamic variable names, writes through aliases, and mutations hidden in called
functions are not inferred. Promoted and variadic parameters are covered.

## MaxConsecutiveLocalAssignments

Counts consecutive **first simple assignments** to named local variables in the
direct body of each method, function or closure. Eight is allowed by default;
nine produces one finding at the first assignment with value nine and threshold
eight. Zero forbids any qualifying initial binding; negative limits are invalid.

Comments and empty statements do not break a run. Other operations, returns,
control-flow statements, reassignments, parameter writes, compound/reference
assignments, destructuring, property/offset writes and assignment chains break
it. Parameters and explicit closure captures are reserved from the start.
Previously encountered variable names, including reads and control-flow bindings,
are conservatively treated as existing locals. Nested callable bodies are
measured separately; runs inside control-flow blocks are not measured by this
policy. Deferred arrow bodies are not inspected to reserve outer variable names.

This is a signal to inspect a preparation phase, not a requirement to replace
locals with a data container. Dependent calculations count too; use a higher
project limit when a straight-line algorithm is clearer.

## NoRepeatedStringLiterals

Groups exact decoded string bytes within one class, trait, enum, interface or
anonymous class. A group needs at least three occurrences and three **bytes** by
default. A finding points to the triggering third occurrence, lists all occurrence
lines, and carries the total count with threshold two (the permitted maximum).
`minOccurrences` must be at least two and `minLength` at least one.

```php
// Three uses of 'pending' in class behavior are a review signal.
// If they name one protocol value, a constant can express that meaning:
private const PENDING = 'pending';
```

Quoted escape variants, constant heredoc and nowdoc strings with the same decoded
bytes form one group. Array keys count. Whitespace-only and shorter literals do
not. Interpolated strings, attributes, parameter/property defaults, class
constants and enum backing values are excluded. Property-hook behavior is
included. Expression closures/arrows belong to their class; separately declared
functions and nested classes do not. Nested classes are checked independently.
Traits are not merged into consuming classes; top-level functions are outside
this owner-based policy. Equal text does not prove equal meaning: configure
intentional repeats rather than introducing misleading shared constants.

## Verification evidence

The five rule suites and `ExpressionAndLocalStateIntegrationTest` cover default
enforcement, positive findings and legitimate counterexamples,
inclusive and triggering thresholds, invalid configuration, lexical ownership,
diagnostic evidence, catalog/init selection, JSON reporting and executable
configuration examples. Runtime cases verify boolean conversion with 16 different
PHP values and demonstrate how finally returns replace results and suppress
exceptions.

Dynamic aliases, runtime dispatch and shared string meaning are outside these
syntax checks; review the scope descriptions before adoption.
