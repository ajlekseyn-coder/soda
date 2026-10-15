<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

use Cosmira\Soda\Analysis\Architecture\Cohesion;
use Cosmira\Soda\Analysis\Complexity\CognitiveComplexity;
use Cosmira\Soda\Analysis\Composition\ClassBehaviorFacts;
use Cosmira\Soda\Analysis\Design\DesignEvidence;
use Cosmira\Soda\Analysis\Frameworks\LaravelMiddlewareContracts;
use Cosmira\Soda\Analysis\Representation\RepresentationFacts;
use Cosmira\Soda\Analysis\Usage\ParameterInputFacts;
use Cosmira\Soda\Analysis\Usage\UnusedMethodDeclarations;
use Cosmira\Soda\Rules\Structure\ForwardedConstruction;
use PhpParser\Node;

/**
 * Collect optional design facts after names and ordinary structural metrics are resolved.
 *
 * @phpstan-import-type FileMetrics from FileFacts
 */
trait CollectsDesignFacts
{
    use ForwardedConstruction;

    /**
     * Collect optional design measurements without changing legacy structural counts.
     *
     * @param FileMetrics       $metrics
     * @param list<Node>        $nodes
     * @param list<string>|null $required
     *
     * @phpstan-return FileMetrics
     */
    private static function designMetrics(array $metrics, array $nodes, ?array $required): array
    {
        $measured = self::isRequired($required, 'cognitive') ? self::cognitiveMetrics($metrics, $nodes) : $metrics;

        if (self::isRequired($required, 'parameterInputs')) {
            $measured['parameterInputs'] = ParameterInputFacts::collect($nodes);
            $measured['parameterContracts'] = InheritedParameterContracts::collect($nodes);
            $measured['registeredMiddleware'] = LaravelMiddlewareContracts::collect($nodes);
        }

        if (self::isRequired($required, 'argumentContracts')) {
            $measured['argumentContracts'] = NativeStreamContract::signatures($nodes);
        }

        $measured['ownerDesign'] = self::isRequired($required, 'ownerDesign') ? DesignEvidence::collect($nodes) : [];

        $measured['classBehavior'] = self::isRequired($required, 'classBehavior') ? ClassBehaviorFacts::collect($nodes) : [];

        $measured['privateState'] = self::isRequired($required, 'privateState') ? PrivateStateFacts::collect($nodes) : [];

        $measured['interfaceUsage'] = self::isRequired($required, 'interfaceUsage') ? InterfaceFacts::collect($nodes) : [];

        $measured['methodUsage'] = self::isRequired($required, 'methodUsage') ? UnusedMethodDeclarations::collect($nodes) : [];

        $measured['cohesion'] = self::isRequired($required, 'cohesion') ? (new Cohesion)->collect($nodes) : [];

        return self::representationMetrics(self::factoryMetrics($measured, $nodes, $required), $nodes, $required);
    }

    /**
     * Construction diagnostics wait for incoming calls from the complete project.
     *
     * @param FileMetrics       $metrics
     * @param list<Node>        $nodes
     * @param list<string>|null $required
     *
     * @return FileMetrics
     */
    private static function factoryMetrics(array $metrics, array $nodes, ?array $required): array
    {
        if (self::isRequired($required, 'factoryConstruction')) {
            $metrics['factoryConstruction'] = self::factoryConstruction($nodes);
            $metrics['parameterContracts'] ??= InheritedParameterContracts::collect($nodes);
        }

        return $metrics;
    }

    /**
     * Keep representation flow opt-in while collecting its compact syntax facts.
     *
     * @param FileMetrics       $metrics
     * @param list<Node>        $nodes
     * @param list<string>|null $required
     *
     * @return FileMetrics
     */
    private static function representationMetrics(array $metrics, array $nodes, ?array $required): array
    {
        if (self::isRequired($required, 'representationFlow')) {
            $metrics['representationFlow'] = RepresentationFacts::collect($nodes);
        }

        return $metrics;
    }

    /**
     * Store named measurements once and keep anonymous scopes outside legacy rule rows.
     *
     * @param FileMetrics $metrics
     * @param list<Node>  $nodes
     *
     * @phpstan-return FileMetrics
     */
    private static function cognitiveMetrics(array $metrics, array $nodes): array
    {
        $methods = $metrics['methods'];
        $extras = [];
        foreach ((new CognitiveComplexity)->collect($nodes) as $name => $cognitive) {
            if (isset($methods[$name])) {
                $methods[$name] += $cognitive;

                continue;
            }

            $extras[$name] = $cognitive;
        }

        $metrics['methods'] = $methods;
        $metrics['cognitiveExtras'] = $extras;

        return $metrics;
    }
}
