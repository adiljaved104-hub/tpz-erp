<?php

namespace App\Filament\Tables\Columns;

use App\Enums\PurchasePermission;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Purchases\PurchaseCostHistoryService;
use App\Support\AedMoney;
use Closure;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

class PurchaseCostHistoryColumn
{
    public static function make(Closure $productId): TextColumn
    {
        return TextColumn::make('latest_purchase_cost')
            ->label('Latest Purchase Cost')
            ->formatStateUsing(fn ($state): ?string => $state === null ? null : AedMoney::format((string) $state))
            ->placeholder('No received purchase cost')
            ->icon('heroicon-o-clock')
            ->tooltip('Click or tap to view the last five received Purchase costs.')
            ->action(
                Action::make('purchaseCostHistory')
                    ->label('Purchase Cost History')
                    ->icon('heroicon-o-clock')
                    ->authorize(fn (): bool => self::allowed())
                    ->slideOver()
                    ->modalHeading('Purchase Cost History')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (Model $record) use ($productId): View {
                        $user = auth()->user();

                        abort_unless($user instanceof User, 403);

                        return view('filament.purchases.purchase-cost-history', [
                            'history' => app(PurchaseCostHistoryService::class)->summaryForProduct(
                                (int) $productId($record),
                                $user,
                            ),
                        ]);
                    }),
            );
    }

    public static function allowed(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(PurchaseAuthorization::class)->allows($user, PurchasePermission::ViewCostHistory);
    }
}
