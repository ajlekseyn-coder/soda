<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Design;

use Cosmira\Soda\Analysis\ProjectFacts;
use Cosmira\Soda\Reporting\Violation;
use Cosmira\Soda\Rules\Check;
use InvalidArgumentException;

/**
 * Finds copied groups of stateful owner behavior and suggests a shared concern.
 */
final class RepeatedOwnerConcern extends Check
{
    use OwnerGroups;

    /**
     * Require a substantive method group shared by independent owners.
     */
    public function __construct(
        /**
         * Minimum substantive methods in an owner group.
         */
        private readonly int $minMethods = 3,
        /**
         * Minimum independent owners sharing a group.
         */
        private readonly int $minOwners = 2,
    ) {
        throw_if($minMethods < 3 || $minOwners < 2, InvalidArgumentException::class, 'minMethods must be at least 3 and minOwners at least 2.');
    }

    /**
     * Identify the optional review signal in reports and configuration.
     */
    public function id(): string
    {
        return 'repeated_owner_concern';
    }

    /**
     * Request bounded owner behavior evidence from the shared parse.
     */
    public function requiredAnalyses(): array
    {
        return ['ownerDesign'];
    }

    /**
     * Compare exact member contracts and implementations, normalizing local variable names only.
     */
    public function checkProject(ProjectFacts $project): iterable
    {
        $repeated = [];
        foreach (DesignEvidence::types($project->files) as $type) {
            foreach ($this->groups($type) as $members) {
                if (! $this->isConcern($members, $this->minMethods)) {
                    continue;
                }

                $evidence = $this->concernEvidence($type, $members);
                $state = array_intersect_key($type['propertyContracts'], array_fill_keys($evidence['fields'], true));
                ksort($state);
                $contracts = ['state' => $state];
                foreach ($members as $name => $method) {
                    $contracts[$name] = [$method['visibility'], $method['body']];
                }

                ksort($contracts);
                $key = hash('sha256', serialize($contracts));
                $owners = $repeated[$key] ?? [];
                $owners[$type['name']] = $evidence;
                $repeated[$key] = $owners;
            }
        }

        foreach ($repeated as $owners) {
            if (count($owners) < $this->minOwners) {
                continue;
            }

            $first = reset($owners);
            $rows = array_merge(...array_column($owners, 'rows'));
            $origin = $rows[0];
            yield new Violation(
                rule: $this->id(), file: $origin['file'], value: count($owners), threshold: $this->minOwners - 1, class: array_key_first($owners), line: $origin['line'],
                message: 'The same stateful method group is copied across '.count($owners).' owners: '.DesignEvidence::locations($rows).'.'
                        .' Required owner fields: '.implode(', ', $first['fields']).'.'
                        ." If these express one behavior contract, share a concern (trait) on the owners; preserve each owner's invariants.",
            );
        }
    }
}
