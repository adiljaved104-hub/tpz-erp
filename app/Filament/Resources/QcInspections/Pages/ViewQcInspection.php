<?php

namespace App\Filament\Resources\QcInspections\Pages;

use App\Enums\QcInspectionStatus;
use App\Enums\QcPermission;
use App\Filament\Concerns\HandlesActionFeedback;
use App\Filament\Resources\QcInspections\QcInspectionResource;
use App\Services\Authorization\QcAuthorization;
use App\Services\Qc\QcEvidenceService;
use App\Services\Qc\QcInspectionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewQcInspection extends ViewRecord
{
    use HandlesActionFeedback;

    protected static string $resource = QcInspectionResource::class;

    protected function getHeaderActions(): array
    {
        $allowed = fn (QcPermission $permission): bool => app(QcAuthorization::class)->allows(auth()->user(), $permission, $this->record);
        $completed = $this->record->status === QcInspectionStatus::Completed;

        return [EditAction::make()->label('Inspect / Continue QC'),
            Action::make('completeQc')->label('Complete QC')->color('success')->visible(fn () => ! $completed && $allowed(QcPermission::Complete))->action(function (): void {
                $certificate = $this->runWithActionFeedback(fn () => app(QcInspectionService::class)->complete($this->record, auth()->user()), 'QC was not completed');
                if ($certificate) {
                    $this->record->refresh();
                    Notification::make()->success()->title('QC certified. Certificate and labels are ready.')->send();
                }
            }),
            Action::make('uploadEvidence')->label('Add Evidence')->visible(fn () => ! $completed && $allowed(QcPermission::Update))->schema([
                Select::make('kind')->options(QcEvidenceService::KINDS)->required(), FileUpload::make('file')->image()->storeFiles(false)->required()->maxSize(8192), Toggle::make('customer_visible')->label('Customer Visible')->default(true),
            ])->action(function (array $data): void {
                $result = $this->runWithActionFeedback(fn () => app(QcEvidenceService::class)->upload($this->record, $data['file'], $data['kind'], (bool) $data['customer_visible'], auth()->user()), 'Evidence was not uploaded');
                if ($result) {
                    $this->record->refresh();
                    Notification::make()->success()->title('Evidence uploaded and watermarked.')->send();
                }
            }),
            Action::make('certificate')->label('Download Certificate')->visible(fn () => $completed && $allowed(QcPermission::PrintCertificate))->url(fn () => route('qc.certificate', $this->record))->openUrlInNewTab(),
            Action::make('label')->label('Print Label')->visible(fn () => $completed && $this->record->certificate->isCurrent() && $allowed(QcPermission::PrintLabel))->url(fn () => route('qc.labels', ['ids' => [$this->record->id]]))->openUrlInNewTab(),
            Action::make('reQc')->label('Re-QC / New Version')->visible(fn () => $completed && $this->record->certificate->isCurrent() && $allowed(QcPermission::Reopen))->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->action(function (array $data): void {
                    $new = $this->runWithActionFeedback(fn () => app(QcInspectionService::class)->reopen($this->record, $data['reason'], auth()->user()), 'Re-QC was not started');
                    if ($new) {
                        $this->redirect(static::$resource::getUrl('edit', ['record' => $new]));
                    }
                }),
        ];
    }
}
