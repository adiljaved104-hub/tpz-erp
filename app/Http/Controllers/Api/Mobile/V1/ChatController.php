<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\ChatPermission;
use App\Models\Conversation;
use App\Models\Employee;
use App\Models\Team;
use App\Services\Authorization\ChatAuthorization;
use App\Services\Chat\ChatQueryService;
use App\Services\Chat\ChatRecordMentionService;
use App\Services\Chat\ConversationMessageService;
use App\Services\Chat\ConversationPresenter;
use App\Services\Chat\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends MobileController
{
    private function authorize(Request $request): void
    {
        abort_unless(app(ChatAuthorization::class)->allows($request->user(), ChatPermission::View), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize($request);
        $d = $request->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50',
            'filter' => 'nullable|in:all,direct,teams,contexts,channels,archived']);
        $query = app(ChatQueryService::class)->inbox($request->user(), $d['q'] ?? '', $d['filter'] ?? 'all');
        // Match the record-level team authorization, including inactive teams.
        $query->where(fn ($q) => $q->where('type', '!=', 'team')->orWhereHas('team', fn ($t) => $t->where('status', true)));
        $page = $query->paginate($d['per_page'] ?? 25)->through(fn ($c) => $this->present($request, $c));

        return response()->json([...$page->toArray(), 'unread_count' => app(ChatQueryService::class)->totalUnread($request->user())]);
    }

    private function present(Request $request, Conversation $c, bool $detail = false): array
    {
        app(ChatAuthorization::class)->authorizeConversation($request->user(), $c);
        $c->loadMissing(['team:id,name', 'participants.employee:id,name', 'latestMessage.sender:id,name']);
        $unread = app(ConversationMessageService::class)->unreadCount($request->user(), $c);
        $data = ['id' => $c->id, 'title' => app(ConversationPresenter::class)->label($c, $request->user()),
            'subtitle' => app(ConversationPresenter::class)->typeLabel($c), 'meta' => $c->latestMessage?->created_at?->toIso8601String(),
            'status' => $c->status->value, 'unread_count' => $unread];
        if (! $detail) {
            return $data;
        }

        return [...$data,
            'fields' => ['type' => $c->type->value, 'team' => $c->team?->name],
            'participants' => $c->participants->whereNull('left_at')->map(fn ($participant) => [
                'id' => $participant->employee_id,
                'name' => $participant->employee?->name,
            ])->values(),
            'latest_message' => $c->latestMessage ? [
                'id' => $c->latestMessage->id,
                'sender' => $c->latestMessage->sender?->name,
                'created_at' => $c->latestMessage->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    public function options(Request $request): JsonResponse
    {
        $this->authorize($request);
        $d = $request->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'type' => 'nullable|in:direct,team']);
        $service = app(ChatQueryService::class);
        $values = ($d['type'] ?? 'direct') === 'team' ? $service->teamOptions($request->user()) : $service->directEmployeeOptions($request->user());
        $values = collect($values)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])
            ->filter(fn ($r) => str_contains(mb_strtolower($r['name']), mb_strtolower($d['q'] ?? '')))->values();

        return response()->json(['data' => $values->forPage($d['page'] ?? 1, 25)->values(), 'last_page' => max(1, (int) ceil($values->count() / 25))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize($request);
        $d = $request->validate(['type' => 'required|in:direct,team', 'employee_id' => 'required_if:type,direct|nullable|integer', 'team_id' => 'required_if:type,team|nullable|integer']);
        $service = app(ConversationService::class);
        if ($d['type'] === 'direct') {
            $other = Employee::query()->with('user')->findOrFail($d['employee_id']);
            abort_unless($other->user && app(ChatAuthorization::class)->allows($other->user, ChatPermission::Direct), 403);
            $c = $service->direct($request->user(), $other);
        } else {
            $c = $service->team($request->user(), Team::query()->findOrFail($d['team_id']));
        }

        return response()->json(['data' => $this->present($request, $c)]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        return response()->json(['data' => $this->present($request, $conversation, true)]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        app(ChatAuthorization::class)->authorizeConversation($request->user(), $conversation);
        $d = $request->validate(['before' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
        $rows = $conversation->messages()->with('sender:id,name')->when($d['before'] ?? null, fn ($q, $id) => $q->where('id', '<', $id))->orderByDesc('id')->limit(($d['per_page'] ?? 30) + 1)->get();
        $hasMore = $rows->count() > ($d['per_page'] ?? 30);
        $rows = $rows->take($d['per_page'] ?? 30);
        $previews = app(ChatRecordMentionService::class)->previewsForMessages($rows, $request->user(), 5000);

        return response()->json(['data' => $rows->map(fn ($m) => ['id' => $m->id, 'body' => $previews[$m->id] ?? '',
            'sender' => $m->sender?->name, 'mine' => $m->sender_employee_id === $request->user()->employee->id, 'created_at' => $m->created_at->toIso8601String()])->reverse()->values(),
            'next_before' => $hasMore ? $rows->last()?->id : null]);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        app(ChatAuthorization::class)->authorizeConversation($request->user(), $conversation);
        $d = $request->validate(['body' => 'required|string|max:5000']);
        $m = app(ConversationMessageService::class)->send($request->user(), $conversation, $d['body']);

        return response()->json(['data' => ['id' => $m->id]], 201);
    }

    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        app(ChatAuthorization::class)->authorizeConversation($request->user(), $conversation);
        $d = $request->validate(['message_id' => 'required|integer|min:1']);
        app(ConversationMessageService::class)->markRead($request->user(), $conversation, $conversation->messages()->findOrFail($d['message_id']));

        return response()->json(['data' => ['unread_count' => app(ConversationMessageService::class)->unreadCount($request->user(), $conversation)]]);
    }
}
