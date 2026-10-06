<?php

namespace App\Filament\Resources\QcInspections\Concerns;

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
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['data.grade' => 'QC was not saved. Your entries have been kept; please try again.']);
        }
    }
}
