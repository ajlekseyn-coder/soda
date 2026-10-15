# Packagist Laravel audit — 2026-10-15

`packages.json` pins all 82 names returned by Packagist's Laravel vendor API,
including abandoned packages and aliases. Selection uses the newest stable
release in Packagist's p2 ordering, or a development reference when no stable
release exists. Package versions and commit references are immutable audit inputs.

The source archive for `laravel/liferaft` returns HTTP 404. The other 81 packages
were scanned separately with the standard rule catalog. Pint and Vet omit their
application source from Composer distributions; their matching Git tags supplied
that source after verifying the pinned commit. Bundled PHARs are excluded.

The 5,875 selected PHP files produced 62,672 baseline diagnostics and 62,419 after
five review rounds: 253 resolved and one existing diagnostic revised. No independent
new finding was introduced. The raw diagnostic multiset contains 254 removals and
one addition: the revised Cloud CLI dispatch group now names three methods instead
of four. All unchanged diagnostics match field-for-field with their original
multiplicity.

| Rule | Before | After | Removed |
| --- | ---: | ---: | ---: |
| `no_unused_parameters` | 950 | 727 | 223 |
| `no_declarations_before_early_exit` | 171 | 151 | 20 |
| `useless_variable` | 6 | 3 | 3 |
| `prefer_null_coalesce_assignment` | 16 | 15 | 1 |
| `no_repeated_type_dispatch` | 40 | 34 | 6 |

Removed diagnostics include proved false positives and conservative suppression
where an implicit consumer cannot be ruled out. This does not reclassify every
remaining finding as a confirmed violation. The full test suite passes with
2,204 tests and three existing external-corpus skips; soda's self-check, PHPStan
and Pint also pass.

## Scope and reproduction

Run from the soda checkout with its existing dependencies:

```sh
python3 docs/research/laravel-packagist/audit.py /tmp/soda-laravel-recheck
```

The script requires PHP, Git, Python's standard library and network access. It
downloads pinned sources, reads Composer's JSON autoload roots and PHP binaries,
and invokes the standard Runner. Package code, Composer scripts, plugins and
upstream `soda.php` files are never executed. Tests, vendor directories, Blade
views, resources and scaffolding stubs are excluded. When no production autoload
roots or PHP binaries exist, the remaining PHP files under the package root are
scanned. Dependencies are not installed; external contracts are available only
when their declarations occur in the selected source set. Loaded PHP internal
classes and interfaces also provide native signature metadata without application
autoloading.

Each package report contains the exact source paths and SHA-256 hashes, the full
diagnostic list, time and peak memory. `summary.json` records completed and failed
packages. The command exits nonzero when a package cannot be obtained or analyzed;
quality findings themselves are expected and do not stop the corpus run. Liferaft's
missing archive therefore currently causes a nonzero aggregate exit.

`results.json` records the before/after diagnostic counts and a SHA-256 digest of
each source list (`path + NUL + file SHA-256 + newline`, sorted by path).
`resolved.jsonl` preserves the 253 resolved diagnostics and their source hashes.
`round2-resolved.jsonl` isolates the 81 additional resolutions from the second round.
`round3-resolved.jsonl` records 40 native contract corrections: 18 for `ArrayAccess`,
19 for `SessionHandlerInterface`, and three for `php_user_filter`.
`round4-resolved.jsonl` records six snapshots that must precede input mutation
in Laravel AI, Cashier Paddle, Framework and Scout.
`round5-resolved.jsonl` records the Cashier property assignment whose model setter
must remain observable even when its current value is non-null.
`revised.jsonl` retains both versions of the Cloud CLI finding;
`reviewed-unchanged.jsonl` records 81 retained findings: 29 string-policy findings
and 52 recommendations about repeated branches and boolean expressions. Changes
are compared as diagnostic multisets, including message, location and multiplicity.
The baseline uses the original soda source plus the one-line `switch` crash fix:
the original Runner cannot finish packages containing affected switches.

## Corrections

- `ControlBodies` no longer reads a nonexistent `Switch_::$stmts`; each `case`
  retains its own statement list.
- `no_unused_parameters` preserves PHP's fixed magic signatures, implicit reads
  through `compact()`, argument introspection and included files. Recovery paths
  in `catch`/`finally` and alternative switch cases are analyzed independently.
  Anonymous classes preserve their declared interfaces and parent contracts with
  file-scoped identities; extra parameters remain detectable. Loaded internal PHP
  ancestors supply their native signatures without loading application classes.
- `no_declarations_before_early_exit` preserves JSON operations observed through
  `json_last_error()`/`json_last_error_msg()`, reference bindings, and callables
  containing `goto`, whose execution order cannot be inferred lexically. Earlier
  snapshots also stay before writes to their inputs, including removal of array
  keys. Writes confined to an exiting guard branch preserve the surviving path.
- `useless_variable` preserves aliases whose names may be consumed by included
  code, dynamic variable access or symbol-table operations. It also follows writes
  through loop/catch bindings and nested destructuring assignments.
- `no_repeated_type_dispatch` retains scalar selector identity instead of grouping
  unrelated operations by equal literals. Computed locals and distinct private
  field owners do not establish the same discriminator.

- `prefer_null_coalesce_assignment` preserves writes through unknown, hooked or
  readonly properties. Only proved ordinary fields remain eligible. Calls,
  assignments and increments in a target prevent collapsing its evaluations.

Regression cases live in the corresponding rule test classes:
`NoUnusedParametersTest`, `NoDeclarationsBeforeEarlyExitTest` and
`UselessVariableRuleTest`, together with `NoRepeatedTypeDispatchTest` and
`PreferNullCoalesceAssignmentTest`. Controls
continue to require genuine violations. Cross-file anonymous contracts and
inherited selectors are also exercised in reverse file order.

This is an audit of the pinned production source set, not a claim that every
remaining diagnostic has been individually reviewed. Remaining findings include
intentional soda policies as well as candidates needing additional evidence.
The existing six-package ledger in `../laravel-audit` remains a separate snapshot.
