<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Design;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Captures local behavior without treating unknown calls as absent relationships.
 *
 * @internal
 */
trait OwnerMethodEvidence
{
    /**
     * Keep declaration contracts and proved syntax relationships in one scalar method row.
     */
    private function method(Stmt\ClassMethod $method, int $order, array $properties): array
    {
        $variables = ['this' => 'owner'];
        $unknown = $method->byRef;
        foreach ($method->params as $index => $parameter) {
            if ($parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                $variables[$parameter->var->name] = 'parameter:'.$index;
            }

            $unknown = $unknown || $parameter->byRef || $parameter->variadic;
        }

        $relations = $this->methodRelations($method->stmts ?? [], $properties);
        $signature = $this->syntax([$method->params, $method->returnType, $method->stmts], $variables);

        return [
            'name'           => $method->name->toString(), 'line' => $method->getStartLine(), 'order' => $order,
            'visibility'     => match (true) {
                $method->isPublic() => 'public', $method->isProtected() => 'protected', default => 'private'
            },
            'public'         => $method->isPublic(), 'static' => $method->isStatic(), 'final' => $method->isFinal(), 'params' => $this->parameterTypes($method),
            'parameterCount' => count($method->params), ...$relations,
            'unknown'        => $unknown || $relations['unknown'], 'body' => hash('sha256', serialize($signature)),
            'getter'         => $this->getter($method), 'configuration' => $this->isConfiguration($method),
            'setter'         => $this->setter($method),
        ];
    }
}
