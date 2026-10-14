# Changelog

## 1.0.0 — 2026-10-14

First stable release of Soda, published as `cosmira/soda` under the MIT license.
Requires PHP 8.2 or higher.

- Check PHP code with a standard rule set and no initial configuration.
- Configure named checks and constructor options in one `soda.php` file;
  generate a starting configuration with `soda init`.
- Inspect rules, defaults and analysis facts through `list:rules` and `list:metrics`.
- Check size, complexity, naming, PHPDoc, parameter usage, private state,
  composition, dependencies and owner behavior.
- Preserve known inheritance, trait, callback and framework contracts when
  evaluating rule findings.
- Return actionable source locations, measured values and thresholds, with
  JSON schema 4 reports and nonzero exit status for failed checks.
- Extend Soda through named PHP checks, expression checks and reusable rule sets.
- Collect cognitive complexity and cohesion measurements with documented limits.
- Report experimental representation-churn findings separately from violations;
  research output does not fail the quality gate.
- Use PSR-4 autoloading and support Illuminate 11, 12 and 13 on compatible PHP versions.

## Migrating from development versions

Update the Composer package name from `bunnivo/soda` to `cosmira/soda` and the PHP
namespace prefix from `Bunnivo\Soda\` to `Cosmira\Soda\`. Plugin wrappers and
Breathing metrics have been removed. Existing explicit rule lists continue to
run only the selected checks. See the [migration guide](docs/MIGRATION.md) for
namespace and extension-contract changes.
