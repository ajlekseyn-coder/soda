# Packagist Laravel audit — 2026-10-15

`packages.json` pins all 82 names returned by Packagist's Laravel vendor API,
including abandoned packages and aliases. Selection uses the newest stable
release in Packagist's p2 ordering, or a development reference when no stable
release exists. Package versions and commit references are immutable audit inputs.

The source archive for `laravel/liferaft` returns HTTP 404. The other 81 packages
were scanned separately with the standard rule catalog. Pint and Vet omit their
application source from Composer distributions; their matching Git tags supplied
that source after verifying the pinned commit. Bundled PHARs are excluded.

The 5,875 selected PHP files produced 62,672 baseline diagnostics and 62,280 after
twelve review rounds: 392 resolved and one existing diagnostic revised. No independent
new finding was introduced. The raw diagnostic multiset contains 393 removals and
one addition: the revised Cloud CLI dispatch group now names three methods instead
of four. All unchanged diagnostics match field-for-field with their original
multiplicity.

| Rule | Before | After | Removed |
| --- | ---: | ---: | ---: |
| `no_unused_parameters` | 950 | 722 | 228 |
| `no_declarations_before_early_exit` | 171 | 150 | 21 |
| `useless_variable` | 6 | 3 | 3 |
| `prefer_null_coalesce_assignment` | 16 | 15 | 1 |
| `no_catch_all_exceptions` | 221 | 218 | 3 |
| `no_consecutive_block_comments` | 8 | 4 | 4 |
| `no_singleton_access` | 181 | 95 | 86 |
| `no_trivial_factories` | 127 | 87 | 40 |
| `no_repeated_type_dispatch` | 40 | 34 | 6 |

Removed diagnostics include proved false positives and conservative suppression
where an implicit consumer cannot be ruled out. This does not reclassify every
remaining finding as a confirmed violation. The full test suite passes with
2,319 tests and three existing external-corpus skips; soda's self-check, PHPStan
and Pint also pass.

## Source-grounded policy review

`verified-policies.json` records 30,747 findings checked against the pinned source:

| Policy | Findings | Evidence |
| --- | ---: | --- |
| `max_line_length` | 10,130 | Physical byte length exceeds the standard 100-byte limit. |
| `no_untyped_parameters` | 20,389 | The parameter declaration has no native type. |
| `no_reflection` | 227 | Construction resolves to a native reflection class or a source-proved subclass. |
| `no_dynamic_properties` | 1 | The attribute resolves to PHP's global `AllowDynamicProperties`. |

The verifier reads all 5,875 selected files, checks every source hash, recomputes
measurements and AST declarations without invoking Soda rules, and compares exact
point multiplicities. Reflection subclasses require a declared, unambiguous path
to a native reflection type. SHA-256 digests cover the verifier, pinned manifest,
source set and each policy's diagnostic multiset. Deliberately forged line-length
and parameter-name diagnostics were rejected during verification.

Together with the 176 individually reviewed findings in `reviewed-unchanged.jsonl`,
this leaves 31,357 retained findings unclassified. These policy checks do not prove
the behavior of the remaining semantic rules.

After reproducing the corpus, run:

```sh
php docs/research/laravel-packagist/verify-policies.php /tmp/soda-laravel-recheck
```

For reports stored separately from source archives, pass the `sources` directory
as the second argument. The script prints its verification summary as JSON and
fails when source hashes or policy evidence differ.

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
scanned. Dependencies are not installed. Package-local PSR-4 mappings, including
those declared for development, can supply reachable contract declarations outside
the selected file list; these declarations are parsed without being linted or
executed. Installed dependency metadata can provide external source when present.
Loaded PHP internal classes and interfaces also provide native signature metadata
without application autoloading.

Each package report contains the exact source paths and SHA-256 hashes, the full
diagnostic list, time and peak memory. `summary.json` records completed and failed
packages. The command exits nonzero when a package cannot be obtained or analyzed;
quality findings themselves are expected and do not stop the corpus run. Liferaft's
missing archive therefore currently causes a nonzero aggregate exit.

