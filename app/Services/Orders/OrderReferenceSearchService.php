<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Search\SearchQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class OrderReferenceSearchService
{
    public function __construct(private readonly OrderReadService $orders) {}

    public function query(User $user): Builder
    {
        return $this->orders->orders($user);
    }

    /**
     * @param  array<int, OrderStatus|string>  $statuses
     * @return Collection<int, Order>
     */
    public function search(User $user, string $term, array $statuses = [], int $limit = 50): Collection
    {
        $term = trim($term);
        if ($term === '' || $limit < 1) {
            return new Collection;
        }

        try {
            $query = $this->applyStatuses($this->query($user), $statuses)
                ->with('platform:id,name');
        } catch (AuthorizationException) {
            return new Collection;
        }

        SearchQuery::match($query->getQuery(), ['reference', 'external_order_number'], $term);
        SearchQuery::rank($query->getQuery(), 'reference', $term);
        SearchQuery::rank($query->getQuery(), 'external_order_number', $term);

        return $query->limit(min($limit, 100))->get();
    }

    /** @param array<int, OrderStatus|string> $statuses */
    public function options(User $user, string $term, array $statuses = [], int $limit = 50): array
    {
        return $this->search($user, $term, $statuses, $limit)
            ->mapWithKeys(fn (Order $order): array => [$order->id => $this->label($order)])
            ->all();
    }

    /** @param array<int, OrderStatus|string> $statuses */
    public function findAuthorized(User $user, Order|int|string $order, array $statuses = []): ?Order
    {
        try {
            $query = $this->applyStatuses($this->query($user), $statuses)
                ->with('platform:id,name');
        } catch (AuthorizationException) {
            return null;
        }

        if ($order instanceof Order || is_int($order) || ctype_digit($order)) {
            return $query->whereKey($order instanceof Order ? $order->id : (int) $order)->first();
        }

        $normalized = mb_strtolower(trim($order));

        return $query
            ->where(fn (Builder $query): Builder => $query
                ->whereRaw('LOWER(reference) = ?', [$normalized])
                ->orWhereRaw('LOWER(external_order_number) = ?', [$normalized]))
            ->orderByDesc('id')
            ->first();
    }

    public function label(Order $order): string
    {
        $order->loadMissing('platform:id,name');

        return collect([
            $order->reference,
            $order->platform?->name ?? 'Manual / No Platform',
            filled($order->external_order_number) ? $order->external_order_number : null,
        ])->filter(fn (?string $part): bool => $part !== null)->implode(' — ');
    }

    public function duplicateValidationMessage(User $user, string $identityHash, ?int $ignoreOrderId = null): string
    {
        try {
            $order = $this->query($user)
                ->where('external_identity_hash', $identityHash)
                ->when($ignoreOrderId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreOrderId))
                ->first();
        } catch (AuthorizationException) {
            $order = null;
        }

        return $order
            ? "This Platform Order Number already exists on ERP Order {$order->reference}."
            : 'This Platform Order Number already exists.';
    }

    /** @param array<int, OrderStatus|string> $statuses */
    private function applyStatuses(Builder $query, array $statuses): Builder
    {
        if ($statuses === []) {
            return $query;
        }

        return $query->whereIn('status', collect($statuses)
            ->map(fn (OrderStatus|string $status): string => $status instanceof OrderStatus ? $status->value : $status)
            ->all());
    }
}
