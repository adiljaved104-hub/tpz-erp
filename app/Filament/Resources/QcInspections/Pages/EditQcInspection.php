<?php

namespace App\Filament\Resources\QcInspections\Pages;

use App\Enums\QcPermission;
use App\Filament\Concerns\HandlesActionFeedback;
use App\Filament\Resources\QcInspections\Concerns\InteractsWithQcEvidence;
use App\Filament\Resources\QcInspections\Concerns\QcFormFeedback;
use App\Filament\Resources\QcInspections\QcInspectionResource;
use App\Services\Authorization\QcAuthorization;
use App\Services\Qc\QcInspectionService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditQcInspection extends EditRecord
{
    use HandlesActionFeedback;
    use InteractsWithQcEvidence;
    use QcFormFeedback;

    protected static string $resource = QcInspectionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['checks'] = $this->record->checks()->get()->where('applicable', true)->mapWithKeys(fn ($check) => [$check->check_key => ['result' => $check->result, 'measurement' => $check->measurement, 'notes' => $check->notes]])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->qcFormOperation(fn () => app(QcInspectionService::class)->update($record, $data, auth()->user()));
    }

    public function getSubheading(): ?string
    {
        $checks = $this->record->checks()->where('applicable', true)->get();

        return $checks->whereNotNull('result')->count().' / '.$checks->count().' applicable checks completed · '.$this->record->device->reference;
    }

    public function passGroup(string $group): void
    {
        $result = $this->runWithActionFeedback(function () use ($group) {
            $service = app(QcInspectionService::class);
            $service->update($this->record, $this->data, auth()->user());
            $service->passGroup($this->record, $group, auth()->user());

            return true;
        }, 'QC group was not saved');
        if ($result) {
            $this->refreshFormData(['checks']);
            $this->form->fill($this->mutateFormDataBeforeFill($this->record->fresh()->toArray()));
            Notification::make()->success()->title('Group passed. Required measurements still need entry.')->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), $this->batchEvidenceAction(), Action::make('completeQc')->label('Complete QC')->color('success')->visible(fn () => app(QcAuthorization::class)->allows(auth()->user(), QcPermission::Complete, $this->record))
            ->action(function (): void {
                $result = $this->runWithActionFeedback(fn () => DB::transaction(function () {
                    $service = app(QcInspectionService::class);
                    $this->qcFormOperation(fn () => $service->update($this->record, $this->data, auth()->user()));

                    return $this->qcFormOperation(fn () => $service->complete($this->record, auth()->user()));
                }), 'QC was not completed');
                if ($result) {
                    Notification::make()->success()->title('QC certified successfully. Certificate, passport and label are ready.')->send();
                    $this->redirect(static::$resource::getUrl('view', ['record' => $this->record]));
                }
            })];
    }
}
