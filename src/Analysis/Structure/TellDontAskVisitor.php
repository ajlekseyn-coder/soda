<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Structure;

use Cosmira\Soda\Analysis\AnonymousClassBoundary;
use Cosmira\Soda\Analysis\CallableScopes;
use Cosmira\Soda\Analysis\FactVisitor;
use Cosmira\Soda\Analysis\FileFacts;

use function is_string;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;

/**
 * Finds the "ask, then tell" shape: query the same receiver in a branch condition and then command it.
 *
 * @internal
 *
 * @phpstan-import-type AskThenTell from FileFacts
 */
final class TellDontAskVisitor extends FactVisitor
{
    use CallableScopes, TellDontAskScopes;

    /**
     * Query-then-command findings with their source and callable context.
     *
     * @var list<AskThenTell>
     */
    private array $result = [];

    /**
     * Method whose body is currently being scanned for ask-then-tell pairs.
     */
    private ?string $currentMethod = null;

    /**
     * Resolves variables read in conditions back to the queries that filled them.
     */
    private readonly TellDontAskQuestionProbe $questionProbe;

    /**
     * Collects the commands issued after a query was already answered.
     */
    private readonly TellDontAskCommandCollector $commandCollector;

    /**
     * Share one receiver fingerprint between the probe and the command collector.
     */
    public function __construct()
    {
        $fingerprint = new TellDontAskReceiverFingerprint;
        $this->questionProbe = new TellDontAskQuestionProbe($fingerprint);
        $this->commandCollector = new TellDontAskCommandCollector($fingerprint);
    }

    /**
     * Collect facts before traversing the children of this syntax node.
     */
    #[\Override]
    protected function doEnterNode(Node $node): void
    {
        if (AnonymousClassBoundary::isInside($node)) {
            return;
        }

        match ($node->getType()) {
            'Expr_Closure',
            'Expr_ArrowFunction'       => $this->pushAliasScope($node),
            'Stmt_ClassMethod',
            'Stmt_Function'            => $this->enterMethodScope($node),
            'Expr_Assign'              => $this->recordAliasNode($node),
            'Stmt_If'                  => $this->collectFromIfNode($node),
            'Expr_Ternary'             => $this->collectFromTernaryNode($node),
            'Expr_BinaryOp_BooleanAnd',
            'Expr_BinaryOp_LogicalAnd' => $this->collectFromShortCircuitAnd($node),
            default                    => null,
        };
    }

    /**
     * Restore analysis state after traversing the children of this syntax node.
     */
    #[\Override]
    protected function doLeaveNode(Node $node): void
    {
        if (AnonymousClassBoundary::isInside($node)) {
            return;
        }

        $isClosure = $node->getType() === 'Expr_Closure' || $node->getType() === 'Expr_ArrowFunction';

        if ($isClosure) {
            $this->popAliasScope();

            return;
        }

        $isNamedCallable = $node->getType() === 'Stmt_ClassMethod' || $node->getType() === 'Stmt_Function';

        if ($isNamedCallable) {
            $this->currentMethod = null;
            $this->clear();
        }
    }

    /**
     * Return query-then-command findings recorded during traversal.
     *
     * @return list<AskThenTell>
     */
    public function violations(): array
    {
        return $this->result;
    }

    /**
     * Start method using the current analysis state.
     */
    private function startMethod(Node $node): void
    {
        /** @var ClassMethod|Function_ $node */
        $name = $this->resolveMethodName($node);
        if ($name === null) {
            return;
        }

        $this->currentMethod = $name;
        $this->reset();
        $this->bindReceiverKeys($node);
    }

    /**
     * Push alias scope using the current analysis state.
     */
    private function pushAliasScope(Node $node): void
    {
        if ($this->currentMethod === null) {
            return;
        }

        $this->pushScope();
        $this->bindReceiverKeys($node);
    }

    /**
     * Pop alias scope using the current analysis state.
     */
    private function popAliasScope(): void
    {
        $this->popScope();
    }

    /**
     * Record alias in the current analysis scope.
     */
    private function recordAlias(Node $node): void
    {
        $isAssignment = $node instanceof Assign;
        if (! $isAssignment) {
            return;
        }

        $cannotRecordAlias = ! $node->var instanceof Expr\Variable || ! is_string($node->var->name) || ! $this->hasScopes();
        if ($cannotRecordAlias) {
            return;
        }

        $key = '$'.$node->var->name;
        $questions = $this->questionProbe->questions($node->expr, $this->all());
        $this->record($key, $questions);
    }

