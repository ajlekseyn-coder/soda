<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$reports = realpath($argv[1]);
$sources = realpath($argv[2] ?? $reports.'/sources');
$manifest = json_decode(file_get_contents(__DIR__.'/packages.json'), true, flags: JSON_THROW_ON_ERROR);
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;
$rules = ['max_line_length', 'no_untyped_parameters', 'no_reflection', 'no_dynamic_properties', 'only_list_arrays', 'no_assignment_in_condition'];
$nameBounds = ['variable_name_length' => [2, 16], 'method_name_length' => [1, 32], 'class_name_length' => [3, 32], 'namespace_name_length' => [3, 32]];
$rules = [...$rules, ...array_keys($nameBounds)];
$verified = array_fill_keys($rules, []);
$parents = [];
$declarations = [];
$reflection = [];
$sourceHashes = [];
$packages = 0;
$counts = static function (array $values): array {
    $counts = array_count_values($values);
    ksort($counts);

    return $counts;
};
$encode = static function (array $row): string {
    ksort($row['diagnostic']);
    ksort($row);

    return json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

$insideCondition = static function (Node $assignment): bool {
    $child = $assignment;
    for ($parent = $child->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
        if ($parent instanceof Node\FunctionLike || $parent instanceof Node\Stmt\ClassLike) {
            return false;
        }

        $single = $parent instanceof Node\Stmt\If_ || $parent instanceof Node\Stmt\ElseIf_
            || $parent instanceof Node\Stmt\While_ || $parent instanceof Node\Stmt\Do_
            || $parent instanceof Node\Expr\Ternary;
        if (($single && $parent->cond === $child)
            || ($parent instanceof Node\Stmt\For_ && in_array($child, $parent->cond, true))) {
            return true;
        }

        $child = $parent;
    }

    return false;
};

foreach ($manifest['packages'] as $spec) {
    $name = explode('/', $spec['name'])[1];
    $report = $reports.'/'.$name.'.json';
    if (! is_file($report) && isset($spec['download_error'])) {
        continue;
    }

    $data = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
    $git = dirname($sources).'/git/'.$name;
    $root = isset($spec['source_supplement']) && is_dir($git) ? $git : $sources.'/'.$name;
    $byFile = [];
    foreach ($data['violations'] as $row) {
        if (in_array($row['rule'], $rules, true)) {
            $byFile[$row['file']][] = $row;
            $verified[$row['rule']][] = $encode(['package' => $spec['name'], 'diagnostic' => $row]);
        }
    }

    foreach ($data['files'] as $relative => $sha) {
        $path = $root.'/'.$relative;
        if (hash_file('sha256', $path) !== $sha) {
            throw new RuntimeException('Source hash differs: '.$path);
        }

        $sourceHashes[] = $spec['name']."\0".$relative."\0".$sha;
        $source = file_get_contents($path);
        $nodes = (new NodeTraverser(new NameResolver, new ParentConnectingVisitor))->traverse($parser->parse($source) ?? []);
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
            if ($class->name !== null && $class->extends !== null) {
                $key = strtolower($class->namespacedName->toString());
                $parents[$key][strtolower($class->extends->toString())] = true;
                $declarations[$key][] = ['package' => $spec['name'], 'file' => $relative, 'source_sha256' => $sha];
            }
        }

        $expected = array_fill_keys($rules, []);
        foreach (explode("\n", $source) as $index => $line) {
            $length = strlen(rtrim($line, "\r"));
            if ($length > 100) {
                $expected['max_line_length'][] = ($index + 1).':'.$length;
            }
        }

        foreach ($finder->findInstanceOf($nodes, Node\Param::class) as $parameter) {
            if ($parameter->type === null) {
                $expected['no_untyped_parameters'][] = $parameter->getStartLine().':'.$parameter->var->name;
            }
        }

        foreach ($finder->findInstanceOf($nodes, Node\Expr\New_::class) as $new) {
            if ($new->class instanceof Node\Name && str_starts_with(strtolower($new->class->getLast()), 'reflection')) {
                $expected['no_reflection'][] = (string) $new->getStartLine();
                $reflection[] = ['target' => $new->class->toLowerString(), 'package' => $spec['name'], 'file' => $relative, 'line' => $new->getStartLine()];
            }
        }

        foreach ($finder->findInstanceOf($nodes, Node\Attribute::class) as $attribute) {
            if ($attribute->name->toLowerString() === 'allowdynamicproperties') {
                $expected['no_dynamic_properties'][] = (string) $attribute->getStartLine();
            }
        }

        $skipArrayPolicy = fnmatch('*Request.php', basename($path)) || fnmatch('*Resource.php', basename($path));
        $nestedLines = [];
        foreach ($finder->findInstanceOf($nodes, Node\Expr\ArrayDimFetch::class) as $fetch) {
            if (! $skipArrayPolicy && $fetch->var instanceof Node\Expr\ArrayDimFetch) {
                $nestedLines[$fetch->getStartLine()] = true;
            }
        }
        $expected['only_list_arrays'] = array_map(strval(...), array_keys($nestedLines));

        $conditionLines = [];
        foreach ($finder->find($nodes, static fn ($node): bool => $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp) as $assignment) {
            if ($insideCondition($assignment)) {
                $conditionLines[$assignment->getStartLine()] = true;
            }
        }
        $expected['no_assignment_in_condition'] = array_map(strval(...), array_keys($conditionLines));

        foreach ($finder->find($nodes, static fn ($node): bool => $node instanceof Node\Expr\Variable
            || $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Namespace_) as $named) {
            $names = [];
            $nameRule = null;
            if ($named instanceof Node\Stmt\Namespace_) {
                $names = $named->name?->getParts() ?? [];
                $nameRule = 'namespace_name_length';
            } elseif ($named instanceof Node\Stmt\ClassLike) {
                $names = $named->name === null ? [] : [$named->name->toString()];
                $nameRule = 'class_name_length';
            } elseif ($named instanceof Node\Stmt\ClassMethod) {
                $names = [$named->name->toString()];
                $nameRule = 'method_name_length';
            } elseif (is_string($named->name) && ! $named->getAttribute('parent') instanceof Node\Expr\Variable) {
                $names = [$named->name];
                $nameRule = 'variable_name_length';
            }

            foreach ($names as $identifier) {
                [$minimum, $maximum] = $nameBounds[$nameRule];
                $length = strlen($identifier);
                if ($length < $minimum || $length > $maximum) {
                    $expected[$nameRule][] = $named->getStartLine().':'.$identifier.':'.$length.':'.($length < $minimum ? $minimum : $maximum);
                }
            }
        }

        $actual = array_fill_keys($rules, []);
        foreach ($byFile[$relative] ?? [] as $row) {
            if ($row['class'] !== null || $row['method'] !== null) {
                throw new RuntimeException('Unexpected scope: '.$path);
            }

            $key = (string) $row['line'];
            if ($row['rule'] === 'max_line_length') {
                if ($row['threshold'] !== 100 || $row['message'] !== null) {
                    throw new RuntimeException('Unexpected line policy: '.$path);
                }

                $key .= ':'.$row['value'];
            } elseif (isset($nameBounds[$row['rule']])) {
                [$minimum, $maximum] = $nameBounds[$row['rule']];
                if (preg_match('/^Name "(.+)" has length (\d+)\.$/D', $row['message'], $name) !== 1) {
                    throw new RuntimeException('Unexpected name diagnostic: '.$path);
                }

                $length = strlen($name[1]);
                $bound = $length < $minimum ? $minimum : $maximum;
                if ($row['value'] !== $length || (int) $name[2] !== $length || $row['threshold'] !== $bound) {
                    throw new RuntimeException('Unexpected name measurement: '.$path);
                }

                $key .= ':'.$name[1].':'.$length.':'.$bound;
            } elseif ($row['value'] !== 1 || $row['threshold'] !== 0) {
                throw new RuntimeException('Unexpected syntax policy value: '.$path);
            }

            if ($row['rule'] === 'no_untyped_parameters') {
                if (preg_match('/^Parameter \$(.+) must declare its accepted type\.$/D', $row['message'], $match) !== 1) {
                    throw new RuntimeException('Unexpected parameter diagnostic: '.$path);
                }

                $key .= ':'.$match[1];
            }

            $syntaxMessages = [
                'only_list_arrays'           => 'This policy forbids nested array indexing. Review the data shape: repeated domain meaning or invariants can justify a value object; introducing a temporary alone does not improve the model.',
                'no_assignment_in_condition' => 'Assignment inside condition is forbidden. Assign first, then test the value.',
            ];
            if (isset($syntaxMessages[$row['rule']]) && $row['message'] !== $syntaxMessages[$row['rule']]) {
                throw new RuntimeException('Unexpected syntax policy message: '.$path);
            }

            $actual[$row['rule']][] = $key;
        }

        foreach ($rules as $rule) {
            if ($counts($expected[$rule]) !== $counts($actual[$rule])) {
                throw new RuntimeException('Source evidence differs for '.$rule.': '.$path);
            }
        }
    }

    if (array_diff(array_keys($byFile), array_keys($data['files'])) !== []) {
        throw new RuntimeException('Diagnostic outside the source manifest: '.$spec['name']);
    }

    $packages++;
}

