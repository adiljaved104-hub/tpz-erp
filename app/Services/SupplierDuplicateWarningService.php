<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;

class SupplierDuplicateWarningService
{
    /** @return Collection<int, Supplier> */
    public function candidates(
        ?string $name,
        ?string $email,
        ?string $phone,
        ?string $vatNumber,
        ?Supplier $except = null,
    ): Collection {
        $values = collect([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'vat_number' => $vatNumber,
        ])->map(fn (?string $value): ?string => filled($value) ? trim($value) : null)->filter();

        if ($values->isEmpty()) {
            return new Collection;
        }

        return Supplier::query()
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->where(function ($query) use ($values): void {
                foreach ($values as $field => $value) {
                    $method = $query->getQuery()->wheres === [] ? 'whereRaw' : 'orWhereRaw';
                    $query->{$method}('LOWER(TRIM('.$field.')) = ?', [mb_strtolower($value)]);
                }
            })
            ->orderBy('name')
            ->get();
    }
}
