<?php

namespace App\Http\Controllers;

use App\Enums\AiAssistantPermission;
use App\Models\AiAssistantConversation;
use App\Models\User;
use App\Services\AiAssistant\AiAssistantAuthorization;
use App\Services\AiAssistant\AiAssistantService;
use App\Services\AiAssistant\AiAssistantSettings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\ValidationException;

class AiAssistantController
{
    public function index(Request $request, AiAssistantAuthorization $authorization, AiAssistantService $service, AiAssistantSettings $settings)
    {
        $user = $request->user();
        abort_unless($user instanceof User && $authorization->allows($user, AiAssistantPermission::Use) && $settings->enabled(), 404);
        $provider = $settings->current()['provider'];
        $available = $provider !== 'openai' || filled(config('ai_assistant.api_key'));
        return response()->json(['conversations' => $service->conversations($user), 'settings' => ['provider' => $provider, 'available' => $available]]);
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
        return response()->stream(function () use ($service, $user, $conversation, $validated): void {
            try {
                $assistant = $service->send($user, $conversation, $validated['message'], function (string $text): void {
                    echo 'data: '.json_encode(['type' => 'delta', 'text' => $text], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                });
                echo 'data: '.json_encode(['type' => 'done', 'message_id' => $assistant->id], JSON_UNESCAPED_UNICODE)."\n\n";
            } catch (\Throwable $exception) {
                $message = $exception instanceof ValidationException && isset($exception->errors()['budget'])
                    ? 'The configured AI usage budget has been reached. Contact the Owner to review the pilot limits.'
                    : 'The assistant could not complete this request. Please retry.';
                echo 'data: '.json_encode(['type' => 'error', 'message' => $message])."\n\n";
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }
}
