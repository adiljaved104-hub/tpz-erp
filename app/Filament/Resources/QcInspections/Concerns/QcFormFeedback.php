<?php

namespace App\Filament\Resources\QcInspections\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Throwable;

trait QcFormFeedback
{
    protected function qcFormOperation(callable $operation)
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            $missing = collect($exception->errors())->filter(fn ($messages, $key) => str_starts_with($key, 'evidence.'));
            if ($missing->isNotEmpty()) {
                $this->dispatch('qc-evidence-missing');
                Notification::make()->danger()->title('QC cannot be completed. Missing evidence:')
                    ->body($missing->flatten()->implode("\n"))->send();
            }
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['data.grade' => 'QC was not saved. Your entries have been kept; please try again.']);
        }
    }
}
