<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$reports = realpath($argv[1]);
$sources = realpath($argv[2] ?? $reports.'/sources');
$manifest = json_decode(file_get_contents(__DIR__.'/packages.json'), true, flags: JSON_THROW_ON_ERROR);
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;
$rules = ['max_line_length', 'no_untyped_parameters', 'no_reflection', 'no_dynamic_properties'];
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
        $nodes = (new NodeTraverser(new NameResolver))->traverse($parser->parse($source) ?? []);
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
            } elseif ($row['value'] !== 1 || $row['threshold'] !== 0) {
                throw new RuntimeException('Unexpected syntax policy value: '.$path);
            }

            if ($row['rule'] === 'no_untyped_parameters') {
                if (preg_match('/^Parameter \$(.+) must declare its accepted type\.$/D', $row['message'], $match) !== 1) {
                    throw new RuntimeException('Unexpected parameter diagnostic: '.$path);
                }

                $key .= ':'.$match[1];
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
    'scope'             => 'Exact source measurements and AST declarations for four standard policies; no claim about other rules.',
    'rules'             => [], 'reflection_ancestors' => $ancestry,
];
foreach ($verified as $rule => $rows) {
    sort($rows);
    $summary['rules'][$rule] = ['findings' => count($rows), 'diagnostic_multiset_sha256' => hash('sha256', implode("\n", $rows)."\n")];
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
