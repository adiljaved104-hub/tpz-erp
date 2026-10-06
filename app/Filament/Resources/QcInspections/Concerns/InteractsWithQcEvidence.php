<?php

namespace App\Filament\Resources\QcInspections\Concerns;

use App\Services\Qc\QcEvidenceService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;

trait InteractsWithQcEvidence
{
    use WithFileUploads;

    public array $evidenceUploads = [];

    public bool $additionalCustomerVisible = false;

    public function uploadEvidenceKind(string $kind): bool
    {
        $result = $this->runWithActionFeedback(function () use ($kind) {
            try {
                return app(QcEvidenceService::class)->uploadMany($this->record, $this->evidenceUploads[$kind] ?? [], $kind, $this->additionalCustomerVisible, auth()->user());
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['evidenceUploads.'.$kind => collect($exception->errors())->flatten()->all()]);
            }
        }, 'Evidence was not uploaded');
        if (! $result) {
            return false;
        }
        unset($this->evidenceUploads[$kind]);
        $this->resetValidation('evidenceUploads.'.$kind);
        $this->resetValidation('data.evidence.'.$kind);
        $this->record->refresh();
        Notification::make()->success()->title(count($result).' '.QcEvidenceService::KINDS[$kind].' photo(s) uploaded and watermarked.')->send();

        return true;
    }

    protected function batchEvidenceAction(): Action
    {
        return Action::make('uploadEvidence')->label('Upload Multiple Evidence')->schema(function (): array {
            return collect(app(QcEvidenceService::class)->requiredKinds($this->record, $this->data['final_configuration'] ?? $this->record->final_configuration))
                ->map(fn ($kind) => FileUpload::make($kind)->label(QcEvidenceService::KINDS[$kind])->image()->multiple()->maxFiles(10)->maxSize(8192)->storeFiles(false))->all();
        })->action(function (array $data): void {
            $categories = array_filter($data, fn ($files) => is_array($files) && count($files) > 0);
            $result = $this->runWithActionFeedback(fn () => app(QcEvidenceService::class)->uploadBatch($this->record, $categories, true, auth()->user()), 'Evidence was not uploaded');
            if ($result) {
                $this->record->refresh();
                Notification::make()->success()->title(count($result).' evidence photos uploaded and watermarked.')->send();
            }
        });
    }
}
