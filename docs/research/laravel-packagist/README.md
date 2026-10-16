# Packagist Laravel audit — 2026-10-15

`packages.json` pins all 82 names returned by Packagist's Laravel vendor API,
including abandoned packages and aliases. Selection uses the newest stable
release in Packagist's p2 ordering, or a development reference when no stable
release exists. Package versions and commit references are immutable audit inputs.

The source archive for `laravel/liferaft` returns HTTP 404. The other 81 packages
were scanned separately with the standard rule catalog. Pint and Vet omit their
application source from Composer distributions; their matching Git tags supplied
that source after verifying the pinned commit. Bundled PHARs are excluded.

The 5,875 selected PHP files produced 62,672 baseline diagnostics and 61,518 after
twenty-six review rounds: 1,154 resolved and 54 existing diagnostics revised. No independent
new finding was introduced. The raw diagnostic multiset contains 1,208 removals and
54 additions: the Cloud CLI dispatch group now names three methods instead of four,
42 proven-empty interfaces have a more precise message, seven commented-code
ordinals exclude preceding prose, and four ask-then-tell messages retain their
indexed receiver. All other retained diagnostics match field-for-field with their
original multiplicity.

| Rule | Before | After | Removed |
| --- | ---: | ---: | ---: |
| `max_ask_then_tell_patterns` | 234 | 214 | 20 |
| `no_dynamic_invocation` | 506 | 505 | 1 |
| `max_commented_out_code_lines` | 183 | 91 | 92 |
| `no_unused_parameters` | 950 | 250 | 700 |
| `no_assignment_in_return` | 203 | 197 | 6 |
| `no_mode_parameters` | 53 | 50 | 3 |
| `no_reassigned_parameters` | 2,357 | 2,353 | 4 |
| `no_empty_local_interfaces` | 82 | 42 | 40 |
| `no_declarations_before_early_exit` | 171 | 39 | 132 |
| `useless_variable` | 6 | 3 | 3 |
| `prefer_null_coalesce_assignment` | 16 | 15 | 1 |
| `no_catch_all_exceptions` | 221 | 217 | 4 |
| `no_consecutive_block_comments` | 8 | 4 | 4 |
| `no_singleton_access` | 181 | 95 | 86 |
| `no_unused_private_state` | 2 | 1 | 1 |
| `multiline_property_phpdoc` | 4,637 | 4,636 | 1 |
| `no_trivial_factories` | 127 | 77 | 50 |
| `no_repeated_type_dispatch` | 40 | 34 | 6 |

Removed diagnostics include proved false positives and conservative suppression
where an implicit consumer cannot be ruled out. This does not reclassify every
remaining finding as a confirmed violation. The full test suite passes with
2,830 tests and three existing external-corpus skips; soda's self-check, PHPStan
and Pint also pass.

Round 24 removes Horizon’s `SupervisorFactory::make()` finding: the command
accepts an original native `SupervisorFactory` input and invokes its open `make()`
method. Direct construction would bypass a replacement implementation. The new
project facts retain scalar declarations and call sites; closed, ambiguous or
unobserved factories remain checked. `round24-resolved.jsonl` records the exact
finding and pinned source evidence. A single-consumer PHPDoc scanner was moved
into private methods of its collector without changing its parsing behavior.

Round 25 preserves seven parameter positions required by abstract traits in
Framework’s `HasOne`, `HasOneThrough` and `MorphOne`. This includes the protected
`SupportsDefaultModels::newRelatedInstanceFor()` contract. Selected concrete
trait bodies fulfill abstract requirements; class overrides, renamed methods,
visibility adaptations and intermediate traits retain their actual source origins.
Private parent methods cannot fulfill a child’s abstract trait requirement. Extra
positions and ordinary concrete trait signatures remain checked. The seven exact
diagnostics and pinned declarations are recorded in `round25-resolved.jsonl`.

Round 26 distinguishes a queried container from an indexed service, and preserves
literal or stable variable selectors in array and factory receiver paths. It removes
18 container-versus-service matches and two Pulse matches between `make('config')`
and `make(Pulse::class)`. Four genuine matches retain the same query and command
source positions while their messages now name the indexed service. Binding writes,
reference escapes and foreach evaluation order cannot establish an unchanged
variable selector. Exact source hashes and call spans are recorded in
`round26-resolved.jsonl` and `round26-revised.jsonl`; the other 210 retained matches
remain unclassified.

## Source-grounded policy review

