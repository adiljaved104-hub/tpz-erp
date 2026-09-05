<?php

namespace Tests\Feature\UiUx;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RemainingUiCorrectionTest extends TestCase
{
    public function test_chat_and_notifications_use_compact_accessible_workspace_rows(): void
    {
        $chat = $this->viewSource('filament/pages/chat.blade.php');
        $notifications = $this->viewSource('filament/pages/notifications.blade.php');

        $this->assertStringContainsString('data-testid="chat-two-pane-layout"', $chat);
        $this->assertStringContainsString('grid-template-columns: 19rem minmax(0, 1fr)', $chat);
        $this->assertStringContainsString('minmax(0, 1fr)', $chat);
        $this->assertStringContainsString('chat-conversation-row', $chat);
        $this->assertStringContainsString('Select a conversation to start chatting.', $chat);
        $this->assertStringContainsString('sticky bottom-0', $chat);
        $this->assertStringContainsString("'Unread' : 'Read'", $notifications);
        $this->assertStringContainsString('heroicon-o-bell', $notifications);
        $this->assertStringContainsString('notification-row', $notifications);
    }

    public function test_dense_operational_pages_contain_their_own_scrolling_and_bound_long_labels(): void
    {
        $access = $this->viewSource('filament/pages/administration/access-control.blade.php');
        $damaged = $this->viewSource('filament/pages/inventory/damaged-items.blade.php');
        $qc = $this->viewSource('filament/pages/inventory/qc-pending.blade.php');
        $reports = $this->viewSource('filament/pages/purchasing-report.blade.php');

        $this->assertStringContainsString('data-testid="employee-picker"', $access);
        $this->assertStringContainsString('ac-employee-drawer-backdrop', $access);
        $this->assertStringContainsString('ac-module-grid grid min-w-0', $access);
        $this->assertStringContainsString('ac-selected-summary sticky top-20', $access);
        $this->assertStringContainsString('line-clamp-2', $damaged);
        $this->assertStringContainsString('line-clamp-2', $qc);
        $this->assertStringContainsString('max-w-full overflow-x-auto', $reports);
        $this->assertStringContainsString('data-testid="purchasing-report-table"', $reports);
        $this->assertStringContainsString('overflow-x: auto', $reports);
        $this->assertStringContainsString('count($rows[0]) * 125', $reports);
        $this->assertStringContainsString('line-clamp-2', $reports);
    }

    public function test_empty_my_inventory_does_not_render_a_dense_header_table(): void
    {
        $view = $this->viewSource('filament/pages/inventory/my-inventory.blade.php');

        $emptyState = strpos($view, 'No active Product responsibilities.');
        $table = strpos($view, '<table');

        $this->assertNotFalse($emptyState);
        $this->assertNotFalse($table);
        $this->assertLessThan($table, $emptyState);
        $this->assertStringContainsString('@if ($inventoryRows->isEmpty())', $view);
    }

    #[DataProvider('responsiveMetricViews')]
    public function test_summary_metrics_use_responsive_grids(string $viewPath, string $gridClass): void
    {
        $this->assertStringContainsString($gridClass, $this->viewSource($viewPath));
    }

    public static function responsiveMetricViews(): array
    {
        return [
            ['filament/pages/hr/attendance.blade.php', 'sm:grid-cols-2 xl:grid-cols-4'],
            ['filament/pages/hr/leave.blade.php', 'sm:grid-cols-2 lg:grid-cols-5'],
            ['filament/pages/inventory/damaged-items.blade.php', 'md:grid-cols-3'],
            ['filament/pages/inventory/inventory-overview.blade.php', 'inventory-summary-grid'],
            ['filament/pages/inventory/qc-pending.blade.php', 'sm:grid-cols-3'],
        ];
    }

    private function viewSource(string $path): string
    {
        return file_get_contents(resource_path('views/'.$path));
    }
}
