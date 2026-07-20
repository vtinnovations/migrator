<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Job;

/**
 * Indexes every tagged StepInterface by its name() for lookup by JobRunner.
 */
final class StepRegistry
{
    /** @var array<string, StepInterface> */
    private array $byName = [];

    /**
     * @param iterable<StepInterface> $steps
     */
    public function __construct(iterable $steps)
    {
        foreach ($steps as $step) {
            $name = $step->name();

            if (isset($this->byName[$name])) {
                throw new \LogicException(sprintf('Duplicate migrator step name "%s".', $name));
            }

            $this->byName[$name] = $step;
        }
    }

    public function get(string $name): StepInterface
    {
        return $this->byName[$name]
            ?? throw new \RuntimeException(sprintf('Unknown migrator step "%s".', $name));
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }
}
