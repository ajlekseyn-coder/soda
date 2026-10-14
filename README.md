# Soda

Soda checks PHP code for oversized methods, complex conditions, naming problems,
and structural issues. It reports what failed and where, with a non-zero exit
status when violations are found.

Requires PHP 8.2 or higher.

```bash
composer require --dev cosmira/soda
vendor/bin/soda quality src/
```

The first check needs no configuration: Soda uses its standard rules when it
finds no `soda.php`. Vendor directories are excluded automatically.

## Configure once, run daily

Create `soda.php` in your project root. Choose named classes and pass their options:

```php
<?php

use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\MaxCyclomaticComplexity;
use Cosmira\Soda\Rules\Structure\MaxArguments;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxCyclomaticComplexity(10),
        new MaxArguments(5),
    ]);
```

Then run:

```bash
vendor/bin/soda
```

Only the listed rules run. Remove a rule to disable it. To use the whole standard
set, pass `RuleCatalog::standard()` to `with()`; import
`Cosmira\Soda\Config\RuleCatalog`. `vendor/bin/soda init` can generate an expanded,
editable configuration equivalent to `RuleCatalog::standard()`, including its
thresholds and readonly-data and boolean-method policies. The standard bundle also
includes `RepresentationChurn` research: its findings appear separately and do not
fail the quality gate. Save their evidence with `--research-json=build/soda-research.json`.
Optional rules remain opt-in.

## Useful commands

| Command | Purpose |
| --- | --- |
| `vendor/bin/soda` | Check the paths in `soda.php` |
| `vendor/bin/soda quality src/ tests/` | Check explicit paths |
| `vendor/bin/soda --config=tools/soda.php` | Select a configuration |
| `vendor/bin/soda --report-json=quality.json` | Save a JSON report for CI |
| `vendor/bin/soda quality src/ --exclude=generated` | Exclude a directory; repeat for more exclusions |
| `vendor/bin/soda list:rules` | Inspect available rules and defaults |
| `vendor/bin/soda --help` | Show check options |

Use the same check command in CI. Exit status `0` means no violations; `1` means
a failed check or a configuration/input error. Soda complements a formatter and
PHPStan or Psalm by checking code structure and maintainability.

## Learn more

- [Configuration](docs/SODA_PHP_CONFIG.md): paths, thresholds and rule sets.
- [Rules and examples](docs/README.md): structure, complexity and naming.
- [Custom rules](docs/PLUGINS.md): implement a named PHP class.
- [Report JSON](docs/QUALITY_REPORT_JSON.md): machine-readable results.
- [Project flow](docs/PROJECT_FLOW.md): where parsing, checks and reporting happen.
- [Design philosophy](docs/DESIGN_PHILOSOPHY.md): concerns, fluent APIs and value objects with executable examples.
- [Research metrics](docs/RESEARCH_METRICS.md) and [100-finding corpus review](docs/research/CORPUS_REVIEW.md): standard cognitive complexity; cohesion remains measurement only.
- [Migration](docs/MIGRATION.md): changed namespaces and extension contracts.

Rule authors can inspect available facts with `vendor/bin/soda list:metrics`.
Expression syntax is an optional implementation tool inside a rule class.

## Development

Clone this repository and run `composer install`. Inside the Soda repository,
use `php soda` in place of `vendor/bin/soda`. Run `composer test` for behavioral
tests and `composer ci` for the full project checks.

Licensed under the [BSD 3-Clause License](LICENSE).

### Explicit behavior checks

The standard set also reports internal factories that only forward construction
arguments (`NoTrivialFactories`) and compound own-state conditions repeated in
three or more methods (`NoRepeatedCompoundConditions`). These are opinionated
structural checks: they do not prove an abstraction is useless or two decisions
have the same meaning. See the [factory examples](docs/rules/no_trivial_factories.md) and
[repeated-condition examples](docs/rules/no_repeated_compound_conditions.md).

The standard set also includes `NoIdenticalBranches` (repeated explicit `match`
results and identical full ternary branches) and `NoNestedTernary` (a full ternary
directly inside another full ternary's result branch). Both have narrow syntax
boundaries and no automatic fixes. See the [examples](docs/COMPLEXITY_READABILITY_METRICS.md#no_identical_branches).

The standard set also includes `NoAssignmentInReturn` and `NoChainedAssignments`:
make state changes explicit instead of hiding them in returned expressions or
combining multiple writes into one chain. See [return assignments](docs/COMPLEXITY_READABILITY_METRICS.md#no_assignment_in_return)
and [assignment chains](docs/COMPLEXITY_READABILITY_METRICS.md#no_chained_assignments).

### Owner behavior recommendations

Six standard checks identify copied or scattered concerns, fragmented owner
operations, repeated object configuration, and repeated decisions or updates over foreign
state. Their diagnostics suggest traits, owner methods, or fluent objects with
source evidence. See [configuration, examples, and boundaries](docs/OWNER_BEHAVIOR_RULES.md).


### Expressions and local state

The standard set also includes `NoUnneededTernary` (opposite literal boolean
branches) and `NoReturnInFinally` (returns that can override pending results or
exceptions), `NoReassignedParameters`, `MaxConsecutiveLocalAssignments(8)` and
`NoRepeatedStringLiterals(3)`. All five run by default. Repeated-literal checks exclude literal array keys,
parameter checks accept a narrow entry normalization, and return-assignment checks
accept direct lazy initialization with `??=`, named owner-state returns and concise
arrow state callbacks. Each exception has a strict-mode option. See the
[calibration against Soda and pinned Laravel sources](docs/research/readability-calibration/README.md).
See [Expressions and local state](docs/EXPRESSION_AND_LOCAL_STATE_RULES.md) for
configuration, examples, scope boundaries and intentional exceptions.

### Statement limits

The standard set includes `MaxStatementsPerCallable(20)`: at most 20 executable
statements per function, method or closure, counted independently of formatting.
Nested callables have separate counts. See [statement counting](docs/COMPLEXITY_READABILITY_METRICS.md#max_statements_per_callable)
for the precise boundaries and threshold configuration.

### Namespace dependency restrictions

`ForbiddenDependencies` is opt-in because each project defines its own module
boundaries. Map source namespace prefixes to target prefixes they must not depend on:

```php
<?php

use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\ForbiddenDependencies;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        ...RuleCatalog::standard(),
        new ForbiddenDependencies([
            'App\Domain' => ['App\Infrastructure', 'App\Http'],
            'App\Support' => ['App\Domain'],
        ]),
    ]);
```

Here `App\Domain` cannot reference types in `App\Infrastructure` or `App\Http`,
including their descendant namespaces. The reverse direction remains allowed.
The rule checks direct class and trait type references, even without a dependency
cycle. Unused imports and dynamic string references are outside this check.
See [module restrictions](docs/STRUCTURAL_METRICS.md#forbidden_dependencies-opt-in)
for matching semantics and analysis boundaries.

The standard bundle includes `NoEmptyFinallyBlocks`, `MaxCognitiveComplexity(15)`,
`MaxEfferentCoupling(15)`, and `NoDependencyCycles()`.
