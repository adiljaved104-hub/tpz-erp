<?php

namespace App\Filament\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

trait HandlesActionFeedback
{
    protected function runWithActionFeedback(Closure $action, string $failureTitle): mixed
    {
        try {
            return $action();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title($failureTitle)
                ->body(collect($exception->errors())->flatten()->first() ?? 'Review the highlighted fields and try again.')
                ->send();

            throw $exception;
        } catch (AuthorizationException) {
            $this->sendAccessDeniedNotification();

            return null;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }

            $this->sendAccessDeniedNotification();

            return null;
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title($failureTitle)
                ->body('The action was not completed. Your entered information has been kept so you can try again.')
                ->send();

            return null;
        }
    }

    private function sendAccessDeniedNotification(): void
    {
        Notification::make()
            ->danger()
            ->title('Access denied')
            ->body('You do not have permission to complete this action.')
            ->send();
    }
}
