<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** Lexical binding writes exclude object/array state and callable-local shadowing. */
function nativeWrites(array $ast): array
{
    $v = new class extends NodeVisitorAbstract
    {
        public array $writes = [];
        private array $stack = [];

        public function enterNode(Node $n): ?int
        {
            if ($n instanceof Stmt\ClassLike) {
                $this->stack[] = ['scope' => [], 'prefix' => [], 'owner' => null];

                return null;
            }
            if ($n instanceof Node\FunctionLike) {
                $frame = ['scope' => [], 'prefix' => [], 'owner' => ['kind' => $n->getType(), 'line' => $n->getStartLine(), 'start' => $n->getStartFilePos(), 'end' => $n->getEndFilePos(), 'name' => $n instanceof Stmt\ClassMethod || $n instanceof Stmt\Function_ ? $n->name->toString() : '{closure}']];
                $outer = $this->stack === [] ? [] : $this->stack[array_key_last($this->stack)]['scope'];
                if ($n instanceof Expr\Closure) {
                    foreach ($n->uses as $u) {
                        if ($u->byRef && is_string($u->var->name) && isset($outer[$u->var->name])) {
                            $frame['scope'][$u->var->name] = $outer[$u->var->name] + ['reference_capture' => ['line' => $u->getStartLine(), 'start' => $u->getStartFilePos(), 'end' => $u->getEndFilePos()]];
                        }
                    }
                }
                foreach ($n->getParams() as $p) {
                    if (! $p->byRef && $p->var instanceof Expr\Variable && is_string($p->var->name)) {
                        $frame['scope'][$p->var->name] = ['name' => $p->var->name, 'line' => $p->getStartLine(), 'start' => $p->getStartFilePos(), 'end' => $p->getEndFilePos(), 'by_reference' => false, 'declaration_owner' => $frame['owner']];
                    }
                }
                // Exclude more than approved entry normalization, so the retained subset cannot depend on callee assumptions.
                foreach ($n->getStmts() ?? [] as $s) {
                    if (! $s instanceof Stmt\Expression || ! $s->expr instanceof Expr\Assign) {
                        break;
                    }
                    $frame['prefix'][] = [$s->getStartFilePos(), $s->getEndFilePos()];
                }
                $this->stack[] = $frame;

                return null;
            }
            if ($this->stack === []) {
                return null;
            }
            $frame = $this->stack[array_key_last($this->stack)];
            $targets = match (true) {
                $n instanceof Expr\Assign, $n instanceof Expr\AssignRef, $n instanceof Expr\AssignOp, $n instanceof Expr\PreInc, $n instanceof Expr\PostInc, $n instanceof Expr\PreDec, $n instanceof Expr\PostDec => [$n->var],
                $n instanceof Stmt\Unset_, $n instanceof Stmt\Global_                                                                                                                                              => $n->vars,
                $n instanceof Stmt\Static_                                                                                                                                                                         => array_map(fn ($x) => $x->var, $n->vars),
                $n instanceof Stmt\Foreach_                                                                                                                                                                        => array_filter([$n->keyVar, $n->valueVar]),
                $n instanceof Stmt\Catch_                                                                                                                                                                          => $n->var === null ? [] : [$n->var],
                default                                                                                                                                                                                            => [],
            };
            $bindings = [];
            $flatten = function (Node $t) use (&$flatten, &$bindings): void {
                if ($t instanceof Expr\Variable && is_string($t->name)) {
                    $bindings[$t->name] ??= $t;

                    return;
                }
                if ($t instanceof Expr\Array_ || $t instanceof Expr\List_) {
                    foreach ($t->items as $item) {
                        if ($item !== null) {
                            $flatten($item->value);
                        }
                    }
                }
            };
            foreach ($targets as $t) {
                $flatten($t);
            }
            foreach ($bindings as $name => $target) {
                if (! isset($frame['scope'][$name])) {
                    continue;
                }
                $excluded = false;
                foreach ($frame['prefix'] as [$a, $b]) {
                    if ($n->getStartFilePos() >= $a && $n->getEndFilePos() <= $b) {
                        $excluded = true;
                    }
                }
                if ($excluded) {
                    continue;
                }
                $this->writes[] = ['name' => $name, 'line' => $target->getStartLine(), 'write_kind' => $n->getType(), 'write_start' => $n->getStartFilePos(), 'write_end' => $n->getEndFilePos(), 'target_start' => $target->getStartFilePos(), 'target_end' => $target->getEndFilePos(), 'owner' => $frame['owner'], 'parameter' => $frame['scope'][$name], 'excluded_entry_assignment_ranges' => $frame['prefix']];
            }

            return null;
        }

        public function leaveNode(Node $n): ?int
        {
            if ($n instanceof Stmt\ClassLike || $n instanceof Node\FunctionLike) {
                array_pop($this->stack);
            }

            return null;
        }
    };
    (new NodeTraverser($v))->traverse($ast);

    return $v->writes;
}

