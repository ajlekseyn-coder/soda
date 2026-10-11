# Owner behavior rules

These six standard checks identify structural evidence for keeping behavior on
its owner, organizing it in concerns (traits), and expressing repeated setup as a
fluent scenario. They do not require a trait quota, a class split, or chaining every
method. A finding is a review signal, not proof that two operations mean the same
thing. There are no automatic fixes.

## Standard configuration

```php
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;

return Soda::configure()->withPaths(['app/'])->with(RuleCatalog::standard());
```

All are discoverable through `list:rules`, with warning severity. They are included
in `RuleCatalog::standard()` and generated `init` configurations.
Their violations still fail the quality gate. Values report participating classes,
owners, methods, or consumers; thresholds show the maximum count below detection.

## FragmentedOwnerBehavior

**Smell:** several small stateless classes implement actual behavior over the same
owner. Each file reads well, but understanding one owner's operations requires
navigation across the classes.

```php
$publisher->publish($post);
$unpublisher->unpublish($post);
$checker->check($post);
```

The default requires three independent operation classes. Each must be concrete,
local, uncomposed, have no state or constructor contract/effects, and expose
instance operations with exactly one named object parameter. Bodies must perform
a decision/calculation or a direct write over that parameter, with no extra
receiver, local delegation, or unsupported effects. Target members must resolve
as public on a local owner. Direct, exactly typed call sites must demonstrate use
of the operations with that owner. Mere forwarding is outside this rule.

**Advice:** consider `$post->publish()`, `$post->unpublish()`, and an owner predicate.
A concern can organize that theme inside `Post`. Preserve separate policies with
independent contracts, context, or lifetimes. The detector does not prove domain
ownership from syntax.

## RepeatedOwnerConcern

**Smell:** a substantive stateful method group is copied across independent owners.

```php
// Both Post and Comment independently declare these bodies.
public function archive(): void { $this->archived = true; }
public function restore(): void { $this->archived = false; }
public function isActive(): bool { return ! $this->archived; }
```

The default requires three connected methods in two owners, at least two public
methods, a state write, and a decision/calculation. Connections are declared field
sharing and resolved direct local calls. Member names, visibility, parameter and
return contracts, field contracts/defaults, literals, and operation order must
match. Comments, formatting, and local parameter/variable names are ignored.
Field names are preserved; there is no semantic or algebraic equivalence analysis.

**Advice:** if the copies implement one behavior contract, share an `Archivable`
concern. Diagnostics list every member location and the required owner fields.
Getters/setters alone do not qualify. Similar implementations with different
meaning must remain independent. Existing trait composition is excluded, so
sharing a concern does not create another copied implementation finding.

## ScatteredOwnerConcern

**Smell:** related stateful operations are interleaved with unrelated methods in
one owner, forcing the reader to reconstruct a behavior group.

The same substantive-group criteria apply, with three methods by default. A
finding additionally requires another group's method between this group's first
and last declarations. A cohesive class or adjacent group does not fail merely
because it contains several themes. Unknown links block the recommendation.

**Advice:** first group the methods together. Consider a concern if its own file
improves navigation, retaining behavior on the original owner. A concern with one
owner is valid. Extraction does not reduce effective method/length measurements.
This is not the rejected disconnected-method-group quota.

## RepeatedConfigurationSequence

**Smell:** repeated fresh-object setup obscures a scenario behind mechanical calls.

```php
$report = new Report();
$report->setPeriod($period);
$report->setFormat('csv');
$report->includeTotals();
return $report->download();
```

The default requires the same ordered method sequence in three distinct consumers,
with at least two configuration calls and a final public non-configuration,
non-getter operation. Each setup method must have a complete `void` body of direct
assignments from its inputs or simple literals to declared owner fields. Literal
steps such as `includeTotals()` need no parameters. Argument values may differ;
method order and receiver type must match. Arguments must be simple literals or
unrelated local variables, with exact declared argument counts.

Construction and calls must occur in one contiguous statement list. Aliases,
intervening operations, computed/reference/unpacked/named arguments, escaping
receivers, unknown methods, and calls across branch boundaries do not qualify.
The final call may be an expression statement or a return. Existing fluent chains
are accepted. Getters are not treated as execution.

**Advice:** consider a fluent object or named construction:

```php
return Report::for($period)->asCsv()->withTotals()->download();
```

A chain is only an improvement if it clarifies the scenario. Preserve argument
and effect order, object identity/lifetime, and an explicit execution boundary.
The detector does not establish a universal command/query contract for the final
operation.

## RepeatedForeignStateDecision

**Smell:** multiple consumers reconstruct the same question about a foreign owner's
state, distributing a potential domain rule.

