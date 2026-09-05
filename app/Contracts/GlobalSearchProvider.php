<?php

namespace App\Contracts;

use App\DTOs\GlobalSearchResult;
use App\Models\User;
use Illuminate\Support\Collection;

interface GlobalSearchProvider
{
    /** @return Collection<int, GlobalSearchResult> */
    public function search(User $user, string $query, int $limit): Collection;
}
