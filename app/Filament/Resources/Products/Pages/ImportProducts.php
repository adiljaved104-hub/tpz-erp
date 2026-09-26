<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\EmployeeRole;
use App\Enums\ProductPermission;
use App\Filament\Resources\Products\ProductResource;
use App\Models\User;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Products\ProductImportService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ImportProducts extends Page
{
    use WithFileUploads;

    protected static string $resource = ProductResource::class;

    protected string $view = 'filament.resources.products.pages.import-products';

    public mixed $file = null;

    public string $mode = ProductImportService::MODE_CREATE;

    public string $duplicateOverrideReason = '';

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    public bool $importing = false;

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
            && app(ProductAuthorization::class)->allows($user, ProductPermission::Create);
    }

    public function previewImport(ProductImportService $imports): void
    {
        $this->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'], 'mode' => ['required', 'in:create,upsert']]);
        $this->preview = $imports->preview($this->file->getRealPath(), $this->mode, auth()->user());

        Notification::make()->success()->title('Product file validated')->body('Review every row before importing. No Products have been changed.')->send();
    }

    public function import(ProductImportService $imports): void
    {
        if ($this->importing) {
            return;
        }
        $this->importing = true;
        try {
            $this->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'], 'mode' => ['required', 'in:create,upsert']]);
            $freshPreview = $imports->preview($this->file->getRealPath(), $this->mode, auth()->user());
            $result = $imports->import($freshPreview, $this->mode, auth()->user(), $this->duplicateOverrideReason);
            $this->preview = $freshPreview;
            Notification::make()->success()->title('Products imported successfully')->body("{$result['created']} created and {$result['updated']} updated.")->send();
            $this->reset(['file', 'duplicateOverrideReason', 'preview']);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }
            Notification::make()->danger()->title('Products were not imported')->body(collect($exception->errors())->flatten()->implode(' '))->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('Products were not imported')->body('No Products were changed. Review the file and try again.')->send();
        } finally {
            $this->importing = false;
        }
    }

    public function downloadTemplate(ProductImportService $imports): StreamedResponse
    {
        return response()->streamDownload(function () use ($imports): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, $imports->templateHeaders());
            fputcsv($stream, ['', 'HP', 'Laptop', '15-fd0132wm', 'Core i5', 'i5-1334U', '13th Gen', '8GB', '512GB SSD', '15.6"', 'Integrated', 'Silver', 'No', 'No', 'New', '12', '1999.00', '', '']);
            fclose($stream);
        }, 'tpz-product-import-template.csv', ['Content-Type' => 'text/csv']);
    }
}
