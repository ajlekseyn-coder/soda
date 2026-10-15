# Complexity Metrics

Metrics that measure branches, control flow nesting, returns and exception handling.

**Config:** Complexity rules are plain objects in `soda.php`.

### no_useless_return

`new NoUselessReturn()` reports a final direct bare return in a function, method
or closure. Keep guards, nested exits, explicit values and reference contracts.
See [examples and boundaries](rules/no_useless_return.md).

### no_redundant_boolean_returns

`new NoRedundantBooleanReturns()` reports opposite literal boolean returns in
an `if` and its `else` or immediately adjacent fallback. Return a boolean
conversion of the condition or its negation, evaluating it once. Additional
branch work and fallbacks across lexical boundaries remain accepted.
See [examples and PHP semantics](rules/no_redundant_boolean_returns.md).

### no_redundant_boolean_comparisons

`new NoRedundantBooleanComparisons()` reports equality comparisons with boolean
literals only when PHP syntax proves the other operand already returns bool.
Variables, arbitrary calls and integer-valued spaceship comparisons remain
accepted. See [examples and boundaries](rules/no_redundant_boolean_comparisons.md).

### no_useless_concat

`new NoUselessConcat()` reports same-line concatenations of two string literals.
Preserve decoded bytes and escaping. Multiline text layout and dynamic operands
are accepted. See [examples and boundaries](rules/no_useless_concat.md).

Rule selection and upstream sources are recorded in
[the rule research notes](RULE_RESEARCH.md).

### prefer_combined_conditions

`new PreferCombinedConditions()` reports a simple `if` containing only another
simple `if`. Combine the conditions with `&&` while retaining short-circuit order.
Alternative branches, compound logic, extra work and explanatory inner comments
remain accepted. See [examples and boundaries](rules/prefer_combined_conditions.md)
and [research evidence and limitations](RULE_RESEARCH.md#scientific-evidence).

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\MaxBooleanConditions;
use Cosmira\Soda\Rules\Complexity\MaxControlNesting;
use Cosmira\Soda\Rules\Complexity\MaxCyclomaticComplexity;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxCyclomaticComplexity(10),
        new MaxControlNesting(3),
        new MaxBooleanConditions(4),
    ]);
