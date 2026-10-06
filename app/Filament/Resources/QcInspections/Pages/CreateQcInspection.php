<?php

namespace App\Filament\Resources\QcInspections\Pages;

use App\Filament\Resources\QcInspections\Concerns\QcFormFeedback;
use App\Filament\Resources\QcInspections\QcInspectionResource;
use App\Services\Qc\QcInspectionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class CreateQcInspection extends CreateRecord
{
    use QcFormFeedback;

    protected static string $resource = QcInspectionResource::class;

    #[Locked]
    public string $startKey;

    public function mount(): void
    {
        $this->startKey = (string) str()->uuid();
        parent::mount();
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->qcFormOperation(fn () => app(QcInspectionService::class)->start(array_replace($data, ['idempotency_key' => $this->startKey]), auth()->user()));
    }

    protected function getRedirectUrl(): string
    {
        return static::$resource::getUrl('edit', ['record' => $this->record]);
    }

    protected function afterCreate(): void
    {
        $this->startKey = (string) str()->uuid();
    }
}
