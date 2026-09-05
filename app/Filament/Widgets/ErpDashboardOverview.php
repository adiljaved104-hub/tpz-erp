<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\Dashboard\DashboardPreferenceService;
use App\Services\Dashboard\DashboardWidgetRegistry;
use App\Services\Dashboard\ErpDashboardService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Validation\ValidationException;

class ErpDashboardOverview extends Widget
{
    protected string $view = 'filament.widgets.erp-dashboard-overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    protected static bool $isLazy = false;

    public string $period = 'today';

    public ?string $customFrom = null;

    public ?string $customTo = null;

    public ?string $appliedCustomFrom = null;

    public ?string $appliedCustomTo = null;

    public string $inventoryScope = 'company';

    public ?string $inventoryTeamId = null;

    public ?string $inventoryEmployeeId = null;

    /** @var array<int, string> */
    public array $widgetOrder = [];

    /** @var array<int, string> */
    public array $hiddenWidgets = [];

    public bool $customizeMode = false;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->employee?->status === true;
    }

    public function mount(): void
    {
        $this->loadPreferenceState();
    }

    public function setPeriod(string $period): void
    {
        $this->period = in_array($period, ['today', 'week', 'month', 'custom'], true) ? $period : 'today';

        if ($this->period === 'custom' && ($this->customFrom === null || $this->customTo === null)) {
            $today = CarbonImmutable::today(config('app.timezone'));
            $this->customFrom = $today->subDays(29)->toDateString();
            $this->customTo = $today->toDateString();
            $this->appliedCustomFrom = $this->customFrom;
            $this->appliedCustomTo = $this->customTo;
        }
    }

    public function applyCustomRange(): void
    {
        $this->resetValidation(['customFrom', 'customTo']);
        $validated = $this->validate([
            'customFrom' => ['required', 'date_format:Y-m-d'],
            'customTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:customFrom'],
        ], [
            'customTo.after_or_equal' => 'The To Date must be on or after the From Date.',
        ]);

        $from = CarbonImmutable::createFromFormat('Y-m-d', $validated['customFrom'], config('app.timezone'));
        $to = CarbonImmutable::createFromFormat('Y-m-d', $validated['customTo'], config('app.timezone'));
        if ($from->diffInDays($to) > 366) {
            $this->addError('customTo', 'The custom Dashboard range cannot exceed 366 days.');

            return;
        }

        $this->appliedCustomFrom = $validated['customFrom'];
        $this->appliedCustomTo = $validated['customTo'];
        $this->period = 'custom';
    }

    public function updatedInventoryScope(string $scope): void
    {
        $this->inventoryScope = in_array($scope, ['company', 'team', 'employee'], true) ? $scope : 'company';
        $this->inventoryTeamId = null;
        $this->inventoryEmployeeId = null;
    }

    public function toggleCustomizeMode(): void
    {
        $this->customizeMode = ! $this->customizeMode;
    }

    public function finishCustomizing(): void
    {
        $this->customizeMode = false;
    }

    public function toggleWidget(string $key): void
    {
        $this->assertAuthorizedWidget($key);

        if (in_array($key, $this->hiddenWidgets, true)) {
            $this->hiddenWidgets = array_values(array_diff($this->hiddenWidgets, [$key]));
        } else {
            $this->hiddenWidgets[] = $key;
        }

        $this->persistPreference('Dashboard visibility saved');
    }

    /** @param array<int, mixed> $orderedKeys */
    public function reorderWidgets(array $orderedKeys): void
    {
        /** @var User $user */
        $user = auth()->user();
        $visible = array_values(array_diff($this->widgetOrder, $this->hiddenWidgets));
        $orderedKeys = array_values($orderedKeys);

        if (count($orderedKeys) !== count(array_unique($orderedKeys)) || array_diff($orderedKeys, $visible) !== [] || array_diff($visible, $orderedKeys) !== []) {
            throw ValidationException::withMessages(['layout' => 'The Dashboard order is invalid.']);
        }

        $this->widgetOrder = array_values(array_merge($orderedKeys, $this->hiddenWidgets));
        app(DashboardPreferenceService::class)->save(
            $user,
            DashboardWidgetRegistry::DASHBOARD_KEY,
            $this->widgetOrder,
            $this->hiddenWidgets,
        );
        $this->savedNotification('Dashboard layout saved');
    }

    public function moveWidget(string $key, string $direction): void
    {
        $this->assertAuthorizedWidget($key);
        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['layout' => 'The Dashboard move direction is invalid.']);
        }

        $index = array_search($key, $this->widgetOrder, true);
        if ($index === false) {
            return;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= count($this->widgetOrder)) {
            return;
        }

        [$this->widgetOrder[$index], $this->widgetOrder[$target]] = [$this->widgetOrder[$target], $this->widgetOrder[$index]];
        $this->widgetOrder = array_values($this->widgetOrder);
        $this->persistPreference('Dashboard order saved');
    }

    public function resetDashboard(): void
    {
        /** @var User $user */
        $user = auth()->user();
        app(DashboardPreferenceService::class)->reset($user);
        $this->loadPreferenceState();
        $this->savedNotification('Dashboard reset to your role default');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $registry = app(DashboardWidgetRegistry::class);
        $authorizedDefinitions = $registry->authorized($user);
        $authorizedKeys = $authorizedDefinitions->keys()->all();
        $layout = array_values(array_unique(array_merge(
            array_values(array_intersect($this->widgetOrder, $authorizedKeys)),
            array_values(array_diff($authorizedKeys, $this->widgetOrder)),
        )));
        $hidden = array_values(array_intersect($this->hiddenWidgets, $authorizedKeys));
        $visible = array_values(array_diff($layout, $hidden));

        return app(ErpDashboardService::class)->forUser(
            $user,
            $this->period,
            $this->appliedCustomFrom,
            $this->appliedCustomTo,
            $this->inventoryScope,
            filled($this->inventoryTeamId) ? (int) $this->inventoryTeamId : null,
            filled($this->inventoryEmployeeId) ? (int) $this->inventoryEmployeeId : null,
            $visible,
        ) + [
            'widget_order' => $layout,
            'visible_widget_keys' => $visible,
            'hidden_widget_keys' => $hidden,
            'available_widgets' => $layout === []
                ? collect()
                : collect($layout)->map(fn (string $key): array => $authorizedDefinitions->get($key))->filter()->values(),
            'widget_definitions' => $authorizedDefinitions,
            'customize_mode' => $this->customizeMode,
        ];
    }

    private function loadPreferenceState(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $state = app(DashboardPreferenceService::class)->resolve($user);
        $this->widgetOrder = $state['layout'];
        $this->hiddenWidgets = $state['hidden'];
    }

    private function persistPreference(string $message): void
    {
        /** @var User $user */
        $user = auth()->user();
        app(DashboardPreferenceService::class)->save(
            $user,
            DashboardWidgetRegistry::DASHBOARD_KEY,
            $this->widgetOrder,
            $this->hiddenWidgets,
        );
        $this->savedNotification($message);
    }

    private function assertAuthorizedWidget(string $key): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! app(DashboardWidgetRegistry::class)->isAuthorized($user, $key)) {
            throw ValidationException::withMessages(['widget' => 'This Dashboard widget is not available for your access.']);
        }
    }

    private function savedNotification(string $title): void
    {
        Notification::make()->success()->title($title)->duration(1800)->send();
    }
}
