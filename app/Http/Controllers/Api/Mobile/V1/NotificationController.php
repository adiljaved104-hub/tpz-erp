<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Services\Mobile\NotificationTarget;
use App\Services\Notifications\NotificationInboxService;
use App\Services\Notifications\StockAlertAcknowledgementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50', 'unread' => 'nullable|boolean']);
        $inbox = app(NotificationInboxService::class);
        $user = $request->user();
        $page = $inbox->query($user)->when($d['unread'] ?? false, fn ($q) => $q->whereNull('read_at'))->paginate($d['per_page'] ?? 25);
        // Never return stored sensitive previews after access has been revoked.
        $messages = $inbox->displayMessages($page->getCollection(), $user);
        $page->through(function ($n) use ($user, $messages) {
            $target = app(NotificationTarget::class)->resolve($user, $n->data);
            $acknowledgment = $target ? app(StockAlertAcknowledgementService::class)->context($user, $n) : null;

            return ['id' => $n->id, 'title' => $target ? ($n->data['title'] ?? 'ERP notification') : 'ERP notification',
                'subtitle' => $target ? ($messages[$n->id] ?? null) : 'Open the ERP for details, or access is no longer available.',
                'meta' => $n->created_at?->toIso8601String(), 'created_at' => $n->created_at?->toIso8601String(),
                'status' => $n->read_at ? 'read' : 'unread', 'read_at' => $n->read_at?->toIso8601String(),
                'type' => $target ? $n->type : null, 'target' => $target,
                'acknowledgment_required' => $acknowledgment !== null,
                'acknowledged' => $acknowledgment['acknowledged'] ?? false,
                'acknowledged_at' => $acknowledgment['acknowledged_at'] ?? null,
                'incident' => $acknowledgment['incident'] ?? null];
        });

        return response()->json([...$page->toArray(), 'unread_count' => $inbox->unreadCount($user)]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $inbox = app(NotificationInboxService::class);
        $n = $inbox->own($request->user(), $notification);
        $target = app(NotificationTarget::class)->resolve($request->user(), $n->data);
        $inbox->markRead($request->user(), $notification);

        return response()->json(['data' => ['target' => $target], 'unread_count' => $inbox->unreadCount($request->user())]);
    }

    public function acknowledge(Request $request, string $notification): JsonResponse
    {
        $inbox = app(NotificationInboxService::class);
        $record = $inbox->own($request->user(), $notification);
        abort_if(app(NotificationTarget::class)->resolve($request->user(), $record->data) === null, 404);

        return response()->json([
            'data' => app(StockAlertAcknowledgementService::class)->acknowledge($request->user(), $record),
            'unread_count' => $inbox->unreadCount($request->user()),
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        app(NotificationInboxService::class)->markAllRead($request->user());

        return response()->json(['data' => ['unread_count' => 0]]);
    }
}
