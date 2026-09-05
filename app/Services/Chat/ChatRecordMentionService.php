<?php

namespace App\Services\Chat;

use App\Enums\ComplaintPermission;
use App\Enums\CustomerReturnPermission;
use App\Enums\OrderPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\TaskPermission;
use App\Enums\WarrantyRepairPermission;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SafetClaims\SafetClaimResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Models\Complaint;
use App\Models\ConversationMessage;
use App\Models\CustomerReturn;
use App\Models\Order;
use App\Models\SafetClaim;
use App\Models\Task;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChatRecordMentionService
{
    private const TOKEN_PATTERN = '/\[\[erp-record:([a-z_]+):(\d+)\]\]/';

    /** @return array<string, string> */
    public function typeOptions(): array
    {
        return [
            'task' => 'Task',
            'order' => 'Order',
            'customer_return' => 'Customer Return',
            'safet_claim' => 'Safe-T Claim',
            'complaint' => 'Complaint',
            'warranty_repair' => 'Warranty Repair',
            'internal_repair' => 'Internal Repair',
        ];
    }

    /** @return array<int, string> */
    public function search(User $user, string $type, string $search): array
    {
        return $this->authorizedQuery($user, $type)
            ->where(function (Builder $query) use ($search, $type): void {
                $query->where('reference', 'like', '%'.trim($search).'%');
                if ($type === 'task') {
                    $query->orWhere('title', 'like', '%'.trim($search).'%');
                } elseif ($type === 'order') {
                    $query->orWhere('external_order_number', 'like', '%'.trim($search).'%');
                }
            })
            ->orderByDesc('id')->limit(30)->get()
            ->mapWithKeys(fn (Model $record): array => [(int) $record->getKey() => $this->recordLabel($type, $record)])
            ->all();
    }

    public function selectedLabel(User $user, string $type, int $id): ?string
    {
        $record = $this->authorizedQuery($user, $type)->find($id);

        return $record instanceof Model ? $this->recordLabel($type, $record) : null;
    }

    public function appendToken(User $user, string $body, string $type, int $id): string
    {
        if (! array_key_exists($type, $this->typeOptions()) || ! $this->authorizedQuery($user, $type)->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['record_id' => 'The selected record is unavailable or you no longer have access to it.']);
        }

        $token = "[[erp-record:{$type}:{$id}]]";

        return trim($body) === '' ? $token : rtrim($body).' '.$token;
    }

    /**
     * Resolve all message mentions with at most one query per used record type.
     *
     * @param  Collection<int, ConversationMessage>  $messages
     * @return array<int, array<int, array{type: string, text?: string, label?: string, url?: string|null}>>
     */
    public function segmentsForMessages(Collection $messages, User $user): array
    {
        $references = [];
        foreach ($messages as $message) {
            preg_match_all(self::TOKEN_PATTERN, $message->body, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $references[$match[1]][] = (int) $match[2];
            }
        }

        $records = [];
        foreach ($references as $type => $ids) {
            if (! array_key_exists($type, $this->typeOptions())) {
                continue;
            }
            $records[$type] = $this->authorizedQuery($user, $type)
                ->whereKey(array_values(array_unique($ids)))->get()->keyBy('id');
        }

        $result = [];
        foreach ($messages as $message) {
            $parts = preg_split(self::TOKEN_PATTERN, $message->body, -1, PREG_SPLIT_DELIM_CAPTURE);
            $segments = [];
            for ($index = 0; $index < count($parts);) {
                $text = $parts[$index++] ?? '';
                if ($text !== '') {
                    $segments[] = ['type' => 'text', 'text' => $text];
                }
                if ($index + 1 >= count($parts)) {
                    continue;
                }
                $type = $parts[$index++];
                $id = (int) $parts[$index++];
                $record = $records[$type][$id] ?? null;
                $segments[] = $record instanceof Model
                    ? ['type' => 'record', 'label' => $this->recordLabel($type, $record), 'url' => $this->recordUrl($record)]
                    : ['type' => 'record', 'label' => 'Restricted Record', 'url' => null];
            }
            $result[$message->id] = $segments;
        }

        return $result;
    }

    /**
     * Build inbox-safe previews from the same authorized segments used by message bubbles.
     *
     * @param  Collection<int, ConversationMessage>  $messages
     * @return array<int, string>
     */
    public function previewsForMessages(Collection $messages, User $user, int $limit = 70): array
    {
        $segments = $this->segmentsForMessages($messages, $user);

        return $messages->mapWithKeys(function (ConversationMessage $message) use ($segments, $limit): array {
            $preview = collect($segments[$message->id] ?? [])->map(
                fn (array $segment): string => $segment['type'] === 'text'
                    ? (string) ($segment['text'] ?? '')
                    : (string) ($segment['label'] ?? 'Restricted Record'),
            )->implode(' ');
            $preview = trim((string) preg_replace('/\s+/', ' ', $preview));

            return [$message->id => Str::limit($preview, $limit)];
        })->all();
    }

    private function authorizedQuery(User $user, string $type): Builder
    {
        return match ($type) {
            'task' => app(TaskAuthorization::class)->allows($user, TaskPermission::View)
                ? app(TaskAuthorization::class)->scopeQuery(Task::query(), $user)
                : Task::query()->whereRaw('1 = 0'),
            'order' => app(OrderAuthorization::class)->allows($user, OrderPermission::View)
                ? $this->scopedOrders($user)
                : Order::query()->whereRaw('1 = 0'),
            'customer_return' => app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::View)
                ? CustomerReturn::query()->whereIn('order_id', $this->scopedOrders($user)->select('id'))
                : CustomerReturn::query()->whereRaw('1 = 0'),
            'safet_claim' => app(SafetClaimAuthorization::class)->allows($user, SafetClaimPermission::View)
                ? app(SafetClaimAuthorization::class)->scopeQuery(SafetClaim::query(), $user)
                : SafetClaim::query()->whereRaw('1 = 0'),
            'complaint' => app(ComplaintAuthorization::class)->allows($user, ComplaintPermission::View)
                ? app(ComplaintAuthorization::class)->scopeQuery(Complaint::query(), $user)
                : Complaint::query()->whereRaw('1 = 0'),
            'warranty_repair' => app(WarrantyRepairAuthorization::class)->allows($user, WarrantyRepairPermission::View)
                ? app(WarrantyRepairAuthorization::class)->scopeQuery(WarrantyRepair::query()->externalService(), $user)
                : WarrantyRepair::query()->whereRaw('1 = 0'),
            'internal_repair' => app(WarrantyRepairAuthorization::class)->allows($user, WarrantyRepairPermission::View)
                ? app(WarrantyRepairAuthorization::class)->scopeQuery(WarrantyRepair::query()->internalCompanyOwned(), $user)
                : WarrantyRepair::query()->whereRaw('1 = 0'),
            default => throw ValidationException::withMessages(['record_type' => 'Select a supported business record type.']),
        };
    }

    private function scopedOrders(User $user): Builder
    {
        $scope = app(OrderResponsibilityScopeService::class);
        $query = Order::query();
        if ($scope->requiresScope($user)) {
            $query->whereHas('items');
        }

        return $scope->applyOrders($query, $user);
    }

    private function recordLabel(string $type, Model $record): string
    {
        $label = $this->typeOptions()[$type];

        return $label.' — '.($record->getAttribute('reference') ?? '#'.$record->getKey());
    }

    private function recordUrl(Model $record): string
    {
        return match (true) {
            $record instanceof Task => TaskResource::getUrl('view', ['record' => $record]),
            $record instanceof Order => OrderResource::getUrl('view', ['record' => $record]),
            $record instanceof CustomerReturn => CustomerReturnResource::getUrl('view', ['record' => $record]),
            $record instanceof SafetClaim => SafetClaimResource::getUrl('view', ['record' => $record]),
            $record instanceof Complaint => ComplaintResource::getUrl('view', ['record' => $record]),
            $record instanceof WarrantyRepair && $record->isInternalCompanyOwnedRepair() => InternalRepairResource::getUrl('view', ['record' => $record]),
            $record instanceof WarrantyRepair => WarrantyRepairResource::getUrl('view', ['record' => $record]),
        };
    }
}