`verified-policies.json` records 52,971 findings checked against the pinned source:

| Policy | Findings | Evidence |
| --- | ---: | --- |
| `max_line_length` | 10,130 | Physical byte length exceeds the standard 100-byte limit. |
| `no_untyped_parameters` | 20,389 | The parameter declaration has no native type. |
| `no_reflection` | 227 | Construction resolves to a native reflection class or a source-proved subclass. |
| `no_dynamic_properties` | 1 | The attribute resolves to PHP's global `AllowDynamicProperties`. |
| `only_list_arrays` | 1,359 | Nested array indexing outside the default request/resource path exclusions. |
| `no_assignment_in_condition` | 1,068 | A direct or compound assignment belongs to a control-flow condition within its callable scope. |
| `variable_name_length` | 5,506 | Literal variable identifier outside the inclusive 2–16 byte bounds, preserving each occurrence. |
| `method_name_length` | 252 | Method identifier outside the inclusive 1–32 byte bounds. |
| `class_name_length` | 126 | Named class-like identifier outside the inclusive 3–32 byte bounds. |
| `namespace_name_length` | 526 | Namespace segment outside the inclusive 3–32 byte bounds. |
| `multiline_method_phpdoc` | 6,110 | Declared method lacks a multiline PHPDoc description before its tags. |
| `multiline_property_phpdoc` | 4,636 | Declared or promoted property lacks its own description or an exact constructor parameter tag under a valid description. |
| `multiline_constant_phpdoc` | 276 | Declared constant lacks a multiline PHPDoc description before its tags. |
| `no_error_suppression` | 134 | Native error-suppression expression. |
| `no_runtime_includes` | 99 | Runtime include or require expression. |
| `no_runtime_code_evaluation` | 1 | Native eval expression. |
| `no_global_state` | 1 | A global declaration or direct global-symbol-table access. |
| `no_chained_assignments` | 82 | Outermost assignment in a chain, preserving expression identity. |
| `no_nested_ternary` | 44 | Full ternary directly nested in a full ternary's result branch. |
| `no_behavior_magic_methods` | 182 | Declared behavioral magic method, excluding ordinary lifecycle methods. |
| `no_anonymous_classes` | 46 | Anonymous class outside the exact migration-hook convention. |
| `no_else_branches` | 229 | Else or elseif follows branches that unconditionally leave the current path. |
| `max_namespace_depth` | 279 | Named class/trait namespace exceeds four segments. |
| `max_file_loc` | 27 | File exceeds 700 code-bearing lines, excluding PHP tags, comments and whitespace. |
| `max_class_length` | 49 | Named class/trait span exceeds 500 code-bearing lines. |
| `max_method_length` | 40 | Named concrete method or function span exceeds 100 code-bearing lines. |
| `max_classes_per_file` | 2 | File declares more than one named class/trait. |
| `max_cyclomatic_complexity` | 573 | Named callable decision inventory exceeds eight, with nested callable/type bodies excluded. |
| `max_cognitive_complexity` | 229 | Independent Soda PHP cognitive v1 measurement exceeds 15, preserving scope and evidence order. |
| `max_statements_per_callable` | 251 | Callable has more than 20 executable statements, excluding branch wrappers and nested declarations. |
| `max_control_nesting` | 97 | Named callable exceeds three control levels, with the deepest source line retained. |

The verifier reads all 5,875 selected files, checks every source hash, recomputes
measurements and AST declarations without invoking Soda rules, and compares exact
point multiplicities. Reflection subclasses require a declared, unambiguous path
to a native reflection type. SHA-256 digests cover the verifier, pinned manifest,
source set and each policy's diagnostic multiset. Deliberately forged line-length
and parameter-name diagnostics were rejected during verification. The extended
verifier also rejected a forged nested-index line and duplicated assignment
diagnostic. Condition membership is checked by walking AST ancestors up to the
nearest callable or type boundary, independently of Soda's rule traversal. Name
measurements preserve repeated occurrences, skip computed variable identifiers,
and verify both the reported length and bound. A forged variable length and a
duplicated namespace diagnostic were also rejected. Documentation checks derive
owners and declared member names from AST boundaries, then verify comment layout
and prose before tags. Promoted properties also use the constructor's exact outer
parameter name, with nested type delimiters and quoted keys kept separate. A
forged method owner, property name and duplicated constant finding were rejected.
This review exposed the multiline promoted-property false positive fixed in round
17; it was not accepted into the confirmed policy findings. The nine additional
syntax checks preserve occurrence multiplicity and verify exact messages, values
and bounds; forged values, lines, messages and duplicate findings were rejected. Five structural
measurements use independent PHP token line positions and declaration spans;
anonymous classes and interfaces follow the catalog's documented measurement scope.
The verifier compares owner, method, location, message, measurement and threshold.
Forged lengths, declaring owners and duplicated metric findings were rejected. The four
complexity checks independently inventory owned AST nodes, body ancestry and boolean
operator runs. All 1,150 diagnostics match in every field; forged owners, FQNs,
thresholds, values, lines, messages and duplicate occurrences were rejected.

