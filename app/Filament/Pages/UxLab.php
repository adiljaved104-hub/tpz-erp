<?php

namespace App\Filament\Pages;

use App\Enums\EmployeeRole;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Owner-only, read-only workspace for evaluating the next ERP interaction model.
 *
 * This page intentionally does not call operational write services. It is a
 * safe visual prototype surface until a reviewed module is promoted.
 */
class UxLab extends Page
{
    protected string $view = 'filament.pages.ux-lab';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'UX Lab';

    protected static ?string $slug = 'ux-lab';

    protected static ?int $navigationSort = 99;

    public string $module = 'my-workspace';

    public string $mode = 'simple';

    public string $viewport = 'desktop';

    public string $comparison = 'proposed';

    public string $feedback = '';

    public bool $feedbackCaptured = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->employee?->role === EmployeeRole::Owner;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function resetDemo(): void
    {
        $this->module = 'my-workspace';
        $this->mode = 'simple';
        $this->viewport = 'desktop';
        $this->comparison = 'proposed';
        $this->feedback = '';
        $this->feedbackCaptured = false;
    }

    public function captureFeedback(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['feedback' => ['nullable', 'string', 'max:2000']]);
        $this->feedbackCaptured = filled($this->feedback);
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return [
            'modules' => [
                'my-workspace' => ['label' => 'My Workspace', 'description' => 'Assigned work, approvals, alerts, and next actions.'],
                'dashboard' => ['label' => 'Main Dashboard', 'description' => 'Role-scoped operational summary and quick actions.'],
                'sales-orders' => ['label' => 'Sales Orders', 'description' => 'Search, responsibility context, reservations, and fulfilment.'],
                'web-sales' => ['label' => 'Web Sales', 'description' => 'Web order pipeline, customer context, and exceptions.'],
                'inventory' => ['label' => 'Inventory', 'description' => 'Location, holder, allocation, and movement visibility.'],
            ],
            'cards' => $this->cardsForModule(),
        ];
    }

    /** @return array<int, array{label:string,value:string,detail:string,accent:string}> */
    private function cardsForModule(): array
    {
        return match ($this->module) {
            'dashboard' => [
                ['label' => 'Open Actions', 'value' => '12', 'detail' => 'Role-scoped next actions', 'accent' => 'primary'],
                ['label' => 'Awaiting Approval', 'value' => '4', 'detail' => 'Requires your decision', 'accent' => 'warning'],
                ['label' => 'Inventory Exceptions', 'value' => '2', 'detail' => 'No stock is changed by this prototype', 'accent' => 'danger'],
            ],
            'sales-orders' => [
                ['label' => 'My Open Orders', 'value' => '8', 'detail' => 'Responsibility-scoped', 'accent' => 'primary'],
                ['label' => 'Reserved', 'value' => '6', 'detail' => 'Existing reservation records only', 'accent' => 'success'],
                ['label' => 'Needs Attention', 'value' => '1', 'detail' => 'Review before fulfilment', 'accent' => 'warning'],
            ],
            'web-sales' => [
                ['label' => 'Today', 'value' => '5', 'detail' => 'Visible within existing Web Sales scope', 'accent' => 'primary'],
                ['label' => 'Pending Ship', 'value' => '2', 'detail' => 'No shipment action is available here', 'accent' => 'warning'],
                ['label' => 'Exceptions', 'value' => '1', 'detail' => 'Read-only preview', 'accent' => 'danger'],
            ],
            'inventory' => [
                ['label' => 'Sellable Units', 'value' => '—', 'detail' => 'Use existing responsibility-scoped services', 'accent' => 'success'],
                ['label' => 'Allocated', 'value' => '—', 'detail' => 'No balances are changed', 'accent' => 'primary'],
                ['label' => 'Exceptions', 'value' => '—', 'detail' => 'No operational query is executed', 'accent' => 'warning'],
            ],
            default => [
                ['label' => 'My Tasks', 'value' => '6', 'detail' => 'Assigned to you', 'accent' => 'primary'],
                ['label' => 'Approvals', 'value' => '3', 'detail' => 'Waiting for your decision', 'accent' => 'warning'],
                ['label' => 'Notifications', 'value' => '4', 'detail' => 'Unread operational alerts', 'accent' => 'success'],
            ],
        };
    }
}
