<?php

namespace App\Actions\Teams;

use App\DTOs\Teams\CreateTeamData;
use App\Models\Team;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateTeam
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function handle(CreateTeamData $data, User $actor): Team
    {
        if (! $actor->can('create', Team::class)) {
            throw new AuthorizationException;
        }

        $validated = Validator::make([
            'name' => trim($data->name),
            'description' => filled($data->description) ? trim($data->description) : null,
            'status' => $data->status,
        ], [
            'name' => ['required', 'string', 'max:255', Rule::unique('teams', 'name')],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($validated, $actor): Team {
            $team = Team::query()->create($validated);
            $this->activity->log('team.created', $actor, $team, [
                'name' => $team->name,
                'active' => $team->status,
            ]);

            return $team;
        });
    }
}
