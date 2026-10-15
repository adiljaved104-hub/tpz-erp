<?php

namespace App\Filament\Resources\QcInspections\Pages;

use App\Filament\Resources\QcInspections\Concerns\QcFormFeedback;
use App\Filament\Resources\QcInspections\QcInspectionResource;
use App\Services\Qc\QcInspectionService;
use App\Services\Qc\QcRecordedRenewedStockIntakeService;
use App\Services\Qc\QcTemplateResolver;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class CreateQcInspection extends CreateRecord
{
    use QcFormFeedback;

    protected static string $resource = QcInspectionResource::class;

    #[Locked]
    public string $startKey;

    #[Locked]
    public ?int $recordedStockIntakeId = null;

    public function mount(): void
    {
        $this->startKey = (string) str()->uuid();
        $intakeId = request()->integer('recorded_stock_intake_id');
        if ($intakeId > 0) {
            $intake = app(QcRecordedRenewedStockIntakeService::class)->findForStart($intakeId, auth()->user());
            $this->recordedStockIntakeId = $intake->id;
        }
        parent::mount();
        if ($intakeId > 0) {
            $intake ??= app(QcRecordedRenewedStockIntakeService::class)->findForStart($intakeId, auth()->user());
            $this->data = array_replace($this->data ?? [], [
                'product_id' => $intake->product_id,
                'warehouse_id' => $intake->warehouse_id,
                'features' => app(QcTemplateResolver::class)->resolve($intake->product)['default_features'],
            ]);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->qcFormOperation(fn () => app(QcInspectionService::class)->start(array_replace($data, [
            'idempotency_key' => $this->startKey,
            'recorded_stock_intake_id' => $this->recordedStockIntakeId,
        ]), auth()->user()));
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
