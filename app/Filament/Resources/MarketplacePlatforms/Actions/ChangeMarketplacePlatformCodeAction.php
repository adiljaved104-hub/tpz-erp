<?php

namespace App\Filament\Resources\MarketplacePlatforms\Actions;

use App\Actions\Responsibilities\ChangeMarketplacePlatformCode;
use App\DTOs\Responsibilities\ChangeMarketplacePlatformCodeData;
use App\Exceptions\MarketplacePlatformCodeChangeException;
use App\Models\MarketplacePlatform;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

class ChangeMarketplacePlatformCodeAction
{
    public static function make(): Action
    {
        return Action::make('changeCode')
            ->label('Change Code')
            ->icon('heroicon-o-pencil-square')
            ->requiresConfirmation()
            ->modalHeading('Change Platform Code')
            ->modalDescription('Codes may only be corrected before a Platform has operational references.')
            ->schema([
                TextInput::make('new_code')
                    ->label('New Code')
                    ->required()
                    ->maxLength(100)
                    ->regex('/^[a-z0-9]+(?:_[a-z0-9]+)*$/')
                    ->helperText('Lowercase letters, numbers, and underscores only.'),
                Textarea::make('reason')->required()->maxLength(2000),
            ])
            ->authorize(fn (MarketplacePlatform $record): bool => auth()->user()->can('changeCode', $record))
            ->action(function (Action $action, MarketplacePlatform $record, array $data): void {
                try {
                    app(ChangeMarketplacePlatformCode::class)->handle(
                        $record,
                        new ChangeMarketplacePlatformCodeData($data['new_code'], $data['reason']),
                        auth()->user(),
                    );
                } catch (MarketplacePlatformCodeChangeException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    $action->failure();
                }
            })
            ->successNotificationTitle('Platform code changed');
    }
}
