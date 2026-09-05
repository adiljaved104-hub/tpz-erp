<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\InvoicePermission;
use App\Models\TaxInvoice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class InvoiceAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, InvoicePermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner, EmployeeRole::Admin => true,
            EmployeeRole::Staff => in_array($permission, [InvoicePermission::View, InvoicePermission::Create, InvoicePermission::DownloadPdf], true),
            EmployeeRole::Manager => in_array($permission, [InvoicePermission::View, InvoicePermission::Create, InvoicePermission::DownloadPdf], true),
            default => false,
        };
    }

    public function allows(User $user, InvoicePermission $permission, ?TaxInvoice $invoice = null): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }
        $default = $this->roleDefault($user, $permission);
        $allowed = $user->employee->role === EmployeeRole::Owner ? $default : ($this->overrides->decision($user, $permission->value) ?? $default);
        if (! $allowed || $invoice === null || $permission === InvoicePermission::Create || in_array($permission, [InvoicePermission::SettingsView, InvoicePermission::SettingsManage], true)) {
            return $allowed;
        }

        return $this->allows($user, InvoicePermission::ViewAll) || $invoice->created_by_user_id === $user->id;
    }

    public function scope(Builder $query, User $user): Builder
    {
        if (! $this->allows($user, InvoicePermission::View)) {
            return $query->whereRaw('1 = 0');
        }

        return $this->allows($user, InvoicePermission::ViewAll) ? $query : $query->where('created_by_user_id', $user->id);
    }

    public function authorize(User $user, InvoicePermission $permission, ?TaxInvoice $invoice = null): void
    {
        throw_unless($this->allows($user, $permission, $invoice), AuthorizationException::class);
    }
}
