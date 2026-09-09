<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\QuotationPermission;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class QuotationAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, QuotationPermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner, EmployeeRole::Admin => true,
            EmployeeRole::Manager => ! in_array($permission, [QuotationPermission::Cancel, QuotationPermission::SourceInventory, QuotationPermission::ViewSourceCost], true),
            EmployeeRole::Staff => in_array($permission, [
                QuotationPermission::View, QuotationPermission::Create, QuotationPermission::Update,
                QuotationPermission::Send, QuotationPermission::Export,
            ], true),
            default => false,
        };
    }

    public function allows(User $user, QuotationPermission $permission, ?Quotation $quotation = null): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        $default = $this->roleDefault($user, $permission);
        $allowed = $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);

        if (! $allowed || $quotation === null || in_array($permission, [QuotationPermission::Create, QuotationPermission::ViewAll], true)) {
            return $allowed;
        }

        return $this->scope(Quotation::query()->whereKey($quotation), $user)->exists();
    }

    public function scope(Builder $query, User $user): Builder
    {
        if (! $this->allows($user, QuotationPermission::View)) {
            return $query->whereRaw('1 = 0');
        }
        if (in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
            && $this->allows($user, QuotationPermission::ViewAll)) {
            return $query;
        }
        if ($user->employee?->role === EmployeeRole::Manager && $this->allows($user, QuotationPermission::ViewAll)) {
            return $query->whereHas('salesperson', fn (Builder $employee) => $employee->where('team_id', $user->employee->team_id));
        }

        return $query->where('created_by_user_id', $user->id);
    }

    public function authorize(User $user, QuotationPermission $permission, ?Quotation $quotation = null): void
    {
        throw_unless($this->allows($user, $permission, $quotation), AuthorizationException::class);
    }
}
