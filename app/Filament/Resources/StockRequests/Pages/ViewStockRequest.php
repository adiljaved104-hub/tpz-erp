<?php

namespace App\Filament\Resources\StockRequests\Pages;

use App\Enums\StockRequestSourceStatus;
use App\Filament\Concerns\HandlesActionFeedback;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Models\StockRequestExecution;
use App\Models\StockRequestSourceLine;
use App\Services\Inventory\StockRequestApprovalService;
use App\Services\Inventory\StockRequestExecutionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewStockRequest extends ViewRecord
{
    use HandlesActionFeedback;

    protected static string $resource = StockRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('executeRequest')
                ->label('Execute Request')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Execute approved Stock Request?')
                ->modalDescription(fn (): string => implode("\n", app(StockRequestExecutionService::class)->summary($this->record)))
                ->visible(fn (): bool => app(StockRequestExecutionService::class)->canExecute(auth()->user(), $this->record))
                ->action(fn () => $this->executeRequest()),
            Action::make('approveAndTransfer')
                ->label('Approve & Transfer')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Approve and transfer this Stock Request?')
                ->modalDescription(fn (): string => implode("\n", app(StockRequestExecutionService::class)->summary($this->record)))
                ->schema([
                    Repeater::make('approvals')
                        ->label('Quantities to approve now')
                        ->schema([
                            Select::make('source_line_id')->label('Stock Source')->options(fn (): array => $this->eligibleSourceOptions())->required()->searchable(),
                            TextInput::make('quantity')->label('Quantity')->numeric()->minValue(1)->required(),
                        ])->columns(2)->default(fn (): array => $this->approvalDefaults())->minItems(1),
                ])
                ->visible(fn (): bool => app(StockRequestExecutionService::class)->canApproveAndTransfer(auth()->user(), $this->record))
                ->action(fn (array $data) => $this->approveAndTransfer($data)),
            Action::make('approveSource')
                ->label('Approve Stock Source')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('This records approval only. Stock is not transferred or reserved until a later execution phase.')
                ->schema([
                    Select::make('source_line_id')->label('Stock Source')->options(fn (): array => $this->eligibleSourceOptions())->required()->searchable(),
                    TextInput::make('approved_quantity')->label('Quantity to Approve')->numeric()->minValue(1)->required(),
                    Textarea::make('note')->label('Approval Note')->maxLength(2000),
                ])
                ->visible(fn (): bool => $this->eligibleSourceOptions() !== [])
                ->action(fn (array $data) => $this->recordDecision($data, StockRequestSourceStatus::Approved)),
            Action::make('rejectSource')
                ->label('Reject Stock Source')
                ->color('danger')
                ->requiresConfirmation()
                ->schema([
                    Select::make('source_line_id')->label('Stock Source')->options(fn (): array => $this->eligibleSourceOptions())->required()->searchable(),
                    Textarea::make('note')->label('Rejection Reason')->required()->maxLength(2000),
                ])
                ->visible(fn (): bool => $this->eligibleSourceOptions() !== [])
                ->action(fn (array $data) => $this->recordDecision($data, StockRequestSourceStatus::Rejected)),
        ];
    }

    private function executeRequest(): void
    {
        $result = $this->runWithActionFeedback(
            fn (): StockRequestExecution => app(StockRequestExecutionService::class)->execute($this->record, (string) str()->uuid(), auth()->user()),
            'Stock Request execution was not completed',
        );
        if (! $result instanceof StockRequestExecution) {
            return;
        }
        $this->record->refresh()->load(['items.sourceLines.decidedBy', 'execution.executedBy.employee']);
        Notification::make()->success()->title('Stock request completed successfully.')->send();
    }

    /** @param array<string, mixed> $data */
    private function approveAndTransfer(array $data): void
    {
        $quantities = collect($data['approvals'] ?? [])->mapWithKeys(fn (array $approval): array => [(int) $approval['source_line_id'] => (int) $approval['quantity']])->all();
        $result = $this->runWithActionFeedback(
            fn (): StockRequestExecution => app(StockRequestExecutionService::class)->approveAndTransfer($this->record, (string) str()->uuid(), auth()->user(), $quantities),
            'Stock Request approval and transfer was not completed',
        );
        if (! $result instanceof StockRequestExecution) {
            return;
        }
        $this->record->refresh()->load(['items.sourceLines.decidedBy', 'execution.executedBy.employee', 'execution.lines.item', 'execution.lines.source', 'execution.lines.sourceAccount', 'execution.lines.destinationAccount']);
        Notification::make()->success()->title('Stock request approved and transferred successfully.')->send();
    }

    /** @param array<string, mixed> $data */
    private function recordDecision(array $data, StockRequestSourceStatus $decision): void
    {
        $result = $this->runWithActionFeedback(function () use ($data, $decision): StockRequestSourceLine {
            $line = $this->record->sourceLines()->whereKey($data['source_line_id'])->firstOrFail();

            return app(StockRequestApprovalService::class)->decide(
                $line,
                $decision,
                $data['note'] ?? null,
                (string) str()->uuid(),
                auth()->user(),
                isset($data['approved_quantity']) ? (int) $data['approved_quantity'] : null,
            );
        }, 'Stock approval was not recorded');

        if (! $result instanceof StockRequestSourceLine) {
            return;
        }

        $this->record->refresh()->load(['items.sourceLines.decidedBy']);
        Notification::make()->success()->title(
            $decision === StockRequestSourceStatus::Approved
                ? 'Stock approval recorded successfully.'
                : 'Stock rejection recorded successfully.'
        )->send();
    }

    /** @return array<int, string> */
    private function eligibleSourceOptions(): array
    {
        return app(StockRequestApprovalService::class)
            ->eligiblePendingLines($this->record, auth()->user())
            ->mapWithKeys(fn (StockRequestSourceLine $line): array => [
                $line->id => "{$line->item->sku} — {$line->source_label} — {$line->proposed_quantity}",
            ])->all();
    }

    /** @return array<int, array{source_line_id: int, quantity: int}> */
    private function approvalDefaults(): array
    {
        return app(StockRequestApprovalService::class)->eligiblePendingLines($this->record, auth()->user())
            ->map(fn (StockRequestSourceLine $line): array => ['source_line_id' => $line->id, 'quantity' => 1])->all();
    }
}