```

### max_cyclomatic_complexity

Counts branching: `if`, `elseif`, `switch`, `case`, `for`, `foreach`, `while`, `catch`, `&&`, `||`, `?:`.

| Config value | Strictness | Interpretation |
|--------------|------------|----------------|
| 5–8 | Strict | Simple methods only |
| 10–15 | Moderate | Acceptable |
| 20+ | Lenient | Complex logic allowed |

```php
// ❌ Bad (complexity 20+)
public function calculateDiscount(User $user, Order $order): float
{
    if ($user->isPremium()) {
        if ($order->total() > 1000) {
            return 0.2;
        }
        return 0.1;
    }
    if ($user->isNew()) {
        if ($order->items()->count() > 5) {
            return 0.05;
        }
        return 0;
    }
    if ($order->total() > 500 && $user->hasCoupon()) {
        return 0.15;
    }
    // ... 10 more branches
}
```

```php
// ✅ Good
public function calculateDiscount(User $user, Order $order): float
{
    return $this->strategies
        ->findFor($user)
        ->discount($order);
}
```

---

## Nesting Depth

### max_control_nesting

Maximum nesting level of `if`, `for`, `foreach`, `while`, `do`, `switch` and
`try`. Alternative branches (`elseif`, `else`, `catch`, `finally`) share the
level of their owning structure. A condition or loop inside such a branch adds
one level. Nested callable bodies are measured independently.

| Config value | Strictness |
|--------------|------------|
| 2–3 | Strict |
| 4 | Moderate |
| 5+ | Lenient |

```php
// ❌ Bad (4+ levels)
if ($a) {
    foreach ($b as $x) {
        if ($x->valid()) {
            switch ($x->type()) {
                case 1:
                    if ($y) {
                        // ...
                    }
                    break;
            }
        }
    }
}
```

```php
// ✅ Good
foreach ($this->filterValid($items) as $item) {
    $this->handle($item);
}
```

---

### max_try_catch_blocks (excessive exception handling)

**What it counts:** number of `try { … } catch (…) { … }` statements in a method or top-level function (each `try` counts once; `finally` does not add a second count). Nested `try` inside the same method counts separately. `try` inside closures passed to `array_map` and similar is **not** attributed to the enclosing method.

| Config value | Severity (default labels) | Meaning |
|--------------|---------------------------|---------|
| `2` | Warning at ≥3 blocks | Default: allow up to two `try/catch` per method |
| `0` | Disabled | Rule off |

```php
// ❌ Bad (3+ try/catch in one method when max_try_catch_blocks is 2)
function run() {
    try {} catch (\Exception $e) {}
    try {} catch (\Exception $e) {}
    try {} catch (\Exception $e) {}
}
```

```php
// ✅ Good: consolidate error handling or extract helpers with a single try/catch boundary
function run(): void
{
    try {
        $this->stepOne();
        $this->stepTwo();
    } catch (ProcessException $e) {
        $this->recover($e);
    }
}
```

---

### max_return_statements

Optional; not enabled in the standard configuration. Counts all returns, including
guards. Prefer nesting and complexity checks for a guard-first policy.

Limits the number of `return` statements in a method or function. Multiple
returns can be fine, but too many exits often mean the method is mixing
validation, branching, and result construction.

```php
new MaxReturnStatements(4)
```

```php
// Good
public function status(): string
{
    if ($this->cancelled) {
        return 'cancelled';
    }

    return $this->paid ? 'paid' : 'open';
}
```

```php
// Bad
public function status(): string
{
    if ($this->cancelled) {
        return 'cancelled';
    }
    if ($this->expired) {
        return 'expired';
    }
    if ($this->refunded) {
        return 'refunded';
    }
    if ($this->paid) {
        return 'paid';
    }

    return 'open';
}
```

### max_boolean_conditions

Limits boolean operands in a single decision. Long condition chains hide named
business concepts and make branch changes risky.

```php
new MaxBooleanConditions(4)
```

```php
// Good
if ($order->canBeRefundedBy($user)) {
    $order->refund();
}
```

```php
// Bad
if ($order->isPaid() && ! $order->isExpired() && $user->canRefund() && $gateway->isOnline() && ! $order->isLocked()) {
    $order->refund();
}
```

---

## Optional exception checks

```php
new \Cosmira\Soda\Rules\Complexity\NoRedundantRethrow()
new \Cosmira\Soda\Rules\Complexity\NoEmptyFinallyBlocks()
```

### no_redundant_rethrow

Reports a final catch whose only executable statement
throws the exact bound variable. It excludes any earlier catch because deleting
it could expose an exception to a later handler. Logging, transformation,
conditional throws, closures and other statements are behavior and excluded.
Comments and empty statements do not execute. Catch-variable scope, destruction
of an overwritten binding and uses in `finally` can still matter: the diagnostic
requests review and does not claim automatic deletion is equivalent.

### no_empty_finally_blocks

Reports a finally containing only comments/empty
statements or nothing. With no catch, removing finally alone creates invalid PHP;
the diagnostic explicitly calls for considering the try body too. A return,
throw, assignment, call or nested try is executable and makes the block nonempty.

Both checks inspect the shared AST, including nested functions, traits and
anonymous classes, report the catch/finally keyword line, and use value 1 with
threshold 0 per finding. `NoEmptyFinallyBlocks` runs by default, including in
`RuleCatalog::checks()` and `init`. `NoRedundantRethrow` requires explicit selection. There are no auto-fixes or suppressions.
Adaptations and license notices: [Third-party material](#third-party-material).

### max_cognitive_complexity

Standard `MaxCognitiveComplexity(15)` measures the documented **Soda PHP cognitive
v1** variant. It reports each contribution and retains independent nested callable
scopes. It is not a replacement for the existing standard or a claim of identical
Sonar scores. See [Research Metrics](RESEARCH_METRICS.md) for the exact formula,
PHP boundaries, limitations and corpus protocol.

### no_repeated_compound_conditions

Enabled by default: `new NoRepeatedCompoundConditions(minMethods: 3)` reports
an identical compound condition in at least three distinct methods of one class.
The diagnostic maximum is 2 methods with the default minimum of 3. Values below
2 for `minMethods` throw `InvalidArgumentException`.

For example, three methods containing `if ($this->active && ! $this->suspended)`
can call a private `canRenew()` predicate when all three express the same decision.
The predicate must be reevaluated at each call, not cached across state changes.

Declared instance-property reads, literals, named constants, `!`, `===`, `!==`,
`&&` and `||` are recognized. Formatting is ignored; operand order and literal
values remain significant. Immediately preceding local snapshots and direct alias chains
are normalized; intervening statements end that snapshot scope. Known inherited and trait
methods are composed across files. Aliases of one body count once. Private state and
relative `self`/`parent` constants retain lexical identity; late-bound `static` constants
belong to the effective receiver. Calls and arbitrary computed locals remain excluded.
Unrelated inheritance, traits, magic methods or hooked properties do not suppress checking
ordinary declared properties of the class.
A property with its own hook remains outside the recognized read vocabulary. Conditions inside nested callables belong to their
own scope. Repetition within one method counts once. Independent decisions should
not be merged merely because their expressions currently match.

### no_identical_branches

Enabled by default: `new NoIdenticalBranches()` compares the results of explicit
`match` arms and the two branches of a full ternary. Identical printed syntax
ignores formatting and comments; argument order and scalar types remain significant.
One diagnostic per repeated result points to its second occurrence, lists all
locations, and reports value = number of extra occurrences with threshold 0.

```php
// Reported: two explicit arms repeat the result.
$result = match ($channel) {
    Channel::Email => send($message),
    Channel::Sms => send($message),
};