$native = array_fill_keys(['reflectionclass', 'reflectionfunction', 'reflectionmethod', 'reflectionproperty', 'reflectionobject', 'reflectionenum'], true);
$ancestry = [];
foreach ($reflection as $construction) {
    $type = $construction['target'];
    $seen = [];
    while (! isset($native[$type])) {
        if (isset($seen[$type]) || count($parents[$type] ?? []) !== 1) {
            throw new RuntimeException('Unproved reflection target: '.json_encode($construction, JSON_THROW_ON_ERROR));
        }

        $seen[$type] = true;
        $ancestry[$type] = ['parent' => array_key_first($parents[$type]), 'declarations' => $declarations[$type]];
        $type = array_key_first($parents[$type]);
    }
}

sort($sourceHashes);
ksort($ancestry);
$summary = [
    'packages'          => $packages, 'files' => count($sourceHashes),
    'manifest_sha256'   => hash_file('sha256', __DIR__.'/packages.json'),
    'verifier_sha256'   => hash_file('sha256', __FILE__),
    'source_set_sha256' => hash('sha256', implode("\n", $sourceHashes)."\n"),
    'scope'             => 'Exact source measurements and AST declarations for ten standard policies; no claim about other rules.',
    'rules'             => [], 'reflection_ancestors' => $ancestry,
];
foreach ($verified as $rule => $rows) {
    sort($rows);
    $summary['rules'][$rule] = ['findings' => count($rows), 'diagnostic_multiset_sha256' => hash('sha256', implode("\n", $rows)."\n")];
    if (isset($nameBounds[$rule])) {
        $summary['rules'][$rule]['byte_bounds'] = $nameBounds[$rule];
    }
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
