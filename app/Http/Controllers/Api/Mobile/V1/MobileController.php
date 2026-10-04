<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileController extends Controller
{
    protected function page(Request $request, Builder $query, array $search, callable $present): JsonResponse
    {
        $data = $request->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50', 'status' => 'nullable|string|max:60']);
        if (filled($data['q'] ?? null)) {
            $query->where(function (Builder $q) use ($search, $data): void {
                foreach ($search as $column) {
                    $q->orWhere($column, 'like', '%'.$data['q'].'%');
                }
            });
        }
        if (filled($data['status'] ?? null)) {
            $query->where('status', $data['status']);
        }

        return response()->json($query->paginate($data['per_page'] ?? 25)->through($present));
    }

    protected function field(string $name, string $label, string $type = 'text', bool $required = false, mixed $value = null, array $options = [], bool $editable = true, ?string $placeholder = null, ?string $helpText = null): array
    {
        return [...compact('name', 'label', 'type', 'required', 'value', 'options'),
            'editable' => $editable, 'mobile_editable' => $editable, 'read_only' => ! $editable,
            'placeholder' => $placeholder, 'help_text' => $helpText];
    }

    protected function action(string $key, string $label, array $fields = [], ?bool $destructive = null, ?string $confirmationMessage = null): array
    {
        $destructive ??= in_array($key, ['cancel', 'cancelled', 'close', 'closed', 'rejected', 'not_eligible', 'release', 'return', 'reject_source', 'deactivate', 'cannot_repair'], true);

        return [...compact('key', 'label', 'fields', 'destructive'),
            'confirmation_message' => $confirmationMessage ?? ($destructive ? 'Review this action before confirming.' : null)];
    }

    protected function readFields(array $values, array $types = []): array
    {
        $fields = [];
        foreach ($values as $name => $value) {
            $fields[] = $this->field($name, str($name)->headline()->toString(), $types[$name] ?? (is_bool($value) ? 'boolean' : 'text'), false, $value, editable: false);
        }

        return $fields;
    }
}