Together with the 2,847 individually reviewed findings in `reviewed-unchanged.jsonl`
and 53 revised retained findings in `round18-reviewed.jsonl`,
`round22-reviewed-revised.jsonl` and `round26-revised.jsonl`, this leaves 5,647 retained
findings unclassified.
These policy checks do not prove the behavior of the remaining semantic rules.

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
executed. An enclosing bundle can supply sibling source roots when its `replace`
map explicitly names the closest nested package. Installed dependency metadata
can provide external source when present.
Loaded PHP internal classes and interfaces also provide native signature metadata
without application autoloading.

Each package report contains the exact source paths and SHA-256 hashes, the full
diagnostic list, time and peak memory. `summary.json` records completed and failed
packages. The command exits nonzero when a package cannot be obtained or analyzed;
quality findings themselves are expected and do not stop the corpus run. Liferaft's
missing archive therefore currently causes a nonzero aggregate exit.

`results.json` records the before/after diagnostic counts and a SHA-256 digest of
each source list (`path + NUL + file SHA-256 + newline`, sorted by path).
`resolved.jsonl` preserves the 1,134 resolved diagnostics and their source hashes.
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
`round13-resolved.jsonl` records 105 initializers that invoke functions, methods
or constructors. Each records its exact assignment hash and eager invocation
locations. Such calls may have effects on the exiting path, so relocation is not
justified without a purity proof. Two entries include concrete effect contexts:
Dusk must assert the query parameter exists even when no expected value is given;
Valet must execute redirected commands such as `brew update` while suppressing
their output. The remaining entries are conservative suppression of unproved
purity, not a claim that every callee changes state.
`round14-resolved.jsonl` records six property snapshots in AI, Framework, LSP,
MCP and Telescope. Each entry records the assignment hash and property-read
locations. Property getters or hooks may be observable, and intervening work may
change a snapshot. MCP has a concrete write witness: `captureSessionId()` changes
`sessionId` after the `hadSession` snapshot and before the proposed guard location;
moving the snapshot would change the later session-expiration decision. The other
five entries conservatively retain property reads without a proof of pure, stable
access.
`round15-resolved.jsonl` records nine contracts across bundled Framework components:
six JsonSchema interface signatures, two Image driver hooks and one Concurrency
hook. Entries retain hashes of the enclosing and nested Composer manifests and
the ancestor source. Each dispatch entry records a preceding selector definition
and call; Concurrency's later fallback reassignment cannot invalidate its earlier
factory invocation. Only explicit `replace` ownership supplies enclosing roots;
unrelated manifests and `require` entries do not create contracts.
`round16-resolved.jsonl` records Framework's emergency memory reserve. The private
static slot retains a generated 32 KiB string until a registered shutdown callback
clears it before reporting a processing failure. Its value need not be explicitly
read: releasing the retained allocation uses its storage lifetime. The ledger
records allocation, callback registration, release and dependent work locations
under the same source hash.
`round17-resolved.jsonl` records the promoted `config` property in Framework's
ExceptionReporter. Its constructor already has a multiline prose PHPDoc and an
exact `@param array{...} $config` entry. The type spans lines, so a single-line
matching pattern missed it. The ledger records the parameter, constructor,
comment hash and complete matching tag; callable parameters, quoted keys and tag
descriptions cannot substitute for the outer parameter name.
`round18-resolved.jsonl` records 174 inherited-contract corrections: 40 empty-interface
findings and 134 unused-parameter findings. Every entry includes declaration hashes,
ancestor paths and available contract witnesses. Of these, 66 have nonempty contracts
or required parameter positions corroborated by PHP internal metadata or pinned
sibling source; 108 conservatively suppress an accusation whose complete contract
is unavailable. Sibling source corroborates contract content without being treated
as an installed dependency binding. Private inputs and complete known signatures
remain checked, including surplus parameters. All 42 retained interfaces have
complete, empty method/property graphs and appear in `round18-reviewed.jsonl`.
`round19-resolved.jsonl` records 116 corrections: 109 positional contracts observed
through Surveyor's declared resolver factory family, three validation-only exception
branches and four leading input normalizations with literal replacement arrays.
Resolver records connect each real ancestor chain to an alias-resolved return bound
and a concrete positional method call. They establish a family protocol without
claiming that every subclass is returned at runtime or reads that input. The 33
DocBlock resolvers lack the corresponding factory bound and remain under review.
Literal normalization retains the existing consecutive entry-prefix restriction;
later writes, other inputs and computed array options remain checked. Throwing a
new exception does not add a productive dispatch operation; calls in constructor
arguments and before the throw remain operations.
`round23-resolved.jsonl` records 21 Schema collaborator contracts. A native field
receives a value from an unchanged native constructor argument through a selected
pure getter. Its backing slot accepts the original native input of a declared
public setter. This establishes an open injection boundary without asserting that
the setter runs on a particular production path. The caller supplies two positional
arguments to public matching methods on that actual receiver family. Selected
trait bodies, private storage identities, field types, references and readonly
initialization remain explicit boundaries. Native typed backing fields remain
supported; union and intersection bounds do not imply a wider family. Promoted
constructors retain their implicit initialization, including readonly fields;
a second initialization cannot establish a usable input contract.
The native `method_exists` guard uses the exact receiver and original selector.
For a declared target it returns true, so the right operand of `||` cannot receive
or rebind that selector on the observed call's path. A separate source inventory
checks all 1,639 Framework files for a shadowing namespace function. Independent
source checks reject altered receivers, selectors, negated guards and a forged
shadow declaration even when the changed source hashes are supplied correctly.
No macro method name or package name supplies an exemption. Incorrect receivers,
false branches, AND expressions, earlier escapes and known shadow functions keep
their controls. Factory contract checks now live in their single consumer as
private phases. The native binding proof has a private implementation trait; the
source type budget remains unchanged. All 78 factory findings match in every
field before and after that consolidation.
`round23-reviewed.jsonl` records three parameter rebindings through explicit
by-reference closure captures, with source offsets and complete binding paths.
These are binding-policy findings, not a claim that the runtime behavior is wrong.
`round22-resolved.jsonl` records 92 prose comments and one lexical magic-constant
method name. Candidate comment lines must parse as complete PHP or a delimited
code fragment; natural-language explanations
no longer qualify merely because they mention a class, function or variable.
All 81 code-only findings remain, while ten Surveyor documentation examples remain
unclassified. Seven retained ordinals change after prose is removed; both versions
appear in `round22-revised.jsonl`, with source review in
`round22-reviewed-revised.jsonl`. The other 74 reviewed code fragments are recorded
in `round22-reviewed.jsonl`. Shell-execution quotes retain their PHP meaning.
Native string literals and string-valued magic constants supply static method
names, including lexical `__FUNCTION__`; integer `__LINE__` and computed names
retain their controls.
`round21-resolved.jsonl` records 68 additional positional hook contracts and six
class-name cache keys. Native concatenation constrains a prefix/suffix without
interpreting formatter functions: 55 schema types, three Query Grammar components,
three ResourceRegistrar actions, three Blade directives and three Auth/Broadcast
drivers, plus one Pennant driver. Same-block assignments dominate only their own later statements or branches.
Closed native expressions, conditional closing/reopening, reference escapes, jumps
and callable references retain their controls. The former single-consumer public
factory-pattern helper is now a private implementation trait; its legacy factory
pattern facts match the preceding source exactly.
`Name::class` is a native class-name string without autoloading. Ordinary constants,
dynamic class expressions and further/reference assignments remain checked.
`round21-reviewed.jsonl` records 2,293 retained occurrences, including all 1,285
reported repeated-string policies. Their decoded literal bytes, lexical owners,
source offsets, measurements and complete diagnostic multiplicities were checked.
This policy does not prove one domain meaning or a behavioral defect. The structural evidence
records concrete own methods, direct trait imports and project-known interface
ancestry. Environment review confirms 329 superglobal offsets and 16 global/FQN
native calls; 439 namespace fallbacks/helper calls remain unclassified. Repeated
diagnostics on one line retain their source offsets and occurrence multiplicity.
Dynamic-invocation review additionally confirms 266 runtime-selected targets and
12 global/FQN indirect-call helpers; 227 namespace fallbacks remain outside this
retained subset. Round 22 removes the lexical magic-constant false positive.
`round20-resolved.jsonl` records 133 positional hook contracts and one nested-catch
fallback. Native interpolated method names preserve only selected trait/inherited
bodies and the actual positional prefix. Named/unpacked calls, callable references,
closed local selectors, overwritten/escaped bindings, failed guard branches and
replaced trait bodies remain checked. The 117 validation hooks receive four slots;
the 16 schema modifiers receive two slots through inherited `addModifiers`.
`round20-reviewed.jsonl` records 216 combined guards, 59 trailing loop guards and
12 movable local initializers; the other 27 early-exit candidates remain unclassified.
`round19-reviewed.jsonl` records 14 retained temporary-return-variable findings,
including five closure factories whose own `get_defined_vars()` calls cannot observe
the uncaptured outer temporary. Returning the closure directly preserves its body,
PHPDoc and reference captures.
`revised.jsonl` retains both versions of the Cloud CLI finding, the 42 interface messages
and seven commented-code ordinals;
`reviewed-unchanged.jsonl` records 2,847 retained findings: 29 string-policy findings,
52 recommendations about repeated branches and boolean expressions, 95 source-proved
singleton accesses, 14 temporary-return-variable findings and 287 guard/early-exit
recommendations. Round 21 adds 197 return-assignment and 40 literal-concatenation
policies, 148 structural measurements and 345 explicit environment/native argument
accesses, plus 1,285 repeated-string policies, 266 computed targets and 12 native
indirect-call helpers. Round 22 adds 74 unchanged commented-code fragments;
round 23 adds three captured-parameter rebindings. Changes are compared as diagnostic multisets, including message, location and multiplicity.
The baseline uses the original soda source plus the one-line `switch` crash fix:
the original Runner cannot finish packages containing affected switches.

