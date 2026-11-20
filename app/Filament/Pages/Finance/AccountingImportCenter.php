<?php

namespace App\Filament\Pages\Finance;

use App\Enums\AccountingPermission;
use App\Jobs\PreviewAccountingImportBatch;
use App\Models\AccountingImportBatch;
use App\Services\Accounting\QuickBooksImportPreviewService;
use App\Services\Authorization\AccountingAuthorization;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;

class AccountingImportCenter extends BaseAccountingPage
{
    use WithFileUploads;

    protected string $view = 'filament.pages.finance.accounting-import-center';

    protected static ?string $slug = 'finance/accounting/import-center';

    protected static ?string $navigationLabel = 'QuickBooks Import Center';

    protected static ?int $navigationSort = 45;

    protected static AccountingPermission $requiredPermission = AccountingPermission::ViewImportCenter;

    public $sourceFile = null;

    public string $sourceCompanyIdentity = '';

    public function uploadAndPreview(): void
    {
        abort_unless(app(AccountingAuthorization::class)->allows($this->user(), AccountingPermission::ManageImportCenter), 403);

        try {
            $this->validate([
                'sourceFile' => ['required', 'file', 'max:102400', 'mimes:xml,qbxml,json,jsonl,ndjson,csv'],
                'sourceCompanyIdentity' => ['required', 'string', 'max:190'],
            ]);

            $batch = app(QuickBooksImportPreviewService::class)->createBatch($this->sourceFile, $this->sourceCompanyIdentity, $this->user());
            PreviewAccountingImportBatch::dispatch($batch->id);
            $this->reset(['sourceFile', 'sourceCompanyIdentity']);
            Notification::make()->success()->title('Private preview queued')->body('The export will be checksum-verified and staged without creating accounting or operational records.')->send();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }

    public function resumePreview(int $batchId): void
    {
        abort_unless(app(AccountingAuthorization::class)->allows($this->user(), AccountingPermission::ManageImportCenter), 403);
        $batch = AccountingImportBatch::query()->findOrFail($batchId);
        PreviewAccountingImportBatch::dispatch($batch->id);
        Notification::make()->success()->title('Preview resume queued')->send();
    }

    protected function getViewData(): array
    {
        return [
            'batches' => AccountingImportBatch::query()
                ->with('creator:id,name')
                ->latest('id')
                ->paginate(20),
            'canManage' => app(AccountingAuthorization::class)->allows($this->user(), AccountingPermission::ManageImportCenter),
        ];
    }
}