// Accepted: preserve selection and unmatched-value behavior.
$result = match ($channel) {
    Channel::Email, Channel::Sms => send($message),
};
```

Default arms, shorthand ternaries, statement branches and `switch` fall-through
are excluded. Results containing magic constants, closures, arrow functions or
anonymous classes are excluded because source location or declaration identity can
matter. Nested expressions are still checked independently. This is a structural
review signal, not proof that separate cases have the same business meaning.
There is no automatic fix: retain condition effects, evaluation order, selection
and exceptions for unmatched values. A warning still fails an enabled quality check.

### no_nested_ternary

Enabled by default: `new NoNestedTernary()` reports a full ternary directly used
as a result branch of another full ternary. Each outer decision is reported once
at its starting line with value 1 and threshold 0, even if both branches are nested.

```php
// Reported.
$price = $premium ? ($annual ? 100 : 10) : 20;

// Accepted: name the inner decision and retain lazy evaluation.
$price = $premium ? premiumPrice($annual) : 20;
```

Single ternaries, shorthand `?:`, `??` chains, nesting in a condition and decisions
inside call arguments are outside this narrow policy. Callable bodies are checked
independently; a ternary inside a closure is not a branch of its enclosing expression.
There is no automatic fix. Do not eagerly compute a previously conditional branch
when extracting it. Both checks run through the existing catalog, `init`,
`list:rules` and JSON schema 4 without additional configuration.


### max_statements_per_callable

The standard set includes `new MaxStatementsPerCallable(20)`, limiting executable
statements independently of line breaks and comments. The threshold is inclusive; zero accepts only empty
bodies, and negative limits are rejected. Methods (including trait and anonymous
class methods), named functions, closures and arrow functions are checked separately.
An arrow function counts as one implicit return statement.

Expression statements, returns, throws, loop controls and control structures
count once each; statements within control bodies count too. For example,
`if ($ready) { execute(); }` counts as two statements. `else`, `elseif`, `case`,
`catch` and `finally` are wrapper clauses and do not add a count, but their bodies
do. Empty statements and comments do not count. Nested class and function
declarations do not add to their enclosing callable; their methods and functions
are evaluated independently. File-level code is outside this rule.

```php
use Cosmira\Soda\Rules\Complexity\MaxStatementsPerCallable;