`results.json` records the before/after diagnostic counts and a SHA-256 digest of
each source list (`path + NUL + file SHA-256 + newline`, sorted by path).
`resolved.jsonl` preserves the 392 resolved diagnostics and their source hashes.
`round2-resolved.jsonl` isolates the 81 additional resolutions from the second round.
`round3-resolved.jsonl` records 40 native contract corrections: 18 for `ArrayAccess`,
19 for `SessionHandlerInterface`, and three for `php_user_filter`.
`round4-resolved.jsonl` records six snapshots that must precede input mutation
in Laravel AI, Cashier Paddle, Framework and Scout.
`round5-resolved.jsonl` records the Cashier property assignment whose model setter
must remain observable even when its current value is non-null.
`round6-resolved.jsonl` records five Reverb factory inputs read after a match whose
default arm throws; a successful explicit arm still reaches those reads.
`round7-resolved.jsonl` records three Octane/Vapor catches that terminate the
process with `exit(1)` rather than continuing.
`round8-resolved.jsonl` records four adjacent Chisel section markers in the Svelte
starter kit. Chisel's pinned `Filesystem/File.php` consumes each marker as a
separate block comment; the ledger includes that consumer's source hash.
`round9-resolved.jsonl` records the Blade compiler's loop-counter increment that
must still execute before a malformed-expression guard throws.
`round10-resolved.jsonl` records 86 accessor calls whose names do not prove shared
object storage. This includes value factories and conservative suppression when
the target declaration or its sharing behavior is unresolved; it does not assert
that external containers construct fresh objects. Targets are recorded using
resolved imports, with dynamic receivers left unknown.
`round10-reviewed.jsonl` records 95 retained singleton accesses: 88 to Framework's
container, one to `Once`, and six to Folio's metadata. Each has a pinned declaration
returning an object cached with `??=` in a declared static slot. The ledger records
the caller and declaration hashes, method, return and storage lines.
`round11-resolved.jsonl` records 15 factory hooks in Framework and Dusk, with 24
observed virtual call sites. Every entry records the enclosing factory kind and
caller methods, lines and receiver form; their source hash covers both factory
and call sites. Construction is transparent, but bypassing the method would
remove its replaceable implementation.
`round12-resolved.jsonl` records 25 local factory contracts in Cashier Mollie,
Framework and LSP: 23 inherited signatures and two registered driver factories.
Each entry records hashes of the package's Composer manifest and the verified
ancestor source, with the method or dispatch location. Package-local PSR-4 roots
supply these declarations without requiring an installed autoloader.
`revised.jsonl` retains both versions of the Cloud CLI finding;
`reviewed-unchanged.jsonl` records 176 retained findings: 29 string-policy findings,
52 recommendations about repeated branches and boolean expressions, and the 95
source-proved singleton accesses. Changes
are compared as diagnostic multisets, including message, location and multiplicity.
The baseline uses the original soda source plus the one-line `switch` crash fix:
the original Runner cannot finish packages containing affected switches.

## Corrections

- `ControlBodies` no longer reads a nonexistent `Switch_::$stmts`; each `case`
  retains its own statement list.
- `no_unused_parameters` preserves PHP's fixed magic signatures, implicit reads
  through `compact()`, argument introspection and included files. Recovery paths
  in `catch`/`finally` and alternative switch cases are analyzed independently.
  Match arms also retain independent paths, with short-circuit conditions and
  default selection after all explicit conditions.
  Anonymous classes preserve their declared interfaces and parent contracts with
  file-scoped identities; extra parameters remain detectable. Loaded internal PHP
  ancestors supply their native signatures without loading application classes.
- `no_declarations_before_early_exit` preserves JSON operations observed through
  `json_last_error()`/`json_last_error_msg()`, reference bindings, and callables
  containing `goto`, whose execution order cannot be inferred lexically. Earlier
  snapshots also stay before writes to their inputs, including removal of array
  keys. Writes confined to an exiting guard branch preserve the surviving path.
  Initializers with explicit writes, yields, output or termination also stay in
  place. Deferred callable bodies do not contribute eager effects.
- `useless_variable` preserves aliases whose names may be consumed by included
  code, dynamic variable access or symbol-table operations. It also follows writes
  through loop/catch bindings and nested destructuring assignments.
- `no_repeated_type_dispatch` retains scalar selector identity instead of grouping
  unrelated operations by equal literals. Computed locals and distinct private
  field owners do not establish the same discriminator.

- `prefer_null_coalesce_assignment` preserves writes through unknown, hooked or
  readonly properties. Only proved ordinary fields remain eligible. Calls,
  assignments and increments in a target prevent collapsing its evaluations.

- `no_catch_all_exceptions` accepts native process termination. A later
  unreachable bare return does not replace an unconditional catch outcome.

- `no_consecutive_block_comments` preserves ordinary blocks containing only a
  tool directive. Joining section markers changes how source tools recognize
  section boundaries. PHPDoc tag blocks and ordinary prose remain checked.

- `no_singleton_access` requires resolved shared-object storage rather than an
  accessor name. Scalar method facts survive inheritance and trait selection;
  unknown declarations, overrides returning fresh values and callable references
  do not establish a singleton. Duplicate declarations remain ambiguous. The
  demo's fresh-object factory is now a negative case; real cached-object controls
  continue to trigger the rule.

- `no_trivial_factories` preserves factories reached by observed virtual calls
  on the current receiver. Late-static lookup and trait composition preserve
  replaceable implementations; final class/method controls remain detectable.
  Trait declarations also contribute resolved factory-hook contracts. The closest
  package manifest supplies local ancestors before consulting installed dependency
  metadata; executable autoload files remain untouched.

Regression cases live in the corresponding rule test classes:
`NoUnusedParametersTest`, `NoDeclarationsBeforeEarlyExitTest` and
`UselessVariableRuleTest`, together with `NoRepeatedTypeDispatchTest` and
`PreferNullCoalesceAssignmentTest`, `NoCatchAllExceptionsTest` and
`NoConsecutiveBlockCommentsTest`, `NoSingletonAccessTest` and
`NoTrivialFactoriesTest`. Controls
continue to require genuine violations. Cross-file anonymous contracts and
inherited selectors are also exercised in reverse file order.

This is an audit of the pinned production source set, not a claim that every
remaining diagnostic has been individually reviewed. Remaining findings include
intentional soda policies as well as candidates needing additional evidence.
The existing six-package ledger in `../laravel-audit` remains a separate snapshot.
