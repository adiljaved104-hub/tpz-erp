<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Actions\Teams\CreateTeam as CreateTeamAction;
use App\DTOs\Teams\CreateTeamData;
use App\Filament\Resources\Teams\TeamResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTeam extends CreateRecord
{
    protected static string $resource = TeamResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateTeamAction::class)->handle(new CreateTeamData(
            name: $data['name'],
            description: $data['description'] ?? null,
            status: (bool) ($data['status'] ?? true),
        ), auth()->user());
    }
}