function canonical(array $d): string
{
    ksort($d);

    return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function diagnostic(array $f, string $file): array
{
    return ['rule' => 'no_reassigned_parameters', 'file' => $file, 'method' => null, 'class' => null, 'line' => $f['line'], 'value' => 1, 'threshold' => 0, 'message' => 'Parameter $'.$f['name'].' is rebound. Keep the input name for the original value and give a transformed value its own name, or configure intentional rebinding explicitly.'];
}

$reports = realpath($argv[1] ?? '');
$sources = realpath($argv[2] ?? '');
$records = $argv[3] ?? __DIR__.'/round27-reviewed.jsonl';
if ($reports === false || $sources === false || ! isset($argv[2])) {
    throw new RuntimeException('Usage: php verify-parameter-writes.php REPORTS SOURCES [RECORDS.jsonl]');
}
$manifest = json_decode(file_get_contents(__DIR__.'/packages.json'), true, flags: JSON_THROW_ON_ERROR);
$roots = [];
foreach ($manifest['packages'] as $spec) {
    $name = explode('/', $spec['name'])[1];
    $git = dirname($sources).'/git/'.$name;
    $roots[$spec['name']] = [
        'source' => isset($spec['source_supplement']) && is_dir($git) ? $git : $sources.'/'.$name,
        'report' => $reports.'/'.$name.'.json',
    ];
}
$rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($records, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$summary = json_decode(file_get_contents(__DIR__.'/results.json'), true, flags: JSON_THROW_ON_ERROR);
$reviewCounts = array_column($summary['rounds'], 'reviewed_unchanged', 'round');
if (count($rows) !== ($reviewCounts[27] ?? null)) {
    throw new RuntimeException('Record count differs from the complete round 27 review.');
}
if ($rows === []) {
    throw new RuntimeException('No parameter-write review records found.');
}
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$native = [];
$reported = [];
$hashes = [];
$data = [];
foreach ($rows as $row) {
    $package = $row['package'];
    $file = $row['diagnostic']['file'];
    $key = $package.'/'.$file;
    if (isset($hashes[$key])) {
        continue;
    }
    if (! isset($roots[$package])) {
        throw new RuntimeException('Package is absent from the pinned manifest: '.$package);
    }
    $bytes = file_get_contents($roots[$package]['source'].'/'.$file);
    $hash = hash('sha256', $bytes);
    $data[$package] ??= json_decode(file_get_contents($roots[$package]['report']), true, flags: JSON_THROW_ON_ERROR);
    if (($data[$package]['files'][$file] ?? null) !== $hash) {
        throw new RuntimeException('Pinned source hash differs: '.$key);
    }
    $hashes[$key] = $hash;
    foreach (nativeWrites($parser->parse($bytes)) as $fact) {
        $id = canonical(diagnostic($fact, $file));
        $fact['write_source'] = substr($bytes, $fact['write_start'], $fact['write_end'] - $fact['write_start'] + 1);
        $fact['target_source'] = substr($bytes, $fact['target_start'], $fact['target_end'] - $fact['target_start'] + 1);
        $fact['parameter_source'] = substr($bytes, $fact['parameter']['start'], $fact['parameter']['end'] - $fact['parameter']['start'] + 1);
        $native[$package][$id][] = $fact;
    }
    foreach ($data[$package]['violations'] as $diagnostic) {
        if ($diagnostic['file'] === $file) {
            $id = canonical($diagnostic);
            $reported[$package][$id] = ($reported[$package][$id] ?? 0) + 1;
        }
    }
}
foreach ($rows as $index => $row) {
    $package = $row['package'];
    $diagnostic = $row['diagnostic'];
    $key = $package.'/'.$diagnostic['file'];
    $id = canonical($diagnostic);
    if ($hashes[$key] !== $row['source_sha256'] || ($reported[$package][$id] ?? 0) < 1 || ($native[$package][$id] ?? []) === []) {
        throw new RuntimeException('Diagnostic, source hash or occurrence budget differs at record '.($index + 1).': '.$key);
    }
    $reported[$package][$id]--;
    $fact = array_shift($native[$package][$id]);
    if ($fact !== $row['binding_evidence']) {
        throw new RuntimeException('Native binding evidence differs at record '.($index + 1).': '.$key);
    }
}
echo json_encode(['verified_parameter_writes' => count($rows), 'verified_source_files' => count($hashes)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
