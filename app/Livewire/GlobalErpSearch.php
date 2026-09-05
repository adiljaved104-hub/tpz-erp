<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Search\GlobalSearchService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class GlobalErpSearch extends Component
{
    public string $query = '';

    public function render(): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->employee?->status === true, 403);

        return view('livewire.global-erp-search', [
            'groups' => app(GlobalSearchService::class)->search($user, $this->query),
            'minimumLength' => GlobalSearchService::MIN_QUERY_LENGTH,
        ]);
    }
}
