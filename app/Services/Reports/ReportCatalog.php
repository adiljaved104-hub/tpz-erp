<?php

namespace App\Services\Reports;

use App\DTOs\Reports\ReportDefinition;
use App\Models\User;

class ReportCatalog
{
    public function __construct(private readonly ReportRegistry $registry) {}

    /** @return array<string, array<string, mixed>> */
    public function available(User $user): array
    {
        return collect($this->registry->all())
            ->filter(fn (ReportDefinition $definition): bool => $definition->canView($user))
            ->map(fn (ReportDefinition $definition): array => $definition->toArray())
            ->all();
    }

    /** @return array<string, mixed> */
    public function get(User $user, string $key): array
    {
        return $this->resolve($user, $key)->toArray();
    }

    public function resolve(User $user, string $key): ReportDefinition
    {
        $definition = $this->registry->find($key);
        abort_unless($definition !== null && $definition->canView($user), 403);

        return $definition;
    }

    public function canExport(User $user, string $key, ?string $format = null): bool
    {
        $definition = $this->resolve($user, $key);

        return $format === null
            ? collect($definition->formats)->contains(fn (string $supported): bool => $definition->canExport($user, $supported))
            : $definition->canExport($user, $format);
    }
}
