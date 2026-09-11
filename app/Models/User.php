<?php

namespace App\Models;

use App\Services\EmployeeAccessService;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function routeNotificationForMail(Notification $notification): ?string
    {
        $email = $this->employee?->status === true ? trim((string) $this->email) : '';

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_two_factor_enabled_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'actor_user_id');
    }

    public function postedOpeningStockEntries(): HasMany
    {
        return $this->hasMany(OpeningStockEntry::class, 'posted_by_user_id');
    }

    public function createdPurchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'created_by_user_id');
    }

    public function createdOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by_user_id');
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by_user_id');
    }

    public function taskEvents(): HasMany
    {
        return $this->hasMany(TaskEvent::class, 'actor_user_id');
    }

    public function dashboardPreferences(): HasMany
    {
        return $this->hasMany(UserDashboardPreference::class);
    }

    public function createdConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'created_by_user_id');
    }

    public function reservedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'reserved_by_user_id');
    }

    public function orderFulfillments(): HasMany
    {
        return $this->hasMany(OrderFulfillment::class, 'fulfilled_by_user_id');
    }

    public function approvedPurchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'approved_by_user_id');
    }

    public function receivedPurchaseReceipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class, 'received_by_user_id');
    }

    public function assignedResponsibilities(): HasMany
    {
        return $this->hasMany(ResponsibilityAssignment::class, 'assigned_by_user_id');
    }

    public function endedResponsibilities(): HasMany
    {
        return $this->hasMany(ResponsibilityAssignment::class, 'ended_by_user_id');
    }

    public function createdMarketplacePlatforms(): HasMany
    {
        return $this->hasMany(MarketplacePlatform::class, 'created_by_user_id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return app(EmployeeAccessService::class)->canAccessPanel($this);
    }
}