## Corrections

- `multiline_property_phpdoc` reads complete constructor parameter tags across
  lines. Balanced type delimiters and quoted literals keep callable arguments
  and shape keys separate from the actual promoted property name.

- `no_unused_private_state` preserves explicit null releases just as it preserves
  unsetting a slot. Releasing retained data can end an allocation or resource
  lifetime; ordinary scalar and array writes remain detectable. Known receiver
  aliases and selected trait bodies retain their existing scope boundaries.

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
  place. Calls, construction, cloning and shell execution remain in place because
  their effects may be required on the exiting path; deferred callable bodies do
  not contribute eager effects. The broader call check replaces the JSON-specific
  exception. Property reads also stay in place: getters or hooks may have effects,
  and intervening work may change a previously captured property value.
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
  unreachable bare return does not replace an unconditional catch outcome. Nested
  fallback blocks without `finally` also qualify when their normal path and every
  catch return values or terminate. Jumps that can bypass these outcomes keep the
  finding; nested callable bodies do not supply or override an outcome.

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
  on the current receiver or through an unchanged native class parameter. The
  incoming call must select an open public instance method; ambiguous declarations
  and unsafe argument effects do not establish that contract. Late-static lookup and trait composition preserve
  replaceable implementations; final class/method controls remain detectable.
  Trait declarations also contribute resolved factory-hook contracts. The closest
  package manifest supplies local ancestors before consulting installed dependency
  metadata; executable autoload files remain untouched. Explicitly replaced nested
  packages can also use sibling declarations from their enclosing bundle. Selector
  definitions are considered only when they precede the observed call.

Regression cases live in the corresponding rule test classes:
`NoUnusedParametersTest`, `NoDeclarationsBeforeEarlyExitTest` and
`UselessVariableRuleTest`, together with `NoRepeatedTypeDispatchTest` and
`PreferNullCoalesceAssignmentTest`, `NoCatchAllExceptionsTest` and
`NoConsecutiveBlockCommentsTest`, `NoSingletonAccessTest` and
`NoTrivialFactoriesTest`, `NoCommentedOutCodeTest` and
`NoDynamicInvocationTest`. Controls
continue to require genuine violations. Cross-file anonymous contracts and
inherited selectors are also exercised in reverse file order.

This is an audit of the pinned production source set, not a claim that every
remaining diagnostic has been individually reviewed. Remaining findings include
intentional soda policies as well as candidates needing additional evidence.
The existing six-package ledger in `../laravel-audit` remains a separate snapshot.
