<?php

namespace App\Filament\Pages;

use App\Exceptions\TaskException;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Services\MyWork\MyWorkService;
use App\Services\Tasks\TaskAssigneeService;
use App\Services\Tasks\TaskLinkedRecordService;
use App\Services\Tasks\TaskService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class MyWork extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.my-work';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'My Work';

    protected static ?string $title = 'My Work';

    protected static ?int $navigationSort = -100;

    #[Url]
    public string $viewMode = 'mine';

    #[Url]
    public string $search = '';

    #[Url]
    public string $priority = '';

    #[Url]
    public string $status = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(MyWorkService::class)->canAccess($user);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = $mode;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'priority', 'status');
        $this->resetPage();
    }

    public function assignTask(int $taskId, string|int|null $employeeId): void
    {
        try {
            $task = Task::query()->findOrFail($taskId);
            app(TaskService::class)->assign($task, filled($employeeId) ? (int) $employeeId : null, null, auth()->user());
            Notification::make()->success()->title('Task assignment updated')->send();
        } catch (TaskException|ValidationException|AuthorizationException $e) {
            Notification::make()->danger()->title('Cannot assign Task')->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : ($e->getMessage() ?: 'You are not authorized to assign this Task.'))->send();
        }
    }

    public function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $user = auth()->user();
        $service = app(MyWorkService::class);
        $query = $service->query($user, $this->viewMode);
        if ($this->search !== '') {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$this->search.'%')->orWhere('title', 'like', '%'.$this->search.'%'));
        }
        if ($this->priority !== '') {
            $query->where('priority', $this->priority);
        }
        if ($this->status !== '') {
            $query->where('status', $this->status);
        }
        /** @var LengthAwarePaginator $rows */ $rows = $query->paginate(25);
        $links = app(TaskLinkedRecordService::class);
        $assignees = app(TaskAssigneeService::class);
        $contexts = $rows->getCollection()->mapWithKeys(fn (Task $task): array => [$task->id => $links->context($task, $user)])->all();
        $options = $rows->getCollection()->mapWithKeys(fn (Task $task): array => [$task->id => $assignees->options($task)])->all();

        return ['rows' => $rows, 'summary' => $service->summary($user, $this->viewMode), 'canViewTeam' => $service->canViewTeam($user), 'canViewUnassigned' => $service->canViewUnassigned($user), 'contexts' => $contexts, 'assigneeOptions' => $options, 'service' => $service, 'taskUrl' => fn (Task $task): string => TaskResource::getUrl('view', ['record' => $task])];
    }
}
