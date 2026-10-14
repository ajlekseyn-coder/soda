# Rules

SODA ships 115 quality rules. Every rule has its own documentation page in this
directory, following one consistent template.

The source of truth for rule identifiers, default thresholds, severities, and
config labels is `resources/catalog.php` in the repository root.

## Rule template

Every page follows this structure (modeled after the
[`eslint-plugin-unicorn`](https://github.com/sindresorhus/eslint-plugin-unicorn/tree/main/docs/rules)
docs):

````markdown
# <rule-id>

📝 <One-sentence description>.

💼 This rule is enabled in the ✅ `standard` config.
   (or: 💼🚫 This rule is not enabled by default. Add it to your `soda.php` config to enable it.)

## Why

<What problem class the rule detects and why it matters.>

## Examples

```php
// ❌
<violating code>

// ✅
<compliant code>
```

## Options

<Constructor arguments, defaults, and how to tune them. Omitted for rules without options.>

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\<Section>\<Rule>;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new <Rule>(),
    ]);
```

## Related rules

- [<other-rule>](<other-rule>.md) — why it is related.
````

## Sections

| Section | Focus |
| --- | --- |
| Architecture | Hidden coupling, runtime magic, framework bypasses |
| Complexity | Control-flow and expression complexity |
| Structure | Size limits, file/namespace shape, ordering |
| Usage | Dead code, redundant variables and parameters, array usage |
| Design | Behavioral cohesion and duplication across owners |
| Naming | Identifier length and redundancy |
| Documentation | PHPDoc and comment hygiene |

## Rule index

### Architecture

| Rule | Description | Default | Standard |
| --- | --- | --- | --- |
| [forbidden_dependencies](forbidden_dependencies.md) | Forbidden module dependencies | `0` | no |
| [no_anonymous_classes](no_anonymous_classes.md) | Anonymous classes | `0` | yes |
| [no_behavior_magic_methods](no_behavior_magic_methods.md) | Behavior magic methods | `0` | yes |
| [no_blocking_sleep](no_blocking_sleep.md) | Blocking sleeps | `0` | yes |
| [no_boolean_parameters](no_boolean_parameters.md) | Boolean parameters | `0` | no |
| [no_catch_all_exceptions](no_catch_all_exceptions.md) | Catch-all exceptions | `0` | yes |
| [no_closure_rebinding](no_closure_rebinding.md) | Closure rebinding | `0` | yes |
| [no_dependency_cycles](no_dependency_cycles.md) | Module dependency cycles | `0` | yes |
| [no_direct_filesystem_mutation](no_direct_filesystem_mutation.md) | Direct filesystem mutation | `0` | yes |
| [no_direct_response_globals](no_direct_response_globals.md) | Direct response globals | `0` | yes |
| [no_dynamic_invocation](no_dynamic_invocation.md) | Dynamic invocations | `0` | yes |
| [no_dynamic_properties](no_dynamic_properties.md) | Dynamic properties | `0` | yes |
| [no_empty_local_interfaces](no_empty_local_interfaces.md) | Empty local interfaces | `0` | yes |
| [no_environment_reads](no_environment_reads.md) | Environment reads | `0` | yes |
| [no_error_suppression](no_error_suppression.md) | Suppressed errors | `0` | yes |
| [no_global_state](no_global_state.md) | Global state access | `0` | yes |
| [no_implicit_arguments](no_implicit_arguments.md) | Implicit arguments | `0` | yes |
| [no_mode_parameters](no_mode_parameters.md) | Mode parameters | `0` | yes |
| [no_native_deserialization](no_native_deserialization.md) | Native deserialization | `0` | yes |
| [no_object_cloning](no_object_cloning.md) | Object cloning | `0` | no |
| [no_process_configuration_mutation](no_process_configuration_mutation.md) | Process configuration mutation | `0` | yes |
| [no_redundant_local_interfaces](no_redundant_local_interfaces.md) | Unconsumed local interfaces | `0` | yes |
| [no_reflection](no_reflection.md) | Reflection usage | `0` | yes |
| [no_repeated_type_dispatch](no_repeated_type_dispatch.md) | Repeated type dispatch | `1` | yes |
| [no_runtime_code_evaluation](no_runtime_code_evaluation.md) | Runtime code evaluation | `0` | yes |
| [no_runtime_contract_probes](no_runtime_contract_probes.md) | Runtime contract probes | `0` | no |
| [no_runtime_hooks](no_runtime_hooks.md) | Runtime hooks | `0` | yes |
| [no_runtime_includes](no_runtime_includes.md) | Runtime includes | `0` | yes |
| [no_runtime_type_aliases](no_runtime_type_aliases.md) | Runtime type aliases | `0` | yes |
| [no_service_locator_calls](no_service_locator_calls.md) | Service locator calls | `0` | no |
| [no_singleton_access](no_singleton_access.md) | Singleton access | `0` | yes |
| [no_static_mutable_properties](no_static_mutable_properties.md) | Static mutable state | `0` | no |
| [no_subprocess_execution](no_subprocess_execution.md) | Subprocess execution | `0` | yes |
| [no_symbol_table_mutation](no_symbol_table_mutation.md) | Symbol-table mutation | `0` | yes |
| [no_untyped_parameters](no_untyped_parameters.md) | Untyped parameters | `0` | yes |

### Complexity

| Rule | Description | Default | Standard |
| --- | --- | --- | --- |
| [max_boolean_conditions](max_boolean_conditions.md) | Boolean conditions | `4` | yes |
| [max_cognitive_complexity](max_cognitive_complexity.md) | Cognitive complexity (research) | `15` | yes |
| [max_consecutive_local_assignments](max_consecutive_local_assignments.md) | Consecutive initial local assignments | `8` | yes |
| [max_control_nesting](max_control_nesting.md) | Control structure nesting | `3` | yes |
| [max_cyclomatic_complexity](max_cyclomatic_complexity.md) | Cyclomatic complexity | `8` | yes |
| [max_return_statements](max_return_statements.md) | Return statements | `4` | yes |
| [max_statements_per_callable](max_statements_per_callable.md) | Executable statements per callable | `20` | yes |
| [max_try_catch_blocks](max_try_catch_blocks.md) | Try/catch blocks | `2` | yes |
| [no_assignment_in_condition](no_assignment_in_condition.md) | Assignments inside conditions | `0` | yes |
| [no_assignment_in_return](no_assignment_in_return.md) | Assignments inside returns | `0` | yes |
| [no_chained_assignments](no_chained_assignments.md) | Chained assignments | `0` | yes |
| [no_complex_control_conditions](no_complex_control_conditions.md) | Complex control conditions | `0` | yes |
| [no_declarations_before_early_exit](no_declarations_before_early_exit.md) | Declarations before early exits | `0` | yes |
| [no_else_branches](no_else_branches.md) | Else/elseif branches | `0` | yes |
| [no_empty_finally_blocks](no_empty_finally_blocks.md) | Empty finally blocks | `0` | yes |
| [no_identical_branches](no_identical_branches.md) | Identical expression branches | `0` | yes |
| [no_nested_ternary](no_nested_ternary.md) | Nested full ternaries | `0` | yes |
| [no_redundant_rethrow](no_redundant_rethrow.md) | Redundant rethrow | `0` | no |
| [no_repeated_compound_conditions](no_repeated_compound_conditions.md) | Methods repeating a compound condition | `2` | yes |
| [no_repeated_string_literals](no_repeated_string_literals.md) | Repeated expression string literals | `3` | yes |
| [no_return_in_finally](no_return_in_finally.md) | Returns inside finally | `0` | yes |
| [no_unneeded_ternary](no_unneeded_ternary.md) | Unneeded boolean ternaries | `0` | yes |
| [prefer_combined_guards](prefer_combined_guards.md) | Split identical guards | `0` | yes |
| [prefer_continue](prefer_continue.md) | Loops ending in a wrapped remainder | `0` | yes |
| [prefer_null_coalesce_assignment](prefer_null_coalesce_assignment.md) | Repeated null-coalesce targets | `0` | yes |

### Design

| Rule | Description | Default | Standard |
| --- | --- | --- | --- |
| [fragmented_owner_behavior](fragmented_owner_behavior.md) | Fragmented owner behavior | `0` | yes |
| [repeated_configuration_sequence](repeated_configuration_sequence.md) | Repeated object configuration | `0` | yes |
| [repeated_foreign_state_decision](repeated_foreign_state_decision.md) | Repeated foreign state decisions | `0` | yes |
| [repeated_foreign_state_update](repeated_foreign_state_update.md) | Repeated foreign state updates | `0` | yes |
| [repeated_owner_concern](repeated_owner_concern.md) | Copied owner concerns | `0` | yes |
| [representation_churn](representation_churn.md) | Representation research candidates | `0` | yes |
| [scattered_owner_concern](scattered_owner_concern.md) | Scattered owner concerns | `0` | yes |

### Documentation

| Rule | Description | Default | Standard |
| --- | --- | --- | --- |
| [multiline_constant_phpdoc](multiline_constant_phpdoc.md) | Multiline constant PHPDoc | `1` | yes |
| [multiline_method_phpdoc](multiline_method_phpdoc.md) | Multiline method PHPDoc | `1` | yes |
| [multiline_property_phpdoc](multiline_property_phpdoc.md) | Multiline property PHPDoc | `1` | yes |
| [no_consecutive_block_comments](no_consecutive_block_comments.md) | Consecutive block comments | `0` | yes |

### Naming

| Rule | Description | Default | Standard |
| --- | --- | --- | --- |
| [avoid_redundant_naming](avoid_redundant_naming.md) | Redundant naming | `80` | yes |
| [boolean_methods_without_prefix](boolean_methods_without_prefix.md) | Boolean method prefixes | `0` | no |
| [class_name_length](class_name_length.md) | Class name length | `32` | yes |
| [method_name_length](method_name_length.md) | Method name length | `32` | yes |
| [namespace_name_length](namespace_name_length.md) | Namespace name length | `32` | yes |
| [variable_name_length](variable_name_length.md) | Variable name length | `16` | yes |

### Structure

| Rule | Description | Default | Standard |
| --- | --- | --- | --- |
| [max_arguments](max_arguments.md) | Arguments | `3` | no |
| [max_ask_then_tell_patterns](max_ask_then_tell_patterns.md) | Ask-then-tell patterns | `0` | yes |
| [max_class_length](max_class_length.md) | Class length | `500` | yes |
| [max_classes_per_file](max_classes_per_file.md) | Classes per file | `1` | yes |
| [max_classes_per_namespace](max_classes_per_namespace.md) | Classes per namespace | `15` | yes |
| [max_classes_per_project](max_classes_per_project.md) | Classes per project | `300` | yes |
| [max_commented_out_code_lines](max_commented_out_code_lines.md) | Commented-out code lines | `0` | yes |
| [max_delegation_depth](max_delegation_depth.md) | Delegation depth | `3` | yes |
| [max_dependencies](max_dependencies.md) | Dependencies | `8` | yes |
| [max_effective_class_length](max_effective_class_length.md) | Source lines including traits | `500` | yes |
| [max_effective_methods_per_class](max_effective_methods_per_class.md) | Methods including traits | `40` | yes |
| [max_efferent_coupling](max_efferent_coupling.md) | Efferent coupling (Ce) | `15` | yes |
| [max_empty_catch_blocks](max_empty_catch_blocks.md) | Empty catch blocks | `0` | yes |
| [max_file_loc](max_file_loc.md) | File LOC | `700` | yes |
| [max_interfaces_per_class](max_interfaces_per_class.md) | Interfaces per class | `5` | yes |
| [max_layer_dominance_percentage](max_layer_dominance_percentage.md) | Layer mixing | `50` | yes |
| [max_line_length](max_line_length.md) | Line length | `100` | yes |
| [max_method_length](max_method_length.md) | Method length | `100` | yes |
| [max_methods_per_class](max_methods_per_class.md) | Methods per class | `40` | yes |
| [max_namespace_depth](max_namespace_depth.md) | Namespace depth | `4` | yes |
| [max_properties_per_class](max_properties_per_class.md) | Properties per class | `5` | no |
| [max_public_methods](max_public_methods.md) | Public methods | `20` | yes |
| [max_todo_fixme_comments](max_todo_fixme_comments.md) | TODO/FIXME comments | `0` | yes |
| [max_traits_per_class](max_traits_per_class.md) | Traits per class | `10` | yes |
| [methods_follow_call_order](methods_follow_call_order.md) | Methods out of call order | `0` | yes |
| [no_trivial_delegating_classes](no_trivial_delegating_classes.md) | Trivial delegating classes | `0` | yes |
| [no_trivial_factories](no_trivial_factories.md) | Trivial factories | `0` | yes |
| [no_static_only_class](no_static_only_class.md) | Static-only classes | `0` | no |

### Usage

| Rule | Description | Default | Standard |
| --- | --- | --- | --- |
| [no_numeric_array_index](no_numeric_array_index.md) | Numeric array index | `0` | yes |
| [no_reassigned_parameters](no_reassigned_parameters.md) | Rebound input parameters | `0` | yes |
| [no_temporary_return_variable](no_temporary_return_variable.md) | Temporary return variables | `0` | yes |
| [no_unused_parameters](no_unused_parameters.md) | Unused parameters | `0` | yes |
| [no_unused_private_state](no_unused_private_state.md) | Unread private state | `0` | yes |
| [only_list_arrays](only_list_arrays.md) | Nested array chains (list-only / strict opt-in) | `0` | yes |
| [prefer_includes](prefer_includes.md) | Position checks for substrings | `0` | yes |
| [prefer_string_starts_ends_with](prefer_string_starts_ends_with.md) | Substring checks for prefixes and suffixes | `0` | yes |
| [unused_methods](unused_methods.md) | Unused methods | `0` | yes |
| [useless_variable](useless_variable.md) | Useless variable | `0` | yes |
