<?php

namespace App\DTOs\Responsibilities;

readonly class ChangeResponsibilityScopeBatchData
{
    /** @param array<int, int|string> $categoryIds */
    public function __construct(
        public ChangeResponsibilityScopeData $scope,
        public array $categoryIds,
    ) {}
}
