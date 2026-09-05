<?php

namespace App\Filament\Pages;

use App\Enums\ChannelVisibility;
use App\Enums\ChatPermission;
use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\CustomerReturn;
use App\Models\Employee;
use App\Models\Order;
use App\Models\SafetClaim;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\ChatAuthorization;
use App\Services\Chat\ChannelService;
use App\Services\Chat\ChatInteractionService;
use App\Services\Chat\ChatQueryService;
use App\Services\Chat\ChatRecordMentionService;
use App\Services\Chat\ConversationMessageService;
use App\Services\Chat\ConversationPresenter;
use App\Services\Chat\ConversationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class Chat extends Page
{
    protected string $view = 'filament.pages.chat';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|\UnitEnum|null $navigationGroup = 'Work';

    protected static ?string $navigationLabel = 'Chat';

    protected static ?string $title = 'Chat';

    protected static ?int $navigationSort = -90;

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url]
    public ?int $conversation = null;

    #[Url]
    public string $search = '';

    #[Url]
    public string $filter = 'all';

    #[Url]
    public ?int $searchEmployee = null;

    #[Url]
    public ?string $searchFrom = null;

    #[Url]
    public ?string $searchTo = null;

    #[Url]
    public ?string $contextType = null;

    #[Url]
    public ?int $contextId = null;

    public string $body = '';

    public ?int $replyToMessageId = null;

    public ?int $threadMessageId = null;

    public string $threadBody = '';

    /** @var array<int, int|string> */
    public array $mentionEmployeeIds = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ChatAuthorization::class)->allows($user, ChatPermission::View);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }
        $count = app(ChatQueryService::class)->totalUnread(auth()->user());

        return $count === 0 ? null : ($count > 99 ? '99+' : (string) $count);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->contextType !== null && $this->contextId !== null) {
            $model = Relation::getMorphedModel($this->contextType);
            abort_unless(is_string($model) && in_array($this->contextType, ['task', 'order', 'customer_return', 'safet_claim', 'complaint', 'warranty_repair'], true), 404);
            $record = $model::query()->findOrFail($this->contextId);
            $this->conversation = app(ConversationService::class)->context($this->user(), $record, $this->contextTitle($record))->id;
        }
        if ($this->conversation !== null) {
            $this->markSelectedRead();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createChannel')->label('Create Channel')->icon('heroicon-o-plus')
                ->visible(fn (): bool => app(ChatAuthorization::class)->allows($this->user(), ChatPermission::ChannelCreate))
                ->schema([
                    TextInput::make('name')->label('Channel name')->required()->maxLength(80),
                    Select::make('visibility')->options(['public' => 'Public', 'private' => 'Private'])->default('public')->required(),
                    Textarea::make('description')->maxLength(500),
                    Select::make('member_ids')->label('Initial members')->multiple()->searchable()
                        ->options(fn (): array => app(ChatQueryService::class)->directEmployeeOptions($this->user()))
                        ->helperText('Private channels are visible only to members.'),
                ])->action(function (array $data): void {
                    $channel = app(ChannelService::class)->create($this->user(), $data['name'], ChannelVisibility::from($data['visibility']), $data['description'] ?? null, $data['member_ids'] ?? []);
                    $this->conversation = $channel->id;
                    $this->filter = 'channels';
                    Notification::make()->success()->title('Channel created')->send();
                }),
            Action::make('startDirect')->label('Start Direct Chat')->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => app(ChatAuthorization::class)->allows($this->user(), ChatPermission::Direct))
                ->schema([Select::make('employee_id')->label('Employee')->searchable()->required()->options(fn () => app(ChatQueryService::class)->directEmployeeOptions($this->user()))])
                ->action(function (array $data): void {
                    $this->conversation = app(ConversationService::class)->direct($this->user(), Employee::query()->findOrFail($data['employee_id']))->id;
                    $this->filter = 'all';
                }),
            Action::make('teamChat')->label('Open Team Chat')->icon('heroicon-o-user-group')
                ->visible(fn (): bool => app(ChatQueryService::class)->teamOptions($this->user()) !== [])
                ->schema([
                    Select::make('team_id')->label('Team')->options(fn (): array => app(ChatQueryService::class)->teamOptions($this->user()))->searchable()->required(),
                ])
                ->action(function (array $data): void {
                    $team = Team::query()->findOrFail($data['team_id']);
                    $this->conversation = app(ConversationService::class)->team($this->user(), $team)->id;
                    $this->filter = 'all';
                }),
        ];
    }

    public function addChannelMembersAction(): Action
    {
        return Action::make('addChannelMembers')->label('Add Members')->icon('heroicon-o-user-plus')->color('gray')
            ->visible(fn (): bool => $this->selectedConversation()?->type === ConversationType::Channel
                && app(ChatAuthorization::class)->allows($this->user(), ChatPermission::ChannelManage))
            ->schema([Select::make('member_ids')->label('Employees')->multiple()->searchable()->required()
                ->options(fn (): array => app(ChatQueryService::class)->channelMemberOptions($this->user(), $this->selectedConversation()))])
            ->action(function (array $data): void {
                app(ChannelService::class)->addMembers($this->user(), $this->selectedConversationOrFail(), $data['member_ids']);
                Notification::make()->success()->title('Channel members added')->send();
            });
    }

    public function editChannelAction(): Action
    {
        return Action::make('editChannel')->label('Edit Channel')->icon('heroicon-o-pencil-square')->color('gray')
            ->visible(fn (): bool => $this->selectedConversation()?->type === ConversationType::Channel
                && app(ChatAuthorization::class)->allows($this->user(), ChatPermission::ChannelManage))
            ->fillForm(fn (): array => [
                'name' => $this->selectedConversation()?->title,
                'visibility' => $this->selectedConversation()?->visibility?->value,
                'description' => $this->selectedConversation()?->description,
            ])->schema([
                TextInput::make('name')->required()->maxLength(80),
                Select::make('visibility')->options(['public' => 'Public', 'private' => 'Private'])->required(),
                Textarea::make('description')->maxLength(500),
            ])->action(function (array $data): void {
                app(ChannelService::class)->update($this->user(), $this->selectedConversationOrFail(), $data['name'], $data['visibility'], $data['description'] ?? null);
                Notification::make()->success()->title('Channel updated')->send();
            });
    }

    public function removeChannelMemberAction(): Action
    {
        return Action::make('removeChannelMember')->label('Remove Member')->icon('heroicon-o-user-minus')->color('gray')
            ->visible(fn (): bool => $this->selectedConversation()?->type === ConversationType::Channel
                && app(ChatAuthorization::class)->allows($this->user(), ChatPermission::ChannelManage))
            ->schema([Select::make('employee_id')->label('Employee')->required()->searchable()
                ->options(fn (): array => app(ChatQueryService::class)->currentChannelMemberOptions($this->selectedConversationOrFail()))])
            ->action(function (array $data): void {
                app(ChannelService::class)->removeMember($this->user(), $this->selectedConversationOrFail(), Employee::query()->findOrFail($data['employee_id']));
                Notification::make()->success()->title('Channel member removed')->send();
            });
    }

    public function archiveConversationAction(): Action
    {
        return Action::make('archiveConversation')->label('Archive')->icon('heroicon-o-archive-box')->color('gray')->iconButton()->tooltip('Archive conversation')->requiresConfirmation()
            ->visible(fn (): bool => $this->selectedConversation() instanceof Conversation
                && $this->selectedConversation()->status === ConversationStatus::Active
                && ($this->selectedConversation()->type === ConversationType::Channel
                    ? app(ChatAuthorization::class)->allows($this->user(), ChatPermission::ChannelArchive)
                    : app(ChatAuthorization::class)->allows($this->user(), ChatPermission::Manage)))
            ->action(function (): void {
                $selected = $this->selectedConversationOrFail();
                if ($selected->type === ConversationType::Channel) {
                    app(ChannelService::class)->archive($this->user(), $selected);
                } else {
                    app(ConversationService::class)->archive($this->user(), $selected);
                }
                $this->conversation = null;
                Notification::make()->success()->title('Conversation archived')->send();
            });
    }

    public function mentionEmployeesAction(): Action
    {
        return Action::make('mentionEmployees')->label('Mention Employee')->icon('heroicon-o-at-symbol')->color('gray')
            ->visible(fn (): bool => $this->selectedConversation()?->status === ConversationStatus::Active)
            ->fillForm(fn (): array => ['employee_ids' => $this->mentionEmployeeIds])
            ->schema([
                Select::make('employee_ids')->label('Mention Employees')->multiple()->searchable()->options(
                    fn (): array => app(ChatQueryService::class)->mentionOptions($this->user(), $this->selectedConversationOrFail()),
                )->helperText('Only Employees who can already access this conversation are available.'),
            ])->action(function (array $data): void {
                $this->mentionEmployeeIds = array_values(array_unique(array_map('intval', $data['employee_ids'] ?? [])));
            });
    }

    public function mentionRecordAction(): Action
    {
        return Action::make('mentionRecord')->label('Mention Record')->icon('heroicon-o-link')->color('gray')
            ->visible(fn (): bool => $this->selectedConversation()?->status === ConversationStatus::Active)
            ->schema([
                Select::make('record_type')->label('Record Type')->options(fn (): array => app(ChatRecordMentionService::class)->typeOptions())
                    ->required()->live(),
                Select::make('record_id')->label('Business Record')->required()->searchable()
                    ->disabled(fn (Get $get): bool => blank($get('record_type')))
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => blank($get('record_type')) ? [] : app(ChatRecordMentionService::class)->search($this->user(), (string) $get('record_type'), $search))
                    ->getOptionLabelUsing(fn ($value, Get $get): ?string => blank($value) || blank($get('record_type')) ? null : app(ChatRecordMentionService::class)->selectedLabel($this->user(), (string) $get('record_type'), (int) $value))
                    ->helperText('Only records you are authorized to view can be selected.'),
            ])->action(function (array $data): void {
                $this->body = app(ChatRecordMentionService::class)->appendToken(
                    $this->user(), $this->body, (string) $data['record_type'], (int) $data['record_id'],
                );
            });
    }

    public function selectConversation(int $conversationId): void
    {
        $conversation = Conversation::query()->findOrFail($conversationId);
        app(ChatAuthorization::class)->authorizeConversation($this->user(), $conversation);
        $this->conversation = $conversation->id;
        $this->reset('body', 'replyToMessageId', 'threadMessageId', 'threadBody', 'mentionEmployeeIds');
        $this->markSelectedRead();
    }

    public function sendMessage(): void
    {
        try {
            $conversation = $this->selectedConversationOrFail();
            $reply = $this->replyToMessageId === null ? null : ConversationMessage::query()->findOrFail($this->replyToMessageId);
            $message = app(ConversationMessageService::class)->send($this->user(), $conversation, $this->body, $reply, $this->mentionEmployeeIds);
            app(ConversationMessageService::class)->markRead($this->user(), $conversation, $message);
            $this->reset('body', 'replyToMessageId', 'mentionEmployeeIds');
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
        }
    }

    public function replyTo(int $messageId): void
    {
        $conversation = $this->selectedConversationOrFail();
        abort_unless($conversation->messages()->whereKey($messageId)->exists(), 404);
        $this->replyToMessageId = $messageId;
    }

    public function openThread(int $messageId): void
    {
        abort_unless(app(ChatAuthorization::class)->allows($this->user(), ChatPermission::Thread), 403);
        $conversation = $this->selectedConversationOrFail();
        $message = $conversation->messages()->whereKey($messageId)->firstOrFail();
        $this->threadMessageId = $message->reply_to_message_id ?: $message->id;
        $this->threadBody = '';
    }

    public function closeThread(): void
    {
        $this->reset('threadMessageId', 'threadBody');
    }

    public function sendThreadReply(): void
    {
        try {
            $root = ConversationMessage::query()->findOrFail($this->threadMessageId);
            app(ChatInteractionService::class)->reply($this->user(), $root, $this->threadBody);
            $this->threadBody = '';
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
        }
    }

    public function toggleReaction(int $messageId, string $reaction): void
    {
        app(ChatInteractionService::class)->toggleReaction($this->user(), ConversationMessage::query()->findOrFail($messageId), $reaction);
    }

    public function togglePin(int $messageId): void
    {
        app(ChatInteractionService::class)->togglePin($this->user(), ConversationMessage::query()->findOrFail($messageId));
    }

    public function joinChannel(int $channelId): void
    {
        $channel = Conversation::query()->findOrFail($channelId);
        app(ChannelService::class)->join($this->user(), $channel);
        $this->conversation = $channel->id;
        $this->filter = 'channels';
        Notification::make()->success()->title('Joined #'.$channel->slug)->send();
    }

    public function leaveChannel(): void
    {
        $channel = $this->selectedConversationOrFail();
        app(ChannelService::class)->leave($this->user(), $channel);
        $this->conversation = null;
        Notification::make()->success()->title('Channel left')->send();
    }

    public function clearReply(): void
    {
        $this->replyToMessageId = null;
    }

    public function closeConversation(): void
    {
        $this->conversation = null;
        $this->reset('body', 'replyToMessageId', 'threadMessageId', 'threadBody', 'mentionEmployeeIds');
    }

    public function setFilter(string $filter): void
    {
        abort_unless(in_array($filter, ['all', 'channels', 'direct', 'teams', 'contexts', 'archived'], true), 404);
        $this->filter = $filter;
        $this->conversation = null;
    }

    public function refreshConversation(): void
    {
        if ($this->conversation !== null) {
            $this->markSelectedRead();
        }
    }

    public function getViewData(): array
    {
        $user = $this->user();
        $recordMentions = app(ChatRecordMentionService::class);
        $conversations = app(ChatQueryService::class)->inbox($user, $this->search, $this->filter)->limit(50)->get();
        if ($this->filter === 'all') {
            $conversations = collect([
                'Channels' => $conversations->whereIn('type', [ConversationType::Channel, ConversationType::Team]),
                'Direct Messages' => $conversations->where('type', ConversationType::Direct),
                'Work Discussions' => $conversations->where('type', ConversationType::Context),
            ])->flatMap(fn ($items, string $section) => $items->each->setAttribute('sidebar_section', $section))->values();
        }
        $latestMessages = $conversations->pluck('latestMessage')->filter()->values();
        $selected = $this->selectedConversation();
        $messages = collect();
        $reply = null;
        $contextLink = null;
        $messageSegments = [];
        $threadRoot = null;
        $threadMessages = collect();
        $pinnedMessages = collect();
        if ($selected instanceof Conversation) {
            app(ChatAuthorization::class)->authorizeConversation($user, $selected);
            $messages = $selected->messages()->whereNull('reply_to_message_id')
                ->with(['sender:id,name', 'reactions.employee:id,name', 'pin'])->withCount('replies')
                ->latest('id')->limit(100)->get()->reverse()->values();
            $reply = $this->replyToMessageId === null ? null : $messages->firstWhere('id', $this->replyToMessageId);
            $contextLink = app(ConversationPresenter::class)->contextLink($selected->loadMissing('context'), $user);
            $messageSegments = $recordMentions->segmentsForMessages($messages, $user);
            $pinnedMessages = $selected->messages()->whereHas('pin')->with(['sender:id,name', 'pin'])->latest('id')->limit(10)->get();
            if ($this->threadMessageId !== null) {
                $threadRoot = $selected->messages()->with(['sender:id,name', 'reactions.employee:id,name'])->find($this->threadMessageId);
                if ($threadRoot) {
                    $threadMessages = $threadRoot->replies()->with(['sender:id,name', 'reactions.employee:id,name'])->oldest('id')->get();
                    $messageSegments += $recordMentions->segmentsForMessages($threadMessages, $user);
                }
            }
        }

        $searchResults = app(ChatQueryService::class)->searchMessages($user, $this->search, $this->searchEmployee, $this->searchFrom, $this->searchTo)->limit(30)->get();

        return [
            'conversations' => $conversations,
            'inboxPreviews' => $recordMentions->previewsForMessages($latestMessages, $user),
            'selected' => $selected,
            'messages' => $messages,
            'threadRoot' => $threadRoot,
            'threadMessages' => $threadMessages,
            'pinnedMessages' => $pinnedMessages,
            'replyMessage' => $reply,
            'contextLink' => $contextLink,
            'messageSegments' => $messageSegments,
            'selectedMentionNames' => $this->mentionEmployeeIds === [] ? [] : Employee::query()->whereKey($this->mentionEmployeeIds)->orderBy('name')->pluck('name')->all(),
            'presenter' => app(ConversationPresenter::class),
            'publicChannels' => app(ChatQueryService::class)->publicChannels($user, $this->search)->limit(20)->get(),
            'canReact' => app(ChatAuthorization::class)->allows($user, ChatPermission::React),
            'canPin' => app(ChatAuthorization::class)->allows($user, ChatPermission::Pin),
            'canThread' => app(ChatAuthorization::class)->allows($user, ChatPermission::Thread),
            'searchResults' => $searchResults,
            'searchPreviews' => $recordMentions->previewsForMessages($searchResults, $user),
            'searchEmployeeOptions' => app(ChatQueryService::class)->channelMemberOptions($user),
        ];
    }

    private function markSelectedRead(): void
    {
        $conversation = $this->selectedConversationOrFail();
        $latest = $conversation->messages()->latest('id')->first();
        if ($latest instanceof ConversationMessage) {
            app(ConversationMessageService::class)->markRead($this->user(), $conversation, $latest);
        }
    }

    private function selectedConversation(): ?Conversation
    {
        return $this->conversation === null ? null : Conversation::query()->with(['team', 'participants.employee', 'context'])->find($this->conversation);
    }

    private function selectedConversationOrFail(): Conversation
    {
        $conversation = $this->selectedConversation();
        abort_unless($conversation instanceof Conversation, 404);
        app(ChatAuthorization::class)->authorizeConversation($this->user(), $conversation);

        return $conversation;
    }

    private function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }

    private function contextTitle(object $record): string
    {
        $label = match (true) {
            $record instanceof Task => 'Task',
            $record instanceof Order => 'Order',
            $record instanceof CustomerReturn => 'Return',
            $record instanceof SafetClaim => 'Claim',
            $record instanceof Complaint => 'Complaint',
            $record instanceof WarrantyRepair && $record->isInternalCompanyOwnedRepair() => 'Internal Repair',
            $record instanceof WarrantyRepair => 'Warranty',
            default => 'Work Discussion',
        };

        return $label.' '.($record->reference ?? '#'.$record->getKey());
    }
}