new MaxStatementsPerCallable(20);
```

Diagnostics identify the callable declaration line and measured count. This is
a size signal, not proof of separate responsibilities: extract a step
when its name clarifies the operation, rather than introducing forwarding helpers
solely to lower the count.


### no_assignment_in_return

The standard set includes `NoAssignmentInReturn`. It reports ordinary, reference
and compound assignments evaluated within a return expression, including call
arguments, array elements, ternary branches and match results. Arrow-function
expressions are implicit returns and follow the same policy. Each return is
reported once at the return keyword (or arrow-function declaration) line.

```php
// Rejected: the return also changes state.
return $this->value = calculate();

// Accepted: the state change is explicit.
$this->value = calculate();
return $this->value;
```

Returning a closure does not attribute its deferred assignments to the outer
return. Explicit returns and arrow functions within that closure are checked
independently. Anonymous-class bodies are also separate; constructor arguments
still belong to the surrounding expression. Bare returns, comparisons and
increments/decrements are outside this rule. No automatic fix is offered: moving
assignments out of expressions must preserve conditional evaluation, evaluation
order and reference semantics.

### no_chained_assignments

The standard set includes `NoChainedAssignments`. It rejects an assignment whose
immediate right-hand expression is another ordinary, reference or compound
assignment. Parentheses do not change that syntax boundary. A contiguous chain
is reported once at the outermost assignment line, including in file-level code,
methods, functions, closures and arrow functions.

```php
// Rejected: two assignments in one direct chain.
$first = $second = calculate();

// Accepted: evaluate once and make both writes explicit.
$second = calculate();
$first = $second;
```

`$first += $second = calculate()` and `$first = $second =& $source` are also
reported. An assignment nested in a call argument or ternary result is not a
direct chain: `$first = consume($second = calculate())` is outside this rule.
Separate assignments and ordinary compound updates remain accepted. There is no
automatic fix: property/array targets, side-effecting expressions and references
require preserving PHP's original evaluation and aliasing behavior.

These checks complement `NoAssignmentInCondition`, which covers mutation in
control-flow conditions. A chain within a return can legitimately trigger both
new policies, with one finding per rule.

### no_unneeded_ternary

Standard `new NoUnneededTernary()` reports opposite literal boolean branches,
or one literal branch paired with an expression whose PHP result is provably bool.
The latter can use `&&` or `||` while preserving condition and branch evaluation.
Use `(bool) $condition` or `! $condition` to preserve the boolean result type and
single evaluation. Shorthand ternaries and nonliteral results are outside this
policy. See [boolean ternaries](EXPRESSION_AND_LOCAL_STATE_RULES.md#nounneededternary).

### no_return_in_finally

Standard `new NoReturnInFinally()` reports explicit returns owned by a finally
block, including its nested control flow. Deferred callables and class bodies
have independent ownership. See [finally returns](EXPRESSION_AND_LOCAL_STATE_RULES.md#noreturninfinally)
for exception suppression and safe cleanup examples.

### max_consecutive_local_assignments

Standard `new MaxConsecutiveLocalAssignments(8)` allows eight consecutive first simple
local assignments in each direct callable body. It reports maximal longer runs
once, ignores comments/empty statements, and reserves input and previously
encountered bindings. See [local preparation runs](EXPRESSION_AND_LOCAL_STATE_RULES.md#maxconsecutivelocalassignments)
for reset conditions and nested-body boundaries.

### no_repeated_string_literals

Standard `new NoRepeatedStringLiterals(3)` reports expression strings occurring at least
three times in one class-like owner, with a default minimum length of three bytes.
Defaults, constants and metadata are excluded. Intentional repeated strings can
be configured with `ignoredStrings`. See [repeated literals](EXPRESSION_AND_LOCAL_STATE_RULES.md#norepeatedstringliterals)
for exact ownership, counting and threshold semantics.

### no_declarations_before_early_exit

Standard `new NoDeclarationsBeforeEarlyExit()` reports statement-level assignments
declared before a guard clause whose every branch ends with `return`, `throw`,
`break` or `continue`, when the declared variable is unread between the declaration
and the guard (including inside the guard itself) but read somewhere after it.
One diagnostic points at the declaration line and names the guard line. Reads
inside the guard condition or body keep the declaration in place; closure captures
count as reads. Loops take part through `continue` and `break` guards in their bodies.

```php
// Reported: the report is computed even when the caller is gone.
$report = $this->compile($request);
if (! $request->user()->canSeeReports()) {
    return abort(403);
}
return $report;