```php
return $post->isPublished() && ! $post->deleted;
```

The default requires three distinct named methods/functions consuming one exact
local owner type. A supported compound boolean expression must contain only direct
public field reads or proved public zero-argument getters, literals, strict or
ordering comparisons, negation, and exact non-relative class constants. A getter
is proved only when its complete body is one return of a declared owner field.
Variable receiver names are normalized; accessor identity and operand order stay
significant. Preceding single-assignment direct aliases are supported. Multiple
occurrences inside one consumer count once.

**Advice:** if the occurrences express one decision, introduce an owner predicate,
for example `$post->isPubliclyVisible()`. A concern may organize related behavior.
Expressions involving external context, two receiver identities, computed getters,
reassigned parameters/aliases, nullsafe/dynamic access, or nested captured closures
are excluded. No command after the question is required: this differs from the
existing ask-then-tell detector.

## RepeatedForeignStateUpdate

**Smell:** consumers repeatedly compute a change to another object's state.

```php
$wallet->setBalance($wallet->balance() - $amount);
```

Each call reads well, but changing the formula requires finding all consumers.
The default requires three distinct named methods/functions with the same
arithmetic expression over one local owner's mutable `int`, `float`, or
`int|float` field. The getter must be a public zero-argument direct field read.
The setter must take one numeric input and have only a direct assignment of that
input to the same declared field, optionally followed by `return $this`.
Named arguments must match the setter's declared parameter. To exclude unresolved
overrides, either the owner class or both accessors must be final.

Supported expressions contain numeric literals, stable numeric parameters,
zero-argument getters of that same field, arithmetic `+`, `-`, `*`, `/`, `%`, `**`,
and unary `+`/`-`. Receiver names and numeric input names are normalized;
operand order, literal values/types, input sharing, and numeric contracts are
preserved. Proven preceding direct aliases share receiver identity. Different
instances of the same type do not. Repeats within one consumer count once;
different formulas and fields form separate findings.

Computed locals, function calls, casts, dynamic/nullsafe access, multiple fields,
references, reassigned inputs/receivers, inputs or receivers passed directly as
call arguments (potential rebinding through an unknown reference contract),
nested captured closures, untyped or
object state, and getters/setters with validation or other effects are excluded.
Unknown library types and ORM magic accessors are outside this first version.

**Advice:** if the occurrences express one domain operation, introduce an owner
method, for example `$wallet->debit($amount)`. A concern can organize related
behavior. Diagnostics include the field, a representative source expression,
and every participating consumer location. The example name is illustrative;
the detector cannot infer business vocabulary. Transport records and generic
numeric structures may deliberately expose this API. No transaction, atomicity,
or concurrency guarantee is inferred, and no automatic fix is offered.

## Boundaries and verification

Types with inheritance, implemented contracts, existing traits, property hooks,
static state, or behavior magic methods are excluded from owner recommendations.
Duplicate local type declarations are ambiguous and excluded. Unknown external
owners and arbitrary polymorphic calls are not interpreted as proof. Collection
runs only when the `ownerDesign` analysis is requested and reuses the resolved
AST. Project storage retains scalar/array evidence without source or AST objects.

[OwnerDesignRulesTest](../tests/unit/OwnerDesignRulesTest.php) verifies cross-file
findings, boundaries, thresholds, locations, JSON evidence, standard registration,
existing concerns/fluent APIs, and uncertainty. [owner-opportunities.php](../tests/fixtures/design/owner-opportunities.php) provides
one complete scenario that produces all five diagnostics through the normal runner.
[owner-refactored.php](../tests/fixtures/design/owner-refactored.php) shows the
corresponding concerns, owner operations, and fluent report. A runtime test verifies
the illustrated state transitions and report results before/after; all five design
checks accept the refactored scenario. This does not establish safety of arbitrary
refactorings or acceptance by every other Soda rule.
[RepeatedForeignStateUpdateTest](../tests/unit/RepeatedForeignStateUpdateTest.php)
verifies the sixth rule's cross-file expressions, receiver identity, numeric
contracts, overrides, thresholds, JSON evidence, standard registration, and
counterexamples. [state-update-before.php](../tests/fixtures/design/state-update-before.php)
produces one update diagnostic; [state-update-after.php](../tests/fixtures/design/state-update-after.php)
uses an owner operation. The test runs both scenarios and checks identical balances
after each operation, and that the refactored scenario has no update finding.
These tests establish detector
behavior, not real-project precision. No reviewed application corpus or accuracy
percentage is claimed. The rules run by default; calibrate thresholds and review findings in the application context.
