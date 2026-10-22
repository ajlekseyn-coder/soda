<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Structure;

use function is_string;
use function method_exists;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;

/**
 * @internal
 */
final class TellDontAskReceiverFingerprint
{
    /**
     * Scalar lexical binding identity attached before receiver queries are collected.
     */
    private const RECEIVER_BINDING = 'sodaReceiverBinding';

    /**
     * Describe a call receiver in a form that can be compared with later expressions.
     */
    private function callExprFingerprint(Expr $expr): ?string
    {
        return match (true) {
            $expr instanceof MethodCall || $expr instanceof NullsafeMethodCall => $this->methodReceiverFingerprint($expr),
            $expr instanceof StaticCall                                        => $this->staticReceiverFingerprint($expr),
            default                                                            => null,
        };
    }

    /**
     * Describe a value expression so equivalent receivers can be recognized.
     */
    private function valueExprFingerprint(Expr $expr): ?string
    {
        return match (true) {
            $expr instanceof Variable && is_string($expr->name)  => '$'.$expr->name,
            $expr instanceof PropertyFetch                       => $this->propertyFingerprint($expr),
            $expr instanceof StaticPropertyFetch                 => $this->staticPropertyFingerprint($expr),
            default                                              => null,
        };
    }

    /**
     * Build the result from expr.
     */
    public function fromExpr(Expr $expr): ?string
    {
        $callFingerprint = $this->callExprFingerprint($expr);

        if ($callFingerprint !== null) {
            return $callFingerprint;
        }

        $valueFingerprint = $this->valueExprFingerprint($expr);

        if ($valueFingerprint !== null) {
            return $valueFingerprint;
        }

        return $expr instanceof ArrayDimFetch ? $this->arrayReceiverFingerprint($expr) : null;
    }

    /**
     * Describe the receiver and method targeted by a statically identifiable call.
     */
    public function forCall(MethodCall|NullsafeMethodCall|StaticCall $expr): ?string
    {
        if ($expr instanceof StaticCall) {
            return $expr->class instanceof Name ? $expr->class->toString() : null;
        }

        $receiver = $this->fromExpr($expr->var);
        if ($receiver === null) {
            return null;
        }

        $bindings = $this->receiverBindings($expr->var);

        if ($bindings === null) {
            return null;
        }

        return $bindings === [] ? $receiver : $receiver."\0".implode(';', $bindings);
    }

    /**
     * Return a statically known callable name, or null for a computed target.
     */
    public function callName(MethodCall|NullsafeMethodCall|StaticCall $expr): ?string
    {
        return $this->memberName($expr->name);
    }

    /**
     * Return a statically written member name, or null for a computed target.
     */
    private function memberName(Node $name): ?string
    {
        return method_exists($name, 'toString') ? $name->toString() : null;
    }

    /**
     * Return method receiver fingerprint for the supplied analysis context.
     */
    private function methodReceiverFingerprint(MethodCall|NullsafeMethodCall $expr): ?string
    {
        $receiver = $this->fromExpr($expr->var);
        $method = $this->callName($expr);
        $isIncompleteCall = $receiver === null || $method === null;

        if ($isIncompleteCall) {
            return null;
        }

        $arguments = $this->receiverArguments($expr);

        return $arguments === null ? null : $receiver.'->'.$method.'('.$arguments.')';
    }

    /**
     * Return property fingerprint for the supplied analysis context.
     */
    private function propertyFingerprint(PropertyFetch $expr): ?string
    {
        $name = $this->memberName($expr->name);
        if ($name === null) {
            return null;
        }

        $receiver = $this->fromExpr($expr->var);

        return $receiver !== null ? $receiver.'->'.$name : null;
    }

    /**
     * Describe a statically named property receiver for expression comparison.
     */
    private function staticPropertyFingerprint(StaticPropertyFetch $expr): ?string
    {
        $name = $this->memberName($expr->name);
        $isDynamicCall = ! $expr->class instanceof Name || $name === null;
        if ($isDynamicCall) {
            return null;
        }

        return $expr->class->toString().'::$'.$name;
    }

