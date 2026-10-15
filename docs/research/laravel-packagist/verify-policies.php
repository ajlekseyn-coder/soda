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
$docKinds = ['multiline_method_phpdoc' => 'method', 'multiline_property_phpdoc' => 'property', 'multiline_constant_phpdoc' => 'constant'];
$complexityBounds = ['max_cyclomatic_complexity' => 8, 'max_cognitive_complexity' => 15, 'max_statements_per_callable' => 20, 'max_control_nesting' => 3];
$metricBounds = ['max_namespace_depth' => 4, 'max_file_loc' => 700, 'max_class_length' => 500, 'max_method_length' => 100, 'max_classes_per_file' => 1, ...$complexityBounds];
$syntaxMessages = [
    'only_list_arrays'           => 'This policy forbids nested array indexing. Review the data shape: repeated domain meaning or invariants can justify a value object; introducing a temporary alone does not improve the model.',
    'no_assignment_in_condition' => 'Assignment inside condition is forbidden. Assign first, then test the value.',
    'no_error_suppression'       => 'Error suppression hides failures. Handle or translate the failure explicitly.',
    'no_runtime_includes'        => 'Runtime includes hide executable dependencies. Use autoloaded classes or explicit configuration data.',
    'no_runtime_code_evaluation' => 'Runtime code evaluation hides executable behavior. Use explicit policy objects or data-driven configuration.',
    'no_global_state'            => 'Global state hides inputs and couples behavior to process-wide mutation.',
    'no_chained_assignments'     => 'Chained assignments are forbidden. Write separate assignments, preserving right-to-left evaluation, single evaluation of the value and reference semantics.',
    'no_nested_ternary'          => 'A full ternary contains another full ternary in its result branch. Name the inner decision or use explicit control flow, preserving evaluation order.',
    'no_behavior_magic_methods'  => 'Behavioral magic methods hide the public API. Declare explicit methods and contracts.',
    'no_anonymous_classes'       => 'Anonymous classes hide responsibilities and project size. Give the behavior a named type.',
];
$rules = array_values(array_unique([...$rules, ...array_keys($nameBounds), ...array_keys($docKinds), ...array_keys($syntaxMessages), ...array_keys($metricBounds), 'no_else_branches']));
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

$commentBody = static function (Node $node): ?string {
    $text = trim($node->getDocComment()?->getText() ?? '');
    if (preg_match('/\A\/\*\*[ \t]*\R(.*?)\R[ \t]*\*\/\z/sD', $text, $match) !== 1) {
        return null;
    }

    return preg_replace('/^[ \t]*\*+[ \t]*/m', '', $match[1]);
};
$documented = static function (Node $node) use ($commentBody): bool {
    $body = $commentBody($node);
    $description = $body === null ? '' : preg_split('/^[ \t]*@/m', $body, 2)[0];

    return preg_match('/\p{L}/u', $description) === 1;
};
$parameterNames = static function (string $body): array {
    preg_match_all('/^[ \t]*@param[ \t]+(.*?)(?=^[ \t]*@[a-zA-Z]|\z)/ms', $body, $tags);
    $names = [];
    foreach ($tags[1] as $tag) {
        $type = preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', "'literal'", $tag);
        $depth = 0;
        foreach (PhpToken::tokenize('<?php '.$type) as $token) {
            if ($token->id === T_VARIABLE && $depth === 0 && $token->text !== '$this') {
                $names[substr($token->text, 1)] = true;
                break;
            }

            $depth += match ($token->text) {
                '<', '(', '[', '{'       => 1,
                '>', '>>', ')', ']', '}' => -strlen($token->text),
                default                  => 0,
            };
        }
    }

    return $names;
};

$isAssignment = static fn (Node $node): bool => $node instanceof Node\Expr\Assign
    || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\AssignOp;
