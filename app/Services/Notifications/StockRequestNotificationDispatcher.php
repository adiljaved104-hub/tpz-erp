<?php

namespace App\Services\Notifications;

use App\Enums\InventoryPermission;
use App\Enums\StockRequestSourceStatus;
use App\Enums\StockRequestStatus;
use App\Models\StockRequest;
use App\Models\StockRequestSourceLine;
use App\Models\User;
use App\Notifications\StockRequestDatabaseNotification;
use App\Services\Authorization\InventoryAuthorization;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StockRequestNotificationDispatcher
{
    public function __construct(private readonly InventoryAuthorization $authorization, private readonly NotificationRuleService $rules) {}

    public function created(StockRequest $request): void
    {
        $request->loadMissing('sourceLines.account.employee.user');
        $recipients = collect();
        foreach ($request->sourceLines as $line) {
            if ($line->source_type === 'employee' && $line->account?->employee?->user instanceof User) {
                $recipients->push($line->account->employee->user);
            } else {
                $recipients = $recipients->merge($this->authorizedUsers(InventoryPermission::DecideAllStockRequestSources));
            }
        }
        $recipients->unique('id')->each(fn (User $user) => $this->send($user, 'stock_request.created', 'created', $request, 'Stock Approval Required', "{$request->reference} is awaiting your stock-source decision."));
    }

    public function decided(StockRequestSourceLine $line): void
    {
        $line->loadMissing('request.requester.user');
        $request = $line->request;
        $recipient = $request?->requester?->user;
        if ($recipient instanceof User) {
            $decision = $line->status === StockRequestSourceStatus::Approved ? 'approved' : 'rejected';
            $this->send($recipient, "stock_request.source_{$decision}", "source-{$line->id}-{$decision}", $request,
                "Stock Source {$line->status->getLabel()}", "{$line->source_label} {$decision} its source for {$request->reference}.");
        }
        if ($request?->status === StockRequestStatus::Approved) {
            $this->approved($request);
        }
    }

    public function approved(StockRequest $request): void
    {
        $request->loadMissing('requester.user');
        $recipients = $this->authorizedUsers(InventoryPermission::ExecuteStockRequests);
        if ($request->requester?->user instanceof User) {
            $recipients->push($request->requester->user);
        }
        $recipients->unique('id')->each(fn (User $user) => $this->send($user, 'stock_request.approved', 'approved', $request,
            'Stock Request Approved', "{$request->reference} is fully approved and ready for execution."));
    }

    public function completed(StockRequest $request): void
    {
        $request->loadMissing('requester.user');
        if ($request->requester?->user instanceof User) {
            $this->send($request->requester->user, 'stock_request.completed', 'completed', $request,
                'Stock Request Completed', "{$request->reference} completed successfully.");
        }
    }

    /** @return Collection<int, User> */
    private function authorizedUsers(InventoryPermission $permission): Collection
    {
        return User::query()->whereHas('employee', fn ($query) => $query->where('status', true))->with('employee')->get()
            ->filter(fn (User $user): bool => $this->authorization->allows($user, $permission))->values();
    }

    private function send(User $recipient, string $type, string $event, StockRequest $request, string $title, string $message): void
    {
        if (! $this->rules->channelEnabled($type, 'in_app')) {
            return;
        }
        $id = $this->deterministicId("{$event}:{$request->id}:{$recipient->id}");
        if ($recipient->notifications()->whereKey($id)->exists()) {
            return;
        }
        $recipient->notify(new StockRequestDatabaseNotification($id, $type, [
            'category' => 'inventory', 'event' => $type, 'title' => $title, 'message' => Str::limit($message, 180),
            'target_type' => 'stock_request', 'target_id' => $request->id, 'stock_request_reference' => $request->reference, 'sound' => true,
        ]));
    }

    private function deterministicId(string $key): string
    {
        $hex = hash('sha256', 'tpz-erp-stock-request-notification:'.$key);
        $variant = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3).'-'.$variant.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