    /**
     * Collect from if from the current syntax context.
     */
    private function collectFromIf(Node $node): void
    {
        /** @var If_ $node */
        $falseCommands = [];

        foreach ($node->elseifs as $elseif) {
            $falseCommands = [...$falseCommands, ...$this->commandCollector->fromStatements($elseif->stmts)];
        }

        if ($node->else !== null) {
            $falseCommands = [...$falseCommands, ...$this->commandCollector->fromStatements($node->else->stmts)];
        }

        $this->collectFromConditional(
            $node->cond,
            $node->getStartLine(),
            $this->commandCollector->fromStatements($node->stmts),
            $falseCommands,
        );
    }

    /**
     * Match conditional queries to commands executed by their branch statements.
     *
     * @param list<array{receiver: string, receiverKey: string, method: string, arguments: list<string>}> ...$commandGroups
     */
    private function collectFromConditional(Expr $condition, int $line, array ...$commandGroups): void
    {
        $questions = $this->questionProbe->questions($condition, $this->all());

        if ($questions === []) {
            return;
        }

        foreach (array_merge([], ...$commandGroups) as $command) {
            $question = TellDontAskBranchMatcher::firstMatch($questions, $command);

            if ($question === null) {
                continue;
            }

            $this->result[] = [
                'line'     => $line,
                'method'   => $this->currentMethod,
                'class'    => TellDontAskBranchMatcher::currentClassName($this->currentMethod),
                'receiver' => $command['receiver'],
                'question' => $question['method'],
                'command'  => $command['method'],
            ];
        }
    }

    /**
     * Save the current callable owner and enter the next named or suppressed scope.
     */
    private function enterMethodScope(Node $node): void
    {
        /** @var ClassMethod|Function_ $node */
        $this->startMethod($node);
    }

    /**
     * Record alias node in the current analysis scope.
     */
    private function recordAliasNode(Node $node): void
    {
        /** @var Assign $node */
        $this->recordAlias($node);
    }

    /**
     * Collect from if node from the current syntax context.
     */
    private function collectFromIfNode(Node $node): void
    {
        /** @var If_ $node */
        $this->collectFromIf($node);
    }

    /**
     * Collect from ternary node from the current syntax context.
     */
    private function collectFromTernaryNode(Node $node): void
    {
        $isConsumedValue = $node->getAttribute('parent')?->getType() !== 'Stmt_Expression';
        if ($isConsumedValue) {
            return;
        }

        /** @var Ternary $node */
        $this->collectFromConditional(
            $node->cond,
            $node->getStartLine(),
            $this->commandCollector->fromExpr($node->if ?? $node->cond),
            $this->commandCollector->fromExpr($node->else),
        );
    }

    /**
     * Collect from short circuit and from the current syntax context.
     */
    private function collectFromShortCircuitAnd(Node $node): void
    {
        $isNestedExpression = $node->getAttribute('parent')?->getType() !== 'Stmt_Expression';
        /** @var BooleanAnd|LogicalAnd $node */
        if ($isNestedExpression) {
            return;
        }

        $this->collectFromConditional(
            $node->left,
            $node->getStartLine(),
            $this->commandCollector->fromExpr($node->right),
        );
    }

    /**
     * Reference parameters and captures can change through another lexical owner.
     *
     * @return array<string, list<int>>
     */
    private function incomingReferenceBindings(Node\FunctionLike $owner): array
    {
        $escapes = [];
        foreach ($owner->getParams() as $parameter) {
            if ($parameter->byRef && $parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                $escapes[$parameter->var->name] = [-1];
            }
        }
        $this->capturedReferenceBindings($owner, -1, $escapes);

        return $escapes;
    }

    /**
     * A by-reference closure capture keeps its outer binding externally writable.
     *
     * @param array<string,list<int>> $escapes
     */
    private function capturedReferenceBindings(Node $closure, int $position, array &$escapes): void
    {
        if (! $closure instanceof Expr\Closure) {
            return;
        }
        foreach ($closure->uses as $use) {
            if ($use->byRef && is_string($use->var->name)) {
                $this->recordBindingEvent($escapes, $use->var->name, $position);
            }
        }
    }
}
