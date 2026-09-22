<?php

namespace App\Filament\Pages\Administration;

use App\Enums\OrderPermission;
use App\Models\User;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Orders\OrderAmendmentService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class OrderSettings extends Page
{
    protected string $view = 'filament.pages.administration.order-settings';

    protected static ?string $slug = 'administration/order-settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Order Settings';

    public int $amendmentWindowHours = OrderAmendmentService::DEFAULT_WINDOW_HOURS;

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrderAuthorization::class)->allows($actor, OrderPermission::ManageAmendmentSettings);
    }

    public function mount(OrderAmendmentService $service): void
    {
        abort_unless(static::canAccess(), 403);
        $this->amendmentWindowHours = $service->windowHours();
    }

    public function save(OrderAmendmentService $service): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->validate(['amendmentWindowHours' => ['required', 'integer', 'in:1,2,5,12,24']]);
        $service->saveWindowHours((int) $data['amendmentWindowHours'], auth()->user());
        Notification::make()->success()->title('Order amendment window saved')->send();
    }
}
