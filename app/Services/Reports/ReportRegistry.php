<?php

namespace App\Services\Reports;

use App\Contracts\Reports\ReportProvider;
use App\DTOs\Reports\ReportDefinition;
use LogicException;

class ReportRegistry
{
    /** @var array<string, ReportDefinition>|null */
    private ?array $definitions = null;

    /** @param iterable<int, ReportProvider> $providers */
    public function __construct(private readonly iterable $providers) {}

    /** @return array<string, ReportDefinition> */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->definitions() as $definition) {
                if (isset($definitions[$definition->key])) {
                    throw new LogicException("Duplicate report registry key: {$definition->key}");
                }
                $definitions[$definition->key] = $definition;
            }
        }

        uasort($definitions, fn (ReportDefinition $left, ReportDefinition $right): int => [$left->order, $left->title] <=> [$right->order, $right->title]);

        return $this->definitions = $definitions;
    }

    public function find(string $key): ?ReportDefinition
    {
        return $this->all()[$key] ?? null;
    }
}