    /**
     * An indexed service is distinct from its container and from differently selected services.
     */
    private function arrayReceiverFingerprint(ArrayDimFetch $expr): ?string
    {
        $receiver = $this->fromExpr($expr->var);
        $key = $expr->dim instanceof Expr ? $this->stableValue($expr->dim) : null;

        return $receiver === null || $key === null ? null : $receiver.'['.$key.']';
    }

    /**
     * Literal selectors and unchanged lexical variables can select the same native lookup slot.
     */
    private function stableValue(Expr $value): ?string
    {
        if ($value instanceof Node\Scalar\String_) {
            return var_export($value->value, true);
        }

        if ($value instanceof Node\Scalar\Int_) {
            return (string) $value->value;
        }

        if ($value instanceof Expr\ClassConstFetch && $value->class instanceof Name
            && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class') {
            return $value->class->toString().'::class';
        }

        if ($value instanceof Variable && is_string($value->name) && is_string($value->getAttribute(self::RECEIVER_BINDING))) {
            return '$'.$value->name;
        }

        return null;
    }

    /**
     * A class call result includes its selected method and arguments, rather than the class alone.
     */
    private function staticReceiverFingerprint(StaticCall $call): ?string
    {
        $method = $this->callName($call);
        $arguments = $this->receiverArguments($call);
        if (! $call->class instanceof Name || $method === null || $arguments === null) {
            return null;
        }

        return $call->class->toString().'::'.$method.'('.$arguments.')';
    }

    /**
     * Effectful and unpacked selectors do not establish repeatable factory results.
     */
    private function receiverArguments(MethodCall|NullsafeMethodCall|StaticCall $call): ?string
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $arguments = [];
        foreach ($call->getArgs() as $argument) {
            $value = $this->stableValue($argument->value);
            if ($value === null || $argument->unpack || $argument->byRef) {
                return null;
            }

            $name = $argument->name === null ? '' : $argument->name->toString().': ';
            $arguments[] = $name.$value;
        }

        return implode(', ', $arguments);
    }

    /**
     * Binding versions remain internal; the diagnostic keeps the ordinary receiver expression.
     *
     * @return list<string>|null
     */
    private function receiverBindings(Expr $expr, bool $indexed = false): ?array
    {
        if ($expr instanceof Variable && $indexed) {
            $binding = $expr->getAttribute(self::RECEIVER_BINDING);

            return is_string($binding) ? [$binding] : null;
        }

        if ($expr instanceof ArrayDimFetch) {
            return $this->arrayBindings($expr);
        }

        if ($expr instanceof PropertyFetch) {
            return $this->receiverBindings($expr->var, $indexed);
        }

        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall) {
            return $this->callBindings($expr, $indexed);
        }

        return [];
    }

    /**
     * Both the selected key and its indexed base must retain their bindings.
     *
     * @return list<string>|null
     */
    private function arrayBindings(ArrayDimFetch $expr): ?array
    {
        $bindings = $expr->dim instanceof Variable ? [$expr->dim->getAttribute(self::RECEIVER_BINDING)] : [];
        $base = $this->receiverBindings($expr->var, true);

        return $base === null ? null : [...$base, ...$bindings];
    }

    /**
     * Factory selectors retain argument bindings and the instance receiver's indexed base.
     *
     * @return list<string>|null
     */
    private function callBindings(MethodCall|NullsafeMethodCall|StaticCall $expr, bool $indexed): ?array
    {
        $bindings = [];
        foreach ($expr->getArgs() as $argument) {
            if ($argument->value instanceof Variable) {
                $bindings[] = $argument->value->getAttribute(self::RECEIVER_BINDING);
            }
        }

        if ($expr instanceof StaticCall) {
            return $bindings;
        }

        $base = $this->receiverBindings($expr->var, $indexed);

        return $base === null ? null : [...$base, ...$bindings];
    }
}