// Accepted: the guard comes first, the work follows.
if (! $request->user()->canSeeReports()) {
    return abort(403);
}
return $this->compile($request);
```

There is no automatic fix: keep evaluation order and reference semantics when
moving a declaration below its guard.

### prefer_combined_guards

Standard `new PreferCombinedGuards()` reports adjacent `if` statements without
`else` whose body is exactly one `return`, `throw`, `break` or `continue` and whose
exits match by kind and printed expression. One diagnostic per pair points at the
second guard. Combining the conditions with `||` preserves condition evaluation
order and short-circuit behavior.

```php
// Reported: the same exit is written twice.
if ($build->failed) {
    return;
}
if ($build->cancelled) {
    return;
}

// Accepted: one guard answers one question.
if ($build->failed || $build->cancelled) {
    return;
}
```

Exits whose expressions assign while returning or throwing, bodies with more than
one statement, `elseif` chains and non-adjacent guards are excluded.

### prefer_continue

Reports a trailing `if` without alternatives only when it wraps nested executable
control flow in the same scope and does not already exit on all proven paths.
Flat positive branches and early continues are both accepted. Preceding statements
must stay before the guard; jump levels must stay unchanged. See
[examples and boundaries](rules/prefer_continue.md).


### prefer_null_coalesce_assignment

Standard `new PreferNullCoalesceAssignment()` reports assignments whose right side
is a null coalesce whose left operand prints exactly like the assignment target:
`$value = $value ?? $fallback`, `$cache['key'] = $cache['key'] ?? []` and property
fetches all qualify. The `??=` operator assigns the fallback in one step and
evaluates the target once.

```php
// Reported: the target is spelled twice.
$this->scope = $this->scope ?? 'web';

// Accepted: one operator carries the fallback.
$this->scope ??= 'web';
```

Targets evaluated through calls stay outside the comparison, because the merged
operator would evaluate them once instead of twice.

## Third-party material

Soda's own code is licensed under MIT. Adapted third-party material retains the
notice and terms below; no Java or Python runtime dependency is introduced.

### Aibolit test scenarios

Source: [cqfn/aibolit](https://github.com/cqfn/aibolit/tree/1de37051b74fc61ce9313f350487d5807db732db),
commit `1de37051b74fc61ce9313f350487d5807db732db`.
Copyright (c) 2019–2026 Aibolit. [Full MIT license](#aibolit-license).

Destination: `tests/unit/ExceptionChecksTest.php`. Its file notice covers the
adapted scenarios. Source files inspected and adapted:

- `test/patterns/empty_rethrow/Simple.java`: direct bound-variable rethrow.
- `test/patterns/empty_rethrow/CatchWithFunctions.java`: statements before throw.
- `test/patterns/empty_finally/Empty.java`: finally with no statements.
- `test/patterns/empty_finally/NoFinally.java`: catch without finally.
- `test/patterns/empty_finally/NotEmpty.java`: an actual cleanup statement.

Java declarations and IO calls were replaced with minimal PHP syntax. Logging or
other work before rethrow is deliberately **negative** in Soda, unlike Aibolit's
broader EmptyRethrow pattern. Additional sibling catch, union, scope, comment,
trait, anonymous-class and nested-block cases specify Soda's PHP contract.
Production detectors are independently implemented against nikic's AST.

No third-party application fixture (including DBeaver's Apache-licensed files)
was copied. The donors' full test suites were not used as an oracle or executed
as part of Soda's tests.

#### Aibolit license

```text
(The MIT License)

Copyright (c) 2019-2026 Aibolit

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the 'Software'), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED 'AS IS', WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

### Research definitions and source inspection

The cognitive and cohesion calculators and their PHP tests are independently
implemented. Sonar's specification, Aibolit and jPeek informed the comparison and
boundary cases; no Sonar implementation or donor graph code was copied. Pinned
links, formula differences and limitations are recorded in
[Research Metrics](RESEARCH_METRICS.md).

The [corpus manifest](research/corpus.json) pins read-only source snapshots.
Review records contain measurements, original commentary and source links, not
copies of upstream application files. No external corpus dependency is loaded
into Soda's runtime or distributed as an application fixture.
