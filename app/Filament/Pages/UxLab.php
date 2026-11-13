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

    public string $queueFilter = 'all';

    public string $queueSearch = '';

    public string $rolePreview = 'owner';

    public string $layout = 'cards';

    public bool $drawerOpen = false;

    public ?int $selectedRecord = null;

    /** @var array<int, array{id:int,type:string,title:string,status:string,owner:string,detail:string}> */
    public array $demoQueue = [];

    /** @var array<int, array{sku:string,title:string,qty:int,price:float}> */
    public array $demoOrderLines = [];

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
        $this->seedDemoState();
    }

    public function resetDemo(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->module = 'my-workspace';
        $this->mode = 'simple';
        $this->viewport = 'desktop';
        $this->comparison = 'proposed';
        $this->feedback = '';
        $this->feedbackCaptured = false;
        $this->queueFilter = 'all';
        $this->queueSearch = '';
        $this->rolePreview = 'owner';
        $this->layout = 'cards';
        $this->drawerOpen = false;
        $this->selectedRecord = null;
        $this->seedDemoState();
    }

    public function captureFeedback(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['feedback' => ['nullable', 'string', 'max:2000']]);
        $this->feedbackCaptured = filled($this->feedback);
    }

    public function openPreview(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $this->selectedRecord = $id;
        $this->drawerOpen = true;
    }

    public function closePreview(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->drawerOpen = false;
        $this->selectedRecord = null;
    }

    public function simulateAction(int $id, string $action): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(in_array($action, ['approve', 'reserve', 'complete', 'read'], true), 422);
        $allowed = ['Approval' => ['approve', 'read'], 'Sales Order' => ['reserve', 'read'], 'Web Sales' => ['read'], 'Transfer' => ['complete', 'read']];
        $record = collect($this->demoQueue)->firstWhere('id', $id);
        abort_unless($record && in_array($action, $allowed[$record['type']] ?? [], true), 422);
        $labels = ['approve' => 'Approved (demo)', 'reserve' => 'Reserved (demo)', 'complete' => 'Completed (demo)', 'read' => 'Read (demo)'];
        $this->demoQueue = array_map(function (array $item) use ($id, $action, $labels): array {
            if ($item['id'] === $id) {
                $item['status'] = $labels[$action];
            }

            return $item;
        }, $this->demoQueue);
    }

    public function addDemoOrderLine(): void
    {
        abort_unless(static::canAccess(), 403);
        if (count($this->demoOrderLines) >= 20) {
            return;
        }
        $this->demoOrderLines[] = ['sku' => 'TPZ-DEMO-'.str_pad((string) (count($this->demoOrderLines) + 1), 3, '0', STR_PAD_LEFT), 'title' => 'Simulation product', 'qty' => 1, 'price' => 100.0];
    }

    public function updateDemoQuantity(int $index, int $quantity): void
    {
        abort_unless(static::canAccess(), 403);
        if (isset($this->demoOrderLines[$index])) {
            $this->demoOrderLines[$index]['qty'] = max(1, min($quantity, 99));
        }
    }

    public function removeDemoOrderLine(int $index): void
    {
        abort_unless(static::canAccess(), 403);
        unset($this->demoOrderLines[$index]);
        $this->demoOrderLines = array_values($this->demoOrderLines);
    }

    public function toggleLayout(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->layout = $this->layout === 'cards' ? 'table' : 'cards';
    }

    public function downloadFeedback(): mixed
    {
        abort_unless(static::canAccess(), 403);
        $content = "TPZ UX Lab session\nModule: {$this->module}\nMode: {$this->mode}\nFeedback: {$this->feedback}\n";

        return response()->streamDownload(static function () use ($content): void {
            echo $content;
        }, 'tpz-ux-lab-feedback.txt');
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
            'queue' => $this->filteredQueue(),
            'selected' => collect($this->demoQueue)->firstWhere('id', $this->selectedRecord),
            'orderTotal' => collect($this->demoOrderLines)->sum(fn (array $line): float => $line['qty'] * $line['price']),
        ];
    }

    private function seedDemoState(): void
    {
        if ($this->demoQueue !== []) {
            return;
        }

        $this->demoQueue = [
            ['id' => 1, 'type' => 'Approval', 'title' => 'Approve Stock Request SR-DEMO-001', 'status' => 'Pending', 'owner' => 'You', 'detail' => 'Requested 6 · approved 3 · remaining 3'],
            ['id' => 2, 'type' => 'Sales Order', 'title' => 'Review SO-DEMO-001', 'status' => 'Needs attention', 'owner' => 'You', 'detail' => '2 lines · reservation preview only'],
            ['id' => 3, 'type' => 'Web Sales', 'title' => 'Prepare web order WS-DEMO-014', 'status' => 'Pending shipment', 'owner' => 'Team', 'detail' => 'Customer and fulfilment context ready'],
            ['id' => 4, 'type' => 'Transfer', 'title' => 'Receive ST-DEMO-004', 'status' => 'Awaiting receipt', 'owner' => 'You', 'detail' => '8 shipped · 7 received · 1 unresolved'],
        ];
        $this->demoOrderLines = [['sku' => 'TPZ-DEMO-001', 'title' => 'Simulation laptop', 'qty' => 1, 'price' => 3350.0]];
    }

    /** @return array<int, array{id:int,type:string,title:string,status:string,owner:string,detail:string}> */
    private function filteredQueue(): array
    {
        return array_values(array_filter($this->demoQueue, function (array $item): bool {
            $filterMatch = $this->queueFilter === 'all' || strcasecmp($item['type'], $this->queueFilter) === 0;
            $searchMatch = $this->queueSearch === '' || str_contains(strtolower(implode(' ', $item)), strtolower($this->queueSearch));

            return $filterMatch && $searchMatch;
        }));
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
