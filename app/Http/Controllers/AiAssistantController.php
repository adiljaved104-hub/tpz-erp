<?php

namespace App\Http\Controllers;

use App\Enums\AiAssistantPermission;
use App\Enums\CustomerReturnPermission;
use App\Enums\InventoryPermission;
use App\Enums\OrderPermission;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Models\AiAssistantConversation;
use App\Models\User;
use App\Services\AiAssistant\AiAssistantService;
use App\Services\AiAssistant\AiAssistantSettings;
use App\Services\AiAssistant\AiAssistantStreamInterrupted;
use App\Services\Authorization\AiAssistantAuthorization;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\OrderAuthorization;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiAssistantController
{
    public function index(Request $request, AiAssistantAuthorization $authorization, AiAssistantService $service, AiAssistantSettings $settings, OrderAuthorization $orders, InventoryAuthorization $inventory, CustomerReturnAuthorization $returns)
    {
        $user = $request->user();
        abort_unless($user instanceof User && $authorization->allows($user, AiAssistantPermission::Use) && $settings->enabled(), 404);
        $provider = $settings->current()['provider'];
        $available = $provider !== 'openai' || filled(config('ai_assistant.api_key'));

        $links = [];
        if ($orders->allows($user, OrderPermission::View)) {
            $links[] = ['label' => 'View Sales Orders', 'url' => OrderResource::getUrl('index')];
        }
        if ($inventory->allows($user, InventoryPermission::View)) {
            $links[] = ['label' => 'Open Inventory', 'url' => ProductInventoryResource::getUrl('index')];
        }
        if ($inventory->allows($user, InventoryPermission::ViewStockRequests)) {
            $links[] = ['label' => 'View Stock Requests', 'url' => StockRequestResource::getUrl('index')];
        }
        if ($returns->allows($user, CustomerReturnPermission::View)) {
            $links[] = ['label' => 'Open Customer Returns', 'url' => CustomerReturnResource::getUrl('index')];
        }

        return response()->json(['conversations' => $service->conversations($user), 'settings' => ['provider' => $provider, 'available' => $available], 'links' => $links]);
    }

    public function create(Request $request, AiAssistantAuthorization $authorization, AiAssistantService $service, AiAssistantSettings $settings)
    {
        $user = $request->user();
        abort_unless($user instanceof User && $authorization->allows($user, AiAssistantPermission::Use) && $settings->enabled(), 403);

        return response()->json(['conversation' => $service->create($user)], 201);
    }

    public function show(Request $request, AiAssistantConversation $conversation, AiAssistantAuthorization $authorization, AiAssistantService $service)
    {
        $user = $request->user();
        abort_unless($user instanceof User && $authorization->owns($user, $conversation), 404);

        return response()->json(['messages' => $service->history($user, $conversation)]);
    }

    public function rename(Request $request, AiAssistantConversation $conversation, AiAssistantAuthorization $authorization, AiAssistantService $service)
    {
        $user = $request->user();
        abort_unless($user instanceof User && $authorization->owns($user, $conversation), 404);
        $validated = $request->validate(['title' => ['required', 'string', 'min:1', 'max:160']]);
        $conversation = $service->rename($user, $conversation, $validated['title']);

        return response()->json(['conversation' => ['id' => $conversation->id, 'title' => $conversation->title]]);
    }

    public function destroy(Request $request, AiAssistantConversation $conversation, AiAssistantAuthorization $authorization)
    {
        $user = $request->user();
        abort_unless($user instanceof User && $authorization->owns($user, $conversation), 404);
        $conversation->delete();

        return response()->noContent();
    }

    public function send(Request $request, AiAssistantConversation $conversation, AiAssistantAuthorization $authorization, AiAssistantService $service): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $authorization->owns($user, $conversation), 404);
        $validated = $request->validate(['message' => ['required', 'string', 'max:6000']]);

        $requestStartedAt = hrtime(true);

        return response()->stream(function () use ($service, $user, $conversation, $validated, $requestStartedAt): void {
            try {
                $firstVisible = null;
                $assistant = $service->send($user, $conversation, $validated['message'], function (mixed $event) use (&$firstVisible, $requestStartedAt): void {
                    if (connection_aborted()) {
                        throw new AiAssistantStreamInterrupted('Client disconnected during the stream.');
                    }
                    $payload = is_string($event) ? ['type' => 'delta', 'text' => $event] : $event;
                    if (is_string($event) && $firstVisible === null) {
                        $firstVisible = (int) ((hrtime(true) - $requestStartedAt) / 1_000_000);
                    }
                    echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                });
                echo 'data: '.json_encode(['type' => 'done', 'message_id' => $assistant->id, 'metrics' => ($assistant->response_metrics ?? []) + ['time_to_first_token_ms' => $firstVisible, 'total_request_ms' => (int) ((hrtime(true) - $requestStartedAt) / 1_000_000)]], JSON_UNESCAPED_UNICODE)."\n\n";
            } catch (\Throwable $exception) {
                $message = $exception instanceof ValidationException && isset($exception->errors()['budget'])
                    ? 'The configured AI usage budget has been reached. Contact the Owner to review the pilot limits.'
                    : 'The assistant could not complete this request. Please retry.';
                echo 'data: '.json_encode(['type' => 'error', 'message' => $message])."\n\n";
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }
}
