<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Usage;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Recognizes a narrow entry-normalization idiom without exempting later parameter writes.
 *
 * @internal
 */
final readonly class ParameterInputNormalization
{
    /**
     * Accepted builtin arities; optional output arguments are deliberately excluded.
     */
    private const array NORMALIZERS = [
        'strtolower'  => [1, 1],
        'strtoupper'  => [1, 1],
        'trim'        => [1, 2],
        'ltrim'       => [1, 2],
        'rtrim'       => [1, 2],
        'str_replace' => [3, 3],
    ];

    /**
     * Reject locally declared namespace functions that shadow normalization builtins.
     *
     * @param list<string> $declaredFunctions Lowercase fully qualified function names.
     */
    public function __construct(
        /**
         * Known source declarations that prevent assuming PHP's builtin fallback.
         */
        private array $declaredFunctions = [],
    ) {}

    /**
     * Accept each actual by-value input at most once in a consecutive entry prefix.
     *
     * @param array<string, true> $parameters
     *
     * @return array<int, true>
     */
    public function assignments(Node\FunctionLike $callable, array $parameters): array
    {
        $inputs = $this->byValueInputs($callable);
        $remaining = array_intersect_key($inputs, $parameters);
        $allowed = [];
        foreach ($callable->getStmts() ?? [] as $statement) {
            $assignment = $statement instanceof Stmt\Expression ? $statement->expr : null;
            $name = $assignment instanceof Expr\Assign && $assignment->var instanceof Expr\Variable ? $assignment->var->name : null;
            if (! is_string($name) || ! isset($remaining[$name]) || ! $this->isNormalization($assignment->expr, $name)) {
                break;
            }

            $allowed[spl_object_id($assignment)] = true;
            unset($remaining[$name]);
        }

        return $allowed;
    }

    /**
     * Reference captures are not independently supplied inputs of the nested callable.
     *
     * @return array<string, true>
     */
    private function byValueInputs(Node\FunctionLike $callable): array
    {
        $inputs = [];
        foreach ($callable->getParams() as $parameter) {
            if (! $parameter->byRef && $parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                $inputs[$parameter->var->name] = true;
            }
        }

        return $inputs;
    }

    /**
     * Permit scalar casts and a small vocabulary of conventional string normalization calls.
     */
    private function isNormalization(Expr $expression, string $parameter): bool
    {
        if ($this->isScalarCast($expression)) {
            return $this->isInput($expression->expr, $parameter) || $this->isNormalization($expression->expr, $parameter);
        }

        return $expression instanceof Expr\FuncCall && $this->isNormalizationCall($expression, $parameter);
    }

    /**
     * Limit cast exceptions to scalar representations, excluding object/array reshaping.
     *
     * @phpstan-assert-if-true Expr\Cast $expression
     */
    private function isScalarCast(Expr $expression): bool
    {
        return $expression instanceof Expr\Cast\String_ || $expression instanceof Expr\Cast\Int_
            || $expression instanceof Expr\Cast\Double || $expression instanceof Expr\Cast\Bool_;
    }

    /**
     * Resolve the supported vocabulary and reject optional output arguments.
     */
    private function isNormalizationCall(Expr\FuncCall $call, string $parameter): bool
    {
        if (! $call->name instanceof Node\Name) {
            return false;
        }

        if ($this->isLocallyDeclared($call->name)) {
            return false;
        }

        $name = $call->name->getAttribute('resolvedName', $call->name);
        $limits = $name instanceof Node\Name ? (self::NORMALIZERS[strtolower($name->toString())] ?? null) : null;
        if ($limits === null) {
            return false;
        }

        [$minimum, $maximum] = $limits;
        $arity = count($call->args);

        $subject = $minimum - 1;

        return $arity >= $minimum && $arity <= $maximum && $this->hasNormalizedInputArguments($call->args, $parameter, $subject);
    }

    /**
     * An unqualified namespace call can resolve to a source function instead of PHP's builtin.
     */
    private function isLocallyDeclared(Node\Name $name): bool
    {
        $local = $name->getAttribute('namespacedName');

        return $local instanceof Node\Name && in_array(strtolower($local->toString()), $this->declaredFunctions, true);
    }

    /**
     * Require the original input and otherwise only literals or its nested normalization.
     *
     * @param array<Node\Arg|Node\VariadicPlaceholder> $arguments
     */
    private function hasNormalizedInputArguments(array $arguments, string $parameter, int $subject): bool
    {
        $hasInput = false;
        $subjectName = $subject === 2 ? 'subject' : 'string';
        foreach ($arguments as $index => $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                return false;
            }

            $value = $argument->value;
            $namedSubject = $argument->name?->toString();
            $isSubject = $namedSubject !== null ? $namedSubject === $subjectName : $index === $subject;
            if ($isSubject) {
                $hasInput = $this->isInput($value, $parameter) || $this->isNormalization($value, $parameter);

                continue;
            }

            if (! $this->isScalarLiteral($value)) {
                return false;
            }
        }

        return $hasInput;
    }

    /**
     * Literal options cannot substitute a different input or invoke another operation.
     */
    private function isScalarLiteral(Expr $value): bool
    {
        return $value instanceof Node\Scalar\String_ || $value instanceof Node\Scalar\Int_ || $value instanceof Node\Scalar\Float_;
    }

    /**
     * Only the same statically named input can participate in its normalization.
     */
    private function isInput(Expr $expression, string $parameter): bool
    {
        return $expression instanceof Expr\Variable && $expression->name === $parameter;
    }
}
