<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Structure;

use Cosmira\Soda\Analysis\Dependencies\ExternalParameterContracts;
use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\InheritedParameterContracts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Reports methods that only forward construction arguments, regardless of decorative metadata.
 */
final class NoTrivialFactories extends Check
{
    /**
     * Preserve explicitly identified published entry points, never decorative source markers.
     *
     * @param list<string> $contracts Qualified Class::method names whose API must remain available.
     */
    public function __construct(private readonly array $contracts = []) {}

    /**
     * Report creation wrappers without changing their callers automatically.
     */
    public function checkFile(FileFacts $file): iterable
    {
        $hooks = null;
        $contracts = array_fill_keys(array_map(strtolower(...), $this->contracts), true);
        foreach ((new NodeFinder)->findInstanceOf($file->nodes, Stmt\ClassLike::class) as $class) {
            if ($class->name === null) {
                continue;
            }

            foreach ($class->getMethods() as $method) {
                $creation = ForwardedConstruction::fromMethod($method);
                if (! $creation instanceof New_) {
                    continue;
                }

                $name = isset($class->namespacedName) ? $class->namespacedName->toString() : $class->name->toString();
                $identity = strtolower($name.'::'.$method->name->toString());
                $hooks ??= self::hookTypes($file);
                $isHook = self::isRequired($hooks, $name, $method->name->toString());
                if (isset($contracts[$identity]) || $isHook) {
                    continue;
                }

                yield new Violation(
                    rule: $this->id(), file: $file->path, value: 1, threshold: 0,
                    method: $method->name->toString(), class: $name, line: $method->getStartLine(),
                    message: sprintf('%s::%s() only constructs %s and forwards all parameters unchanged. Consider constructing %s at the call site.',
                        $name, $method->name->toString(), $creation->class->toString(), $creation->class->toString()),
                );
            }
        }
    }

    /**
     * Identify this standard check in configuration and diagnostics.
     */
    public function id(): string
    {
        return 'no_trivial_factories';
    }

    /**
     * Reuse the parsed AST without collecting metrics.
     *
     * @return list<string>
     */
    public function requiredAnalyses(): array
    {
        return [];
    }

    /**
     * Use source declarations only; no application class is instantiated or autoloaded.
     */
    private static function hookTypes(FileFacts $file): array
    {
        $types = InheritedParameterContracts::collect($file->nodes);

        return ExternalParameterContracts::resolve($types, [$file->path]);
    }

    /**
     * A hook requires positive contract evidence, not a conventional method name alone.
     */
    private static function isRequired(array $types, string $class, string $method): bool
    {
        $class = strtolower($class);
        $method = strtolower($method);
        $pending = [$class];
        $visited = [];
        while ($pending !== []) {
            $name = array_pop($pending);
            if (isset($visited[$name])) {
                continue;
            }

            $visited[$name] = true;
            $type = $types[$name] ?? [];
            $methods = $type['methods'] ?? [];
            $signature = $methods[$method] ?? [];
            $isInherited = $name !== $class && ! ($signature['private'] ?? true);
            if ($isInherited || self::isDispatchMatch($type['factoryDispatch'] ?? [], $method)) {
                return true;
            }

            array_push($pending, ...($type['parents'] ?? []), ...array_map(strtolower(...), $type['traitNames'] ?? []));
        }

        return false;
    }

    /**
     * Match method names proved by calls on the declaring receiver.
     */
    private static function isDispatchMatch(array $patterns, string $method): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $method) === 1) {
                return true;
            }
        }

        return false;
    }
}
