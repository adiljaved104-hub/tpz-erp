<?php

namespace App\Services\Chat;

use App\Enums\ConversationType;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SafetClaims\SafetClaimResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Models\CustomerReturn;
use App\Models\Order;
use App\Models\SafetClaim;
use App\Models\Task;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\ChatAuthorization;

class ConversationPresenter
{
    public function __construct(private readonly ChatAuthorization $authorization) {}

    public function label(Conversation $conversation, User $user): string
    {
        return match ($conversation->type) {
            ConversationType::Direct => $conversation->participants->firstWhere('employee_id', '!=', $user->employee->id)?->employee?->name ?? 'Direct Chat',
            ConversationType::Team => $conversation->title ?: ($conversation->team?->name.' Team'),
            ConversationType::Context => $conversation->title ?: 'Work Discussion',
            ConversationType::Channel => '#'.ltrim((string) $conversation->slug, '#'),
        };
    }

    public function typeLabel(Conversation $conversation): string
    {
        return match ($conversation->type) {
            ConversationType::Direct => 'Direct',
            ConversationType::Team => 'Team',
            ConversationType::Context => 'Work Discussion',
            ConversationType::Channel => 'Channel',
        };
    }

    public function typeColor(Conversation $conversation): string
    {
        return match ($conversation->type) {
            ConversationType::Direct => 'primary',
            ConversationType::Team => 'success',
            ConversationType::Context => 'warning',
            ConversationType::Channel => 'info',
        };
    }

    /** @return array{label: string, url: string}|null */
    public function contextLink(Conversation $conversation, User $user): ?array
    {
        $context = $conversation->context;
        if ($conversation->type !== ConversationType::Context || ! $this->authorization->canViewContext($user, $context)) {
            return null;
        }

        return match (true) {
            $context instanceof Task => ['label' => 'View Task', 'url' => TaskResource::getUrl('view', ['record' => $context])],
            $context instanceof Order => ['label' => 'View Order', 'url' => OrderResource::getUrl('view', ['record' => $context])],
            $context instanceof CustomerReturn => ['label' => 'View Return', 'url' => CustomerReturnResource::getUrl('view', ['record' => $context])],
            $context instanceof SafetClaim => ['label' => 'View Claim', 'url' => SafetClaimResource::getUrl('view', ['record' => $context])],
            $context instanceof Complaint => ['label' => 'View Complaint', 'url' => ComplaintResource::getUrl('view', ['record' => $context])],
            $context instanceof WarrantyRepair && $context->isInternalCompanyOwnedRepair() => ['label' => 'View Internal Repair', 'url' => InternalRepairResource::getUrl('view', ['record' => $context])],
            $context instanceof WarrantyRepair => ['label' => 'View Warranty', 'url' => WarrantyRepairResource::getUrl('view', ['record' => $context])],
            default => null,
        };
    }
}
