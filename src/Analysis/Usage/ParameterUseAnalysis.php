<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Usage;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Tracks reads of an incoming parameter before its value is definitely overwritten.
 */
final readonly class ParameterUseAnalysis
{
    /**
     * Retain the declaration only for the duration of its lexical callable analysis.
     *
     * @param Node\Param $parameter Incoming parameter whose reads and overwrites are being tracked.
     */
    public function __construct(private Node\Param $parameter) {}

    /**
     * A by-reference output write is useful even without reading the incoming value.
     */
    public function isUsed(Node\FunctionLike $callable): bool
    {
        $state = $this->scan($callable->getStmts() ?? [], ['alive' => true, 'used' => false, 'continues' => true]);

        return $state['used'];
    }

    /**
     * Distinguish value reads, writes, nested scopes, and branch exits.
     */
    private function node(Node $node, array $state): array
    {
        if (in_array($node->getType(), ['Stmt_Class', 'Stmt_Interface', 'Stmt_Trait', 'Stmt_Enum', 'Stmt_Function'], true)) {
            return $state;
        }

        if ($node instanceof Stmt\Expression) {
            return $node->expr->getType() === 'Expr_Variable' ? $state : $this->expression($node->expr, $state);
        }

        if ($node instanceof Stmt\If_) {
            return $this->branches($node, $state);
        }

        if ($node instanceof Stmt\TryCatch) {
            return $this->tryCatch($node, $state);
        }

        if ($node instanceof Stmt\Switch_) {
            return $this->switchPaths($node, $state);
        }

        return $node instanceof Expr ? $this->expression($node, $state) : $this->children($node, $state);
    }

    /**
     * An exit in try does not prevent a catch or finally body from reading an incoming value.
     */
    private function tryCatch(Stmt\TryCatch $node, array $state): array
    {
        $paths = [$this->scan($node->stmts, $state)];
        foreach ($node->catches as $catch) {
            $paths[] = $this->scan($catch->stmts, $state);
        }

        $joined = $this->join($paths);
        if ($node->finally === null) {
            return $joined;
        }

        // An exception can reach finally before the try body overwrites an input.
        $beforeFinally = $this->join([$state, ...array_map(static fn (array $path): array => array_replace($path, ['continues' => true]), $paths)]);
        $finally = $this->scan($node->finally->stmts, $beforeFinally);

        return array_replace($finally, ['continues' => $joined['continues'] && $finally['continues']]);
    }

    /**
     * A switch selects alternative entry points rather than executing every case in order.
     */
    private function switchPaths(Stmt\Switch_ $node, array $state): array
    {
        $remaining = $this->scan([$node->cond], $state);
        $paths = [];
        $hasDefault = false;
        foreach ($node->cases as $case) {
            $hasDefault = $hasDefault || $case->cond === null;
            $paths[] = $this->scan([$case->cond, ...$case->stmts], $remaining);
        }

        if (! $hasDefault) {
            $paths[] = $remaining;
        }

        return $this->join($paths);
    }

    /**
     * Conditions short-circuit within an arm; default is selected only after all conditions fail.
     */
    private function matchPaths(Expr\Match_ $node, array $state): array
    {
        $remaining = $this->scan([$node->cond], $state);
        // An unmatched value can throw after reading the subject, without continuing.
        $paths = [array_replace($remaining, ['continues' => false])];
        $default = null;
        foreach ($node->arms as $arm) {
            if ($arm->conds === null) {
                $default = $arm->body;

                continue;
            }

            $entries = [];
            foreach ($arm->conds as $condition) {
                $remaining = $this->scan([$condition], $remaining);
                $entries[] = $remaining;
            }

            $paths[] = $this->scan([$arm->body], $this->join($entries));
        }

        if ($default !== null) {
            $paths[] = $this->scan([$default], $remaining);
        }

        return $this->join($paths);
    }

    /**
     * Model expression reads and writes separately from statement-level branch exits.
     */
    private function expression(Expr $node, array $state): array
    {
        return match (true) {
            $node instanceof Node\FunctionLike                                                                                         => $this->capture($node, $state),
            $node instanceof Expr\Ternary                                                                                              => $this->ternary($node, $state),
            $node instanceof Expr\Match_                                                                                               => $this->matchPaths($node, $state),
            $node instanceof Expr\BinaryOp                                                                                             => $this->binary($node, $state),
            $node instanceof Expr\Assign, $node instanceof Expr\AssignRef                                                              => $this->assignment($node, $state),
            ParameterRead::isScopeSnapshot($node, $this->parameter), ParameterRead::isVariable($node, $this->parameter)                => array_replace($state, ['used' => $state['alive']]),
            default                                                                                                                    => $this->children($node, $state),
        };
    }

    /**
     * All paths must overwrite the parameter to make a later read independent of its input.
     */
    private function branches(Stmt\If_ $node, array $state): array
    {
        $remaining = $this->scan([$node->cond], $state);
        $paths = [$this->scan($node->stmts, $remaining)];
        foreach ($node->elseifs as $branch) {
            $remaining = $this->scan([$branch->cond], $remaining);
            $paths[] = $this->scan($branch->stmts, $remaining);
        }

        $paths[] = $this->scan($node->else?->stmts ?? [], $remaining);

        return $this->join($paths);
    }

    /**
     * Closures explicitly capture values; arrow parameters shadow implicit capture.
     */
    private function capture(Node\FunctionLike $node, array $state): array
    {
        if ($node instanceof Expr\Closure) {
            return $this->closure($node, $state);
        }

        if ($this->isShadowed($node)) {
            return $state;
        }

        $captured = $this->scan($node->getStmts() ?? [], $state);

        return array_replace($state, ['used' => $captured['used']]);
    }

    /**
     * The selected ternary arm can overwrite the input only if both paths do so.
     */
    private function ternary(Expr\Ternary $node, array $state): array
    {
        $remaining = $this->scan([$node->cond], $state);

        return $this->join([$this->scan([$node->if], $remaining), $this->scan([$node->else], $remaining)]);
    }

    /**
     * A short-circuit operator can skip its right operand and preserve the incoming value.
     */
    private function binary(Expr\BinaryOp $node, array $state): array
    {
        $isConditional = in_array($node->getType(), [
            'Expr_BinaryOp_BooleanAnd', 'Expr_BinaryOp_BooleanOr',
            'Expr_BinaryOp_LogicalAnd', 'Expr_BinaryOp_LogicalOr', 'Expr_BinaryOp_Coalesce',
        ], true);
        if (! $isConditional) {
            return $this->children($node, $state);
        }

        return $this->shortCircuit($node, $state);
    }

    /**
     * Evaluate the right-hand value before replacing a parameter; reference writes are useful output.
     */
    private function assignment(Expr\Assign|Expr\AssignRef $node, array $state): array
    {
        $remaining = $this->scan([$node->expr], $state);
        if (! ParameterRead::isVariable($node->var, $this->parameter)) {
            return $this->scan([$node->var], $remaining);
        }

        return array_replace($remaining, ['used' => $remaining['used'] || $this->parameter->byRef, 'alive' => false]);
    }

    /**
     * Explicit closure capture reads an incoming value at closure creation.
     */
    private function closure(Expr\Closure $node, array $state): array
    {
        foreach ($node->uses as $use) {
            if (ParameterRead::isVariable($use->var, $this->parameter)) {
                $state['used'] = $state['alive'];
            }
        }

        return $state;
    }

    /**
     * A named arrow parameter shadows the outer input instead of capturing it.
     */
    private function isShadowed(Node\FunctionLike $node): bool
    {
        foreach ($node->getParams() as $parameter) {
            if (ParameterRead::isVariable($parameter->var, $this->parameter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process straight-line statements in order and join conditional paths conservatively.
     */
    private function scan(array $nodes, array $state): array
    {
        $result = $state;
        foreach ($nodes as $node) {
            if (! $node instanceof Node || ! $result['continues'] || $result['used']) {
                continue;
            }

            $result = $this->node($node, $result);
        }

        return $result;
    }

    /**
     * Retain input values surviving any continuing path and reads from every path.
     */
    private function join(array $paths): array
    {
        $result = ['alive' => false, 'used' => false, 'continues' => false];
        foreach ($paths as $path) {
            $result['used'] = $result['used'] || $path['used'];
            $result['alive'] = $result['alive'] || ($path['alive'] && $path['continues']);
            $result['continues'] = $result['continues'] || $path['continues'];
        }

        return $result;
    }

    /**
     * Preserve input on paths that may bypass a loop, switch case or exceptional write.
     */
    private function children(Node $node, array $state): array
    {
        $result = $state;
        foreach ($node->getSubNodeNames() as $field) {
            $child = get_object_vars($node)[$field];
            $children = is_array($child) ? $child : [];
            if ($child instanceof Node) {
                $children = [$child];
            }

            $result = $this->scan($children, $result);
        }

        $isExit = in_array($node->getType(), ['Stmt_Return', 'Expr_Throw'], true);
        if ($isExit) {
            $result['continues'] = false;
        }

        $canBypass = in_array($node->getType(), ['Stmt_While', 'Stmt_For', 'Stmt_Foreach', 'Stmt_Switch', 'Stmt_TryCatch'], true);
        if ($canBypass) {
            $result['alive'] = $result['alive'] || $state['alive'];
            $result['continues'] = $state['continues'];
        }

        return $result;
    }

    /**
     * Join the evaluated right operand with the path that skips it.
     */
    private function shortCircuit(Expr\BinaryOp $node, array $state): array
    {
        $left = $this->scan([$node->left], $state);

        return $this->join([$left, $this->scan([$node->right], $left)]);
    }
}
