<?php

namespace App\Filament\Actions;

use App\Enums\ChatPermission;
use App\Filament\Pages\Chat;
use App\Models\User;
use App\Services\Authorization\ChatAuthorization;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

class OpenChatDiscussionAction
{
    public static function make(Model $record): Action
    {
        return Action::make('discussion')
            ->label('Discussion')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->visible(function () use ($record): bool {
                $user = auth()->user();

                return $user instanceof User
                    && app(ChatAuthorization::class)->allows($user, ChatPermission::Context)
                    && app(ChatAuthorization::class)->canViewContext($user, $record);
            })
            ->url(fn (): string => Chat::getUrl([
                'contextType' => $record->getMorphClass(),
                'contextId' => $record->getKey(),
            ]));
    }
}
