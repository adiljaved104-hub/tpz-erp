<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;

class WebSalesCustomerLookupService
{
    public function __construct(private readonly WebSalesReadService $sales) {}

    /** @return array<int, string> */
    public function options(User $actor, string $search): array
    {
        $search = trim($search);
        if (mb_strlen($search) < 2) {
            return [];
        }

        return $this->sales->scoped($actor)
            ->select(['orders.id', 'orders.customer_name', 'orders.customer_phone', 'orders.customer_address'])
            ->where(function ($query) use ($search): void {
                $query->where('orders.customer_name', 'like', "%{$search}%")
                    ->orWhere('orders.customer_phone', 'like', "%{$search}%");
            })
            ->latest('orders.id')
            ->limit(50)
            ->get()
            ->unique(fn (Order $order): string => mb_strtolower(trim((string) $order->customer_name)).'|'.$this->normalizedPhone((string) $order->customer_phone))
            ->take(20)
            ->mapWithKeys(fn (Order $order): array => [
                $order->id => trim((string) $order->customer_name).' · '.trim((string) $order->customer_phone),
            ])
            ->all();
    }

    /** @return array{name:string,phone:string,address:?string}|null */
    public function details(User $actor, int $orderId): ?array
    {
        $order = $this->sales->scoped($actor)
            ->select(['orders.id', 'orders.customer_name', 'orders.customer_phone', 'orders.customer_address'])
            ->find($orderId);

        if ($order === null) {
            return null;
        }

        return [
            'name' => (string) $order->customer_name,
            'phone' => (string) $order->customer_phone,
            'address' => $order->customer_address,
        ];
    }

    private function normalizedPhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
