<?php

namespace App\Filament\Pages;

use App\Enums\EmployeeRole;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Owner-only visual concept studio. Every workflow is simulated in memory. */
class UxLab extends Page
{
    protected string $view = 'filament.pages.ux-lab';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'UX Lab';

    protected static ?string $slug = 'ux-lab';

    protected static ?int $navigationSort = 99;

    public string $direction = 'aurora';

    public string $workspace = 'home';

    public string $screen = 'overview';

    public string $viewport = 'desktop';

    public string $rolePreview = 'owner';

    public string $search = '';

    public string $flowStep = 'catalog';

    public bool $sidebarCollapsed = false;

    public bool $drawerOpen = false;

    public bool $modalOpen = false;

    public ?string $selectedRecord = null;

    public array $demoOrderLines = [];

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
        $this->resetDemo();
    }

    public function resetDemo(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->direction = 'aurora';
        $this->workspace = 'home';
        $this->screen = 'overview';
        $this->viewport = 'desktop';
        $this->rolePreview = 'owner';
        $this->search = '';
        $this->flowStep = 'catalog';
        $this->sidebarCollapsed = false;
        $this->drawerOpen = false;
        $this->modalOpen = false;
        $this->selectedRecord = null;
        $this->demoOrderLines = [];
        $this->feedback = '';
        $this->feedbackCaptured = false;
    }

    public function chooseWorkspace(string $workspace): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(array_key_exists($workspace, $this->workspaceCatalog()), 422);
        $this->workspace = $workspace;
        $this->screen = 'overview';
        $this->drawerOpen = false;
    }

    public function chooseScreen(string $screen): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(array_key_exists($screen, $this->screenCatalog()[$this->workspace] ?? []), 422);
        $this->screen = $screen;
    }

    public function openRecord(string $record): void
    {
        abort_unless(static::canAccess(), 403);
        $ids = array_column([...$this->homeItems(), ...$this->salesItems(), ...$this->inventoryItems()], 'id');
        abort_unless(in_array($record, $ids, true), 422);
        $this->selectedRecord = $record;
        $this->drawerOpen = true;
    }

    public function closeDrawer(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->drawerOpen = false;
        $this->selectedRecord = null;
    }

    public function openCreateOrder(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->workspace = 'sales';
        $this->screen = 'create';
        $this->flowStep = 'catalog';
        $this->demoOrderLines = [];
        $this->modalOpen = true;
    }

    public function closeModal(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->modalOpen = false;
    }

    public function advanceOrderFlow(): void
    {
        abort_unless(static::canAccess(), 403);
        $steps = ['catalog', 'customer', 'review', 'reserve', 'confirmed'];
        abort_unless($this->modalOpen && $this->demoOrderLines !== [] && in_array($this->flowStep, $steps, true), 422);
        $index = array_search($this->flowStep, $steps, true);
        $this->flowStep = $steps[min(($index === false ? 0 : $index) + 1, count($steps) - 1)];
    }

    public function addDemoProduct(string $sku): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->modalOpen && $this->flowStep === 'catalog', 422);
        $product = collect($this->demoProducts())->firstWhere('sku', $sku);
        abort_unless($product !== null, 422);
        abort_if(count($this->demoOrderLines) >= 20, 422);

        foreach ($this->demoOrderLines as &$line) {
            if ($line['sku'] === $sku) {
                $line['qty'] = min(99, $line['qty'] + 1);

                return;
            }
        }
        unset($line);

        $this->demoOrderLines[] = [
            'sku' => $product['sku'],
            'title' => $product['title'],
            'qty' => 1,
            'price' => $product['price'],
        ];
        $this->flowStep = 'customer';
    }

    public function updateDemoQuantity(int $index, int $quantity): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->modalOpen && isset($this->demoOrderLines[$index]), 422);
        $this->demoOrderLines[$index]['qty'] = max(1, min(99, $quantity));
    }

    public function removeDemoProduct(int $index): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->modalOpen && isset($this->demoOrderLines[$index]), 422);
        array_splice($this->demoOrderLines, $index, 1);
        if ($this->demoOrderLines === []) {
            $this->flowStep = 'catalog';
        }
    }

    public function captureFeedback(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['feedback' => ['nullable', 'string', 'max:2000']]);
        $this->feedbackCaptured = filled($this->feedback);
    }

    public function downloadFeedback(): mixed
    {
        abort_unless(static::canAccess(), 403);
        $content = "TPZ UX Lab review\nDirection: {$this->direction}\nWorkspace: {$this->workspace}\nFeedback: {$this->feedback}\n";

        return response()->streamDownload(static function () use ($content): void {
            echo $content;
        }, 'tpz-ux-lab-review.txt');
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return [
            'workspaces' => $this->workspaceCatalog(),
            'screens' => $this->screenCatalog(),
            'homeItems' => $this->searchItems($this->homeItems()),
            'salesItems' => $this->screenItems($this->salesItems(), 'sales'),
            'inventoryItems' => $this->screenItems($this->inventoryItems(), 'inventory'),
            'products' => $this->searchItems($this->demoProducts()),
            'orderTotal' => collect($this->demoOrderLines)->sum(fn (array $line): float => $line['qty'] * $line['price']),
            'selected' => $this->selectedRecord,
        ];
    }

    private function screenItems(array $items, string $workspace): array
    {
        if ($workspace === 'sales') {
            $types = ['orders' => 'Sales order', 'web' => 'Web sale', 'quotations' => 'Quotation'];
            if (isset($types[$this->screen])) {
                $items = array_values(array_filter($items, fn (array $item): bool => $item['type'] === $types[$this->screen]));
            }
        } elseif ($workspace === 'inventory') {
            $types = ['requests' => 'Stock request', 'transfers' => 'Stock transfer', 'adjustments' => 'Stock adjustment'];
            if (isset($types[$this->screen])) {
                $items = array_values(array_filter($items, fn (array $item): bool => $item['type'] === $types[$this->screen]));
            }
        }

        return $this->searchItems($items);
    }

    private function searchItems(array $items): array
    {
        $term = mb_strtolower(trim($this->search));
        if ($term === '') {
            return $items;
        }

        return array_values(array_filter($items, static function (array $item) use ($term): bool {
            return str_contains(mb_strtolower(implode(' ', array_map('strval', $item))), $term);
        }));
    }

    private function workspaceCatalog(): array
    {
        return [
            'home' => ['label' => 'Home', 'icon' => '⌂', 'description' => 'Your work, alerts, and next actions'],
            'sales' => ['label' => 'Sales', 'icon' => '↗', 'description' => 'Orders, web sales, quotations, and shipments'],
            'inventory' => ['label' => 'Inventory & Warehouse', 'icon' => '▦', 'description' => 'Products, allocations, requests, and transfers'],
            'purchasing' => ['label' => 'Purchasing', 'icon' => '◈', 'description' => 'Purchases, receipts, and supplier follow-up'],
            'returns' => ['label' => 'Returns & QC', 'icon' => '↩', 'description' => 'Returns, inspection, evidence, and exceptions'],
            'finance' => ['label' => 'Finance & Accounting', 'icon' => '₊', 'description' => 'Owner-scoped reports and accounting review'],
            'team' => ['label' => 'Team & HR', 'icon' => '◌', 'description' => 'Tasks, attendance, leave, and responsibilities'],
        ];
    }

    private function screenCatalog(): array
    {
        return [
            'home' => ['overview' => 'My Workspace', 'activity' => 'Recent activity'],
            'sales' => ['overview' => 'Sales overview', 'orders' => 'Sales orders', 'web' => 'Web sales', 'quotations' => 'Quotations'],
            'inventory' => ['overview' => 'Inventory overview', 'requests' => 'Stock requests', 'transfers' => 'Transfers', 'adjustments' => 'Adjustments'],
            'purchasing' => ['overview' => 'Purchasing overview'], 'returns' => ['overview' => 'Returns & QC overview'],
            'finance' => ['overview' => 'Finance overview'], 'team' => ['overview' => 'Team overview'],
        ];
    }

    private function homeItems(): array
    {
        return [
            ['id' => 'approval-001', 'type' => 'Approval', 'title' => 'Approve Stock Request SR-DEMO-001', 'meta' => 'Requested 6 · approved 3 · remaining 3', 'status' => 'Waiting'],
            ['id' => 'sales-001', 'type' => 'Sales Order', 'title' => 'Review SO-DEMO-001', 'meta' => '2 lines · reservation preview ready', 'status' => 'Needs attention'],
            ['id' => 'transfer-004', 'type' => 'Transfer', 'title' => 'Receive ST-DEMO-004', 'meta' => '8 shipped · 7 received · 1 unresolved', 'status' => 'Due today'],
            ['id' => 'alert-012', 'type' => 'Alert', 'title' => 'One Web Sale needs shipment review', 'meta' => 'Customer and fulfilment context ready', 'status' => 'New'],
        ];
    }

    private function salesItems(): array
    {
        return [
            ['id' => 'SO-DEMO-001', 'type' => 'Sales order', 'title' => 'SO-DEMO-001 · Noor Electronics', 'meta' => 'AED 4,850 · 2 products · Main Warehouse', 'status' => 'Reserved'],
            ['id' => 'WS-DEMO-014', 'type' => 'Web sale', 'title' => 'WS-DEMO-014 · Carrefour UAE', 'meta' => 'AED 3,350 · manual sourcing preview', 'status' => 'Pending shipment'],
            ['id' => 'QT-DEMO-031', 'type' => 'Quotation', 'title' => 'QT-DEMO-031 · HP laptop bundle', 'meta' => 'AED 6,200 · expires in 4 days', 'status' => 'Draft'],
        ];
    }

    private function inventoryItems(): array
    {
        return [
            ['id' => 'TPZ-DEMO-001', 'type' => 'Product', 'title' => 'TPZ-DEMO-001 · Simulation laptop', 'meta' => '18 sellable · Main Warehouse · M Zaid', 'status' => 'Available'],
            ['id' => 'SR-DEMO-001', 'type' => 'Stock request', 'title' => 'SR-DEMO-001 · Partial approval', 'meta' => 'Requested 6 · transferred 3 · remaining 3', 'status' => 'Partially approved'],
            ['id' => 'ST-DEMO-004', 'type' => 'Stock transfer', 'title' => 'ST-DEMO-004 · Warehouse receiving', 'meta' => '8 shipped · 7 received · 1 unresolved', 'status' => 'Exception'],
            ['id' => 'ADJ-DEMO-002', 'type' => 'Stock adjustment', 'title' => 'ADJ-DEMO-002 · Pending count review', 'meta' => '2 draft rows · warehouse stock unchanged', 'status' => 'Draft'],
        ];
    }

    private function demoProducts(): array
    {
        return [
            ['sku' => 'TPZ-DEMO-001', 'title' => 'Lenovo Yoga 7i · 16GB · 512GB SSD', 'stock' => '18 available', 'price' => 3350.0],
            ['sku' => 'TPZ-DEMO-002', 'title' => 'HP Series 5 · 27-inch FHD Monitor', 'stock' => '6 available', 'price' => 720.0],
            ['sku' => 'TPZ-DEMO-003', 'title' => 'Acer Nitro Lite · RTX 4050 · 16GB', 'stock' => 'Manual source', 'price' => 3090.0],
        ];
    }
}