$endsPath = static function (array $statements) use (&$endsPath): bool {
    $last = $statements === [] ? null : $statements[array_key_last($statements)];
    if ($last instanceof Node\Stmt\If_) {
        foreach ([$last, ...$last->elseifs, $last->else] as $branch) {
            if ($branch === null || ! $endsPath($branch->stmts)) {
                return false;
            }
        }

        return true;
    }

    return $last instanceof Node\Stmt\Return_ || $last instanceof Node\Stmt\Break_
        || $last instanceof Node\Stmt\Continue_ || ($last instanceof Node\Stmt\Expression
            && ($last->expr instanceof Node\Expr\Throw_ || $last->expr instanceof Node\Expr\Exit_));
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
        $codeLines = [];
        foreach (PhpToken::tokenize($source) as $token) {
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG])) {
                continue;
            }
            foreach (explode("\n", $token->text) as $offset => $part) {
                if (trim($part) !== '') {
                    $codeLines[$token->line + $offset] = true;
                }
            }
        }
        $measure = static function (Node $node) use ($codeLines): int {
            return count(array_filter(array_keys($codeLines), static fn (int $line): bool => $line >= $node->getStartLine() && $line <= $node->getEndLine()));
        };
        $metric = static function (string $rule, int $value, ?string $class = null, ?string $method = null) use (&$expected, $metricBounds): void {
            if ($value > $metricBounds[$rule]) {
                $expected[$rule][] = json_encode([$class, $method, null, null, $value, $metricBounds[$rule]], JSON_THROW_ON_ERROR);
            }
        };
        $metric('max_file_loc', count($codeLines));
        $classCount = 0;
        foreach ($finder->find($nodes, static fn ($node): bool => $node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_) as $class) {
            if ($class->name === null) {
                continue;
            }
            $classCount++;
            $name = $class->namespacedName->toString();
            $metric('max_namespace_depth', substr_count($name, '\\'), $name);
            $metric('max_class_length', $measure($class), $name);
        }
        $metric('max_classes_per_file', $classCount);
        foreach ($finder->find($nodes, static fn ($node): bool => $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) as $callable) {
            if ($callable instanceof Node\Stmt\Function_) {
                $metric('max_method_length', $measure($callable), null, $callable->namespacedName->toString());

                continue;
            }
            $owner = $callable->getAttribute('parent');
            if (! $owner instanceof Node\Stmt\ClassLike || $owner instanceof Node\Stmt\Interface_ || $owner->name === null || $callable->isAbstract()) {
                continue;
            }
            $name = $owner->namespacedName->toString();
            $metric('max_method_length', $measure($callable), $name, $name.'::'.$callable->name->toString());
        }

        foreach (auditComplexityDiagnostics($nodes, $complexityBounds) as $rule => $diagnostics) {
            array_push($expected[$rule], ...$diagnostics);
        }

        foreach (explode("\n", $source) as $index => $line) {
            $length = strlen(rtrim($line, "\r"));
            if ($length > 100) {
                $expected['max_line_length'][] = ($index + 1).':'.$length;
            }
        }

        foreach ($finder->find($nodes, static fn ($node): bool => $node instanceof Node) as $node) {
            $rule = match (true) {
                $node instanceof Node\Expr\ErrorSuppress => 'no_error_suppression',
                $node instanceof Node\Expr\Include_      => 'no_runtime_includes',
                $node instanceof Node\Expr\Eval_         => 'no_runtime_code_evaluation',
                $node instanceof Node\Stmt\Global_,
                $node instanceof Node\Expr\ArrayDimFetch && $node->var instanceof Node\Expr\Variable && $node->var->name === 'GLOBALS'                                     => 'no_global_state',
                $node instanceof Node\Stmt\ClassMethod && in_array($node->name->toLowerString(), ['__call', '__callstatic', '__get', '__set', '__isset', '__unset'], true) => 'no_behavior_magic_methods',
                default                                                                                                                                                    => null,
            };
            if ($rule !== null) {
                $expected[$rule][] = (string) $node->getStartLine();
            }

            $parent = $node->getAttribute('parent');
            if ($isAssignment($node) && $isAssignment($node->expr)
                && ! ($parent instanceof Node && $isAssignment($parent) && $parent->expr === $node)) {
                $expected['no_chained_assignments'][] = (string) $node->getStartLine();
            }
            if ($node instanceof Node\Expr\Ternary && $node->if !== null
                && (($node->if instanceof Node\Expr\Ternary && $node->if->if !== null)
                    || ($node->else instanceof Node\Expr\Ternary && $node->else->if !== null))) {
                $expected['no_nested_ternary'][] = (string) $node->getStartLine();
            }
            if ($node instanceof Node\Stmt\Class_ && $node->name === null) {
                $hooks = [];
                foreach ($node->getMethods() as $method) {
                    if ($method->isPublic() && $method->params === []) {
                        $hooks[$method->name->toLowerString()] = true;
                    }
                }
                $migration = $node->extends?->toLowerString() === 'illuminate\\database\\migrations\\migration'
                    && isset($hooks['up'], $hooks['down']);
                if (! $migration) {
                    $expected['no_anonymous_classes'][] = (string) $node->getStartLine();
                }
            }
            if (($node instanceof Node\Stmt\Else_ || $node instanceof Node\Stmt\ElseIf_) && $parent instanceof Node\Stmt\If_) {
                $allExit = true;
                foreach ([$parent, ...$parent->elseifs] as $previous) {
                    if ($previous === $node) {
                        break;
                    }
                    $allExit = $allExit && $endsPath($previous->stmts);
                }
                if ($allExit) {
                    $expected['no_else_branches'][] = $node->getStartLine().':'.($node instanceof Node\Stmt\ElseIf_ ? 'elseif' : 'else');
                }
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

        foreach ($finder->find($nodes, static fn ($node): bool => $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Property || $node instanceof Node\Stmt\ClassConst
            || $node instanceof Node\Param) as $member) {
            $owner = $member->getAttribute('parent');
            if ($member instanceof Node\Param) {
                if (! $member->isPromoted() || ! $owner instanceof Node\Stmt\ClassMethod || $owner->name->toLowerString() !== '__construct') {
                    continue;
                }

                if ($documented($owner) && isset($parameterNames($commentBody($owner))[$member->var->name])) {
                    continue;
                }

                $owner = $owner->getAttribute('parent');
            }
            if (! $owner instanceof Node\Stmt\ClassLike || $documented($member)) {
                continue;
            }

            $class = $owner->name?->toString() ?? 'anonymous@'.$owner->getStartLine();
            $visibility = $member->isProtected() ? 'Protected' : ($member->isPrivate() ? 'Private' : 'Public');
            if ($member instanceof Node\Stmt\ClassMethod) {
                $rule = 'multiline_method_phpdoc';
                $names = [$member->name->toString()];
            } elseif ($member instanceof Node\Stmt\Property && ! $owner instanceof Node\Stmt\Interface_) {
                $rule = 'multiline_property_phpdoc';
                $names = array_map(static fn ($item): string => $item->name->toString(), $member->props);
            } elseif ($member instanceof Node\Param) {
                $rule = 'multiline_property_phpdoc';
                $names = [$member->var->name];
            } elseif ($member instanceof Node\Stmt\ClassConst) {
                $rule = 'multiline_constant_phpdoc';
                $names = array_map(static fn ($item): string => $item->name->toString(), $member->consts);
            } else {
                continue;
            }
            foreach ($names as $name) {
                $method = $member instanceof Node\Stmt\ClassMethod ? $name : null;
                $expected[$rule][] = json_encode([$member->getStartLine(), $class, $method, $visibility, $name], JSON_THROW_ON_ERROR);
            }
        }

        $actual = array_fill_keys($rules, []);
        foreach ($byFile[$relative] ?? [] as $row) {
            if (! isset($docKinds[$row['rule']]) && ! isset($metricBounds[$row['rule']]) && ($row['class'] !== null || $row['method'] !== null)) {
                throw new RuntimeException('Unexpected scope: '.$path);
            }

            $key = (string) $row['line'];
            if (isset($metricBounds[$row['rule']])) {
                $actual[$row['rule']][] = json_encode([$row['class'], $row['method'], $row['line'], $row['message'], $row['value'], $row['threshold']], JSON_THROW_ON_ERROR);

                continue;
            }

            if ($row['rule'] === 'max_line_length') {
                if ($row['threshold'] !== 100 || $row['message'] !== null) {
                    throw new RuntimeException('Unexpected line policy: '.$path);
                }

                $key .= ':'.$row['value'];
            } elseif (isset($docKinds[$row['rule']])) {
                if ($row['value'] !== 0 || $row['threshold'] !== 1
                    || preg_match('/^(Public|Protected|Private) (method|property|constant)::(.+) must have a multiline PHPDoc comment with a description before any tags$/D', $row['message'], $doc) !== 1
                    || $doc[2] !== $docKinds[$row['rule']] || $row['class'] === null
                    || $row['method'] !== ($doc[2] === 'method' ? $doc[3] : null)) {
                    throw new RuntimeException('Unexpected documentation diagnostic: '.$path);
                }

                $key = json_encode([$row['line'], $row['class'], $row['method'], $doc[1], $doc[3]], JSON_THROW_ON_ERROR);
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

            if ($row['rule'] === 'no_else_branches') {
                $kind = match ($row['message']) {
                    'Avoid elseif after an unconditional exit. Use a separate if.'                => 'elseif',
                    'Avoid else after an unconditional exit. Keep the remaining path unindented.' => 'else',
                    default                                                                       => throw new RuntimeException('Unexpected branch diagnostic: '.$path),
                };
                $key .= ':'.$kind;
            }

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
    'scope'             => 'Exact source measurements and AST declarations for '.count($rules).' standard policies; no claim about other rules.',
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

// Independent AST measurements: owned-node inventories, ancestor body edges and
// source-order boolean runs implement the documented PHP metric boundaries.
// Nested callable/class bodies never contribute to the enclosing inventory.
function auditCallableName(Node\FunctionLike $callable, string $mode): string
{
    if ($callable instanceof Node\Stmt\Function_) {
        return $callable->namespacedName?->toString() ?? $callable->name->toString();
    }
    if ($callable instanceof Node\Stmt\ClassMethod) {
        $type = $callable->getAttribute('parent');
        $owner = $type->namespacedName?->toString() ?? ($mode === 'statements' ? '{anonymous-class}' : '{anonymous}@'.$type->getStartLine().':'.$type->getStartFilePos());

        return $owner.'::'.$callable->name->toString();
    }

    return $mode === 'statements' ? '{closure@'.$callable->getStartLine().'}' : '{callable}@'.$callable->getStartLine().':'.$callable->getStartFilePos();
}
function auditOwnedNodes(Node\FunctionLike $callable): array
{
    $result = [];
    $visit = function (Node $node) use (&$visit, &$result): void {
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return;
        }
        $result[] = $node;
        foreach ($node->getSubNodeNames() as $field) {
            foreach (is_array($node->$field) ? $node->$field : [$node->$field] as $child) {
                if ($child instanceof Node) {
                    $visit($child);
                }
            }
        }
    };
    foreach ($callable->getStmts() ?? [] as $statement) {
        $visit($statement);
    }

    return $result;
}
function auditBooleanKind(Node $node): ?string
{
    return match ($node->getType()) {
        'Expr_BinaryOp_BooleanAnd', 'Expr_BinaryOp_LogicalAnd' => 'and',
        'Expr_BinaryOp_BooleanOr', 'Expr_BinaryOp_LogicalOr'   => 'or',
        'Expr_BinaryOp_LogicalXor'                             => 'xor', default => null,
    };
}
function auditBooleanOrder(Node $node): array
{
    return auditBooleanKind($node) === null ? [] : [...auditBooleanOrder($node->left), $node, ...auditBooleanOrder($node->right)];
}
function auditBodyDepth(Node $node, Node\FunctionLike $scope): int
{
    $depth = 0;
    $child = $node;
    $branchChild = null;
    for ($parent = $child->getAttribute('parent'); $parent instanceof Node && $parent !== $scope; $parent = $parent->getAttribute('parent')) {
        $kind = $parent->getType();
        if ($parent instanceof Node\Stmt\If_) {
            if (in_array($child, $parent->stmts, true)) {
                $depth++;
            } elseif (($child instanceof Node\Stmt\ElseIf_ || $child instanceof Node\Stmt\Else_) && in_array($branchChild, $child->stmts, true)) {
                $depth++;
            }
        } elseif (in_array($kind, ['Stmt_For', 'Stmt_Foreach', 'Stmt_While', 'Stmt_Do', 'Stmt_Catch', 'Stmt_Switch'], true)) {
            if (in_array($child, $parent->stmts ?? $parent->cases ?? [], true)) {
                $depth++;
            }
        } elseif ($parent instanceof Node\Expr\Match_) {
            if (in_array($child, $parent->arms, true)) {
                $depth++;
            }
        } elseif ($parent instanceof Node\Expr\Ternary && $parent->if !== null && ($parent->if === $child || $parent->else === $child)) {
            $depth++;
        }
        $branchChild = $child;
        $child = $parent;
    }

    return $depth;
}
function auditCognitive(Node\FunctionLike $scope, array $owned): array
{
    $name = auditCallableName($scope, 'cognitive');
    $points = [];
    $recursive = false;
    foreach ($owned as $node) {
        $kind = $node->getType();
        $depth = auditBodyDepth($node, $scope);
        if ($node instanceof Node\Stmt\If_) {
            $points[] = [$node->getStartLine(), 1 + $depth, 'if'];
        } elseif ($node instanceof Node\Stmt\ElseIf_ || $node instanceof Node\Stmt\Else_) {
            $points[] = [$node->getStartLine(), 1, 'else/elseif'];
        } elseif (in_array($kind, ['Stmt_For', 'Stmt_Foreach', 'Stmt_While', 'Stmt_Do', 'Stmt_Catch', 'Stmt_Switch', 'Expr_Match'], true) || ($node instanceof Node\Expr\Ternary && $node->if !== null)) {
            $points[] = [$node->getStartLine(), 1 + $depth, $kind];
        }
        $boolean = auditBooleanKind($node);
        if ($boolean !== null && auditBooleanKind($node->getAttribute('parent')) === null) {
            $last = null;
            foreach (auditBooleanOrder($node) as $operator) {
                $current = auditBooleanKind($operator);
                if ($last !== $current) {
                    $points[] = [$operator->getStartLine(), 1, 'boolean '.$current.' sequence'];
                }
                $last = $current;
            }
        }
        if ($node instanceof Node\Stmt\Goto_ || (($node instanceof Node\Stmt\Break_ || $node instanceof Node\Stmt\Continue_) && $node->num instanceof Node\Scalar\Int_ && $node->num->value > 1)) {
            $points[] = [$node->getStartLine(), 1, 'multilevel jump'];
        }
        if ($recursive) {
            continue;
        }
        $isRecursive = false;
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $isRecursive = strtolower(($node->name->getAttribute('namespacedName') ?? $node->name)->toString()) === strtolower($name);
        }
        if (($node instanceof Node\Expr\MethodCall && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this') || ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && strtolower($node->class->toString()) === 'self')) {
            $isRecursive = $node->name instanceof Node\Identifier && str_contains($name, '::') && strtolower(substr($name, strrpos($name, '::') + 2)) === $node->name->toLowerString();
        }
        if ($isRecursive) {
            $recursive = true;
            $points[] = [$node->getStartLine(), 1, 'syntactic direct recursion'];
        }
    }

    return ['value' => array_sum(array_column($points, 1)), 'points' => $points];
}
function auditCallableMetrics(Node\FunctionLike $scope): array
{
    $owned = auditOwnedNodes($scope);
    $decisions = ['Expr_BinaryOp_BooleanAnd', 'Expr_BinaryOp_BooleanOr', 'Stmt_Case', 'Stmt_Catch', 'Stmt_ElseIf', 'Stmt_For', 'Stmt_Foreach', 'Stmt_If', 'Expr_BinaryOp_LogicalAnd', 'Expr_BinaryOp_LogicalOr', 'MatchArm', 'Expr_Ternary', 'Stmt_While'];
    $controls = ['Stmt_If', 'Stmt_For', 'Stmt_Foreach', 'Stmt_While', 'Stmt_Do', 'Stmt_Switch', 'Stmt_TryCatch'];
    $wrappers = ['Stmt_Nop', 'Stmt_Else', 'Stmt_ElseIf', 'Stmt_Catch', 'Stmt_Finally', 'Stmt_Case'];
    $cyclomatic = 1;
    $statements = 0;
    $maximum = 0;
    $line = $scope->getStartLine();
    foreach ($owned as $node) {
        $kind = $node->getType();
        if (in_array($kind, $decisions, true)) {
            $cyclomatic++;
        }
        if ($node instanceof Node\Stmt && ! in_array($kind, $wrappers, true)) {
            $statements++;
        }
        if (! in_array($kind, $controls, true)) {
            continue;
        }
        $depth = 1;
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node && $parent !== $scope; $parent = $parent->getAttribute('parent')) {
            if (in_array($parent->getType(), $controls, true)) {
                $depth++;
            }
        }
        if ($depth > $maximum) {
            $maximum = $depth;
            $line = $node->getStartLine();
        }
    }

    return ['cyclomatic' => $cyclomatic, 'statements' => $scope instanceof Node\Expr\ArrowFunction ? 1 : $statements, 'nesting' => $maximum, 'nesting_line' => $line, 'cognitive' => auditCognitive($scope, $owned)];
}

function auditComplexityDiagnostics(array $nodes, array $bounds): array
{
    $result = array_fill_keys(array_keys($bounds), []);
    foreach ((new NodeFinder)->findInstanceOf($nodes, Node\FunctionLike::class) as $scope) {
        if ($scope->getStmts() === null) {
            continue;
        }
        $metrics = auditCallableMetrics($scope);
        $name = auditCallableName($scope, 'cognitive');
        $statementName = auditCallableName($scope, 'statements');
        $owner = $scope->getAttribute('parent');
        $legacy = $scope instanceof Node\Stmt\Function_ || ($scope instanceof Node\Stmt\ClassMethod
            && $owner instanceof Node\Stmt\ClassLike && $owner->name !== null
            && ! $owner instanceof Node\Stmt\Interface_ && ! $scope->isAbstract());
        $class = str_contains($name, '::') ? strstr($name, '::', true) : null;
        $points = array_map(static fn (array $point): string => 'line '.$point[0].' +'.$point[1].' '.$point[2], $metrics['cognitive']['points']);
        $rows = [
            'max_cognitive_complexity' => [null, $name, $scope->getStartLine(),
                'Soda PHP cognitive v1: '.implode('; ', $points).'. Review the control flow before extracting objects.', $metrics['cognitive']['value']],
            'max_statements_per_callable' => [str_contains($statementName, '::') ? strstr($statementName, '::', true) : null,
                $statementName, $scope->getStartLine(),
                'Callable contains too many executable statements. Name a cohesive step when it clarifies the operation; formatting does not reduce this count.', $metrics['statements']],
        ];
        if ($legacy) {
            $rows['max_cyclomatic_complexity'] = [$class, $name, null, null, $metrics['cyclomatic']];
            $rows['max_control_nesting'] = [$class, $name, $metrics['nesting_line'], null, $metrics['nesting']];
        }
        foreach ($rows as $rule => $row) {
            if ($row[4] > $bounds[$rule]) {
                $result[$rule][] = json_encode([...$row, $bounds[$rule]], JSON_THROW_ON_ERROR);
            }
        }
    }

    return $result;
}
