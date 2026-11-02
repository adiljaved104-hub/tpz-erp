<?php

namespace App\Console\Commands;

use App\Jobs\LabelCustomerReturnPhoto;
use App\Models\CustomerReturnEvidence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class QueueMissingReturnPhotoLabels extends Command
{
    protected $signature = 'returns:queue-photo-labels {returnId : Exact Customer Return ID} {--evidence= : Comma-separated evidence IDs} {--execute : Queue only after a separate review}';

    protected $description = 'Inspect or queue missing Customer Return photo claim copies without altering originals';

    public function handle(): int
    {
        $returnId = filter_var($this->argument('returnId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $raw = (string) $this->option('evidence');
        if (! $returnId || ! preg_match('/^[1-9]\d*(?:,[1-9]\d*)*$/', $raw)) {
            $this->error('Provide an exact return ID and comma-separated photo evidence IDs.');

            return self::FAILURE;
        }

        $ids = array_map('intval', explode(',', $raw));
        if (count($ids) !== count(array_unique($ids)) || count($ids) > 25) {
            $this->error('Use distinct evidence IDs (maximum 25).');

            return self::FAILURE;
        }

        $evidence = CustomerReturnEvidence::query()->with('label')->whereKey($ids)
            ->where('media_type', 'photo')
            ->whereHas('finding.item', fn ($query) => $query->where('customer_return_id', $returnId))
            ->get()->keyBy('id');
        if ($evidence->count() !== count($ids)) {
            $this->error('One or more IDs are missing, are not photos, or belong to a different return. Nothing was queued.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        foreach ($ids as $id) {
            $photo = $evidence[$id];
            if ($photo->captured_at_device === null || $photo->label !== null) {
                $this->error("Evidence {$id} has no device time or already has an immutable label record. Nothing was queued.");

                return self::FAILURE;
            }
            $path = $disk->path($photo->original_path);
            $checksum = is_file($path) && is_readable($path) ? hash_file('sha256', $path) : false;
            if (! is_string($checksum) || ! hash_equals($photo->sha256, $checksum)) {
                $this->error("Evidence {$id} original is missing, unreadable, or has a checksum mismatch. Nothing was queued.");

                return self::FAILURE;
            }
        }

        if (! $this->option('execute')) {
            $this->info('PREFLIGHT=PASS; DRY_RUN=YES; EVIDENCE_IDS='.implode(',', $ids).'; QUEUED=0');

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            LabelCustomerReturnPhoto::dispatch($id)->onConnection('return_evidence_labels');
        }
        $this->info('PREFLIGHT=PASS; EVIDENCE_IDS='.implode(',', $ids).'; QUEUED='.count($ids));

        return self::SUCCESS;
    }
}
