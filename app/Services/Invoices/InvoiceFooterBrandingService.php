<?php

namespace App\Services\Invoices;

use App\Enums\EmployeeRole;
use App\Enums\InvoicePermission;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceFooterBrandingService
{
    public const BRANDS = ['iso' => 'ISO 9001:2015', 'dell' => 'Dell', 'lenovo' => 'Lenovo', 'hp' => 'HP', 'asus' => 'ASUS', 'acer' => 'Acer', 'msi' => 'MSI'];

    public function canManage(?User $actor): bool
    {
        return $actor !== null
            && in_array($actor->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
            && app(InvoiceAuthorization::class)->allows($actor, InvoicePermission::SettingsManage);
    }

    /**
     * Presentation-only review label. Never used to authorize approval or publication.
     *
     * An edited Livewire draft must not retain an "Approved" badge merely
     * because it still contains old server-owned approval metadata.
     *
     * @param  array<string, mixed>  $entry
     */
    public function reviewStatus(array $entry, bool $pendingUpload = false): string
    {
        $hasAsset = $pendingUpload || is_array($entry['asset'] ?? null);
        $hasCaption = filled($entry['caption'] ?? null);
        $hasEvidence = filled($entry['evidence'] ?? null);

        $content = $entry;
        unset($content['approve'], $content['revoke_approval']);
        $approved = $pendingUpload === false
            && filled($entry['approved_by_user_id'] ?? null)
            && filled($entry['approved_at'] ?? null)
            && hash_equals((string) ($entry['approval_fingerprint'] ?? ''), $this->fingerprint($content));

        if ($approved) {
            return empty($entry['enabled']) ? 'Approved - disabled' : 'Approved & enabled';
        }

        if ($hasAsset === false && $hasCaption === false && $hasEvidence === false) {
            return 'Not configured';
        }

        if ($hasAsset === false || $hasCaption === false || $hasEvidence === false) {
            return 'Draft - incomplete';
        }

        return 'Pending Owner approval';
    }

    public function defaults(?array $saved = null): array
    {
        $entries = [];
        foreach (self::BRANDS as $key => $label) {
            $entries[$key] = array_merge([
                'enabled' => false, 'caption' => '', 'relationship' => $key === 'iso' ? 'certification' : 'brand',
                'order' => count($entries) + 1, 'evidence' => '', 'asset' => null,
                'approved_by_user_id' => null, 'approved_at' => null, 'approval_fingerprint' => null,
            ], $saved['entries'][$key] ?? [], ['approve' => false, 'revoke_approval' => false]);
        }

        return ['entries' => $entries, 'statement' => array_merge([
            'enabled' => false, 'en' => '', 'ar' => '', 'evidence' => '',
            'approved_by_user_id' => null, 'approved_at' => null, 'approval_fingerprint' => null,
        ], $saved['statement'] ?? [], ['approve' => false, 'revoke_approval' => false])];
    }

    /** Prepare server-owned approval metadata. Uploaded bytes are stored only after all validations pass. */
    public function prepare(array $input, array $uploads, ?array $previous, User $actor): array
    {
        abort_unless($this->canManage($actor), 403);
        $rules = [
            'entries' => ['required', 'array:'.implode(',', array_keys(self::BRANDS))],
            'statement' => ['required', 'array'],
            'statement.enabled' => ['required', 'boolean'],
            'statement.en' => ['nullable', 'string', 'max:500'], 'statement.ar' => ['nullable', 'string', 'max:500'],
            'statement.evidence' => ['nullable', 'string', 'max:500'],
            'statement.approve' => ['sometimes', 'boolean'], 'statement.revoke_approval' => ['sometimes', 'boolean'],
        ];
        foreach (self::BRANDS as $key => $label) {
            $rules["entries.$key"] = ['required', 'array'];
            $rules["entries.$key.enabled"] = ['required', 'boolean'];
            $rules["entries.$key.caption"] = ['nullable', 'string', 'max:80'];
            $rules["entries.$key.relationship"] = ['required', Rule::in($key === 'iso' ? ['certification'] : ['brand', 'retailer', 'authorized_reseller'])];
            $rules["entries.$key.order"] = ['required', 'integer', 'min:1', 'max:7'];
            $rules["entries.$key.evidence"] = ['nullable', 'string', 'max:500'];
            $rules["entries.$key.approve"] = ['sometimes', 'boolean'];
            $rules["entries.$key.revoke_approval"] = ['sometimes', 'boolean'];
        }
        validator($input, $rules)->validate();
        validator(['uploads' => $uploads], [
            'uploads' => ['array:'.implode(',', array_keys(self::BRANDS))],
            'uploads.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2400,max_height=2400'],
        ])->validate();
        $result = ['version' => 1, 'entries' => []];
        $pending = [];
        foreach (self::BRANDS as $key => $label) {
            $data = $input['entries'][$key];
            $asset = $previous['entries'][$key]['asset'] ?? null;
            if (($upload = $uploads[$key] ?? null) instanceof UploadedFile) {
                $asset = [
                    'path' => 'invoice-footer/'.Str::uuid().'.'.$upload->guessExtension(),
                    'sha256' => hash_file('sha256', $upload->getRealPath()), 'mime' => $upload->getMimeType(),
                ];
                $pending[] = [$upload, $asset['path']];
            }
            $entry = [
                'enabled' => (bool) $data['enabled'], 'caption' => trim((string) ($data['caption'] ?? '')),
                'relationship' => $data['relationship'], 'order' => (int) $data['order'],
                'evidence' => trim((string) ($data['evidence'] ?? '')), 'asset' => $asset,
            ];
            $entry = $this->approve($entry, $data, $previous['entries'][$key] ?? [], $actor, "entries.$key");
            if ($entry['enabled'] && ($entry['caption'] === '' || $asset === null
                || (! isset($uploads[$key]) && $this->image($asset) === null))) {
                throw ValidationException::withMessages(["entries.$key.enabled" => 'An approved local logo and caption are required.']);
            }
            $result['entries'][$key] = $entry;
        }
        $data = $input['statement'];
        $statement = [
            'enabled' => (bool) $data['enabled'], 'en' => trim((string) ($data['en'] ?? '')),
            'ar' => trim((string) ($data['ar'] ?? '')), 'evidence' => trim((string) ($data['evidence'] ?? '')),
        ];
        $result['statement'] = $this->approve($statement, $data, $previous['statement'] ?? [], $actor, 'statement');
        if ($statement['enabled'] && (! $result['entries']['iso']['enabled'] || $statement['en'] === '' || $statement['ar'] === '')) {
            throw ValidationException::withMessages(['statement.enabled' => 'Enable the approved ISO entry and provide both English and Arabic statements.']);
        }
        foreach ($pending as [$upload, $path]) {
            if (config('filesystems.disks.local.driver') !== 'local'
                || $upload->storeAs('invoice-footer', basename($path), ['disk' => 'local', 'visibility' => 'private']) !== $path) {
                throw ValidationException::withMessages(['uploads' => 'The private footer asset could not be stored. Please try again.']);
            }
        }

        return $result;
    }

    private function approve(array $value, array $input, array $previous, User $actor, string $field): array
    {
        $fingerprint = $this->fingerprint($value);
        $approved = ($previous['approval_fingerprint'] ?? null) === $fingerprint
            && filled($previous['approved_by_user_id'] ?? null) && filled($previous['approved_at'] ?? null);
        if (($input['approve'] ?? false) || ($input['revoke_approval'] ?? false)) {
            abort_unless($actor->employee?->role === EmployeeRole::Owner, 403);
        }
        if ($input['revoke_approval'] ?? false) {
            $approved = false;
        } elseif ($input['approve'] ?? false) {
            if ($value['evidence'] === '' || (array_key_exists('asset', $value) && $value['asset'] === null)) {
                throw ValidationException::withMessages(["$field.evidence" => 'Record the verified proof/artwork-rights reference before Owner approval.']);
            }
            $approved = true;
            $previous = ['approved_by_user_id' => $actor->id, 'approved_at' => now()->utc()->toIso8601String()];
        }
        if ($value['enabled'] && ! $approved) {
            throw ValidationException::withMessages(["$field.enabled" => 'Owner approval is required for this artwork and claim. Disable it while awaiting approval.']);
        }

        return $value + [
            'approved_by_user_id' => $approved ? $previous['approved_by_user_id'] : null,
            'approved_at' => $approved ? $previous['approved_at'] : null,
            'approval_fingerprint' => $approved ? $fingerprint : null,
        ];
    }

    private function fingerprint(array $value): string
    {
        // Enabled state and ordering can change without changing the approved claim.
        unset($value['enabled'], $value['order'], $value['approved_by_user_id'], $value['approved_at'], $value['approval_fingerprint']);
        ksort($value);
        if (is_array($value['asset'] ?? null)) {
            ksort($value['asset']);
        }

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function approved(array $value): bool
    {
        return ($value['enabled'] ?? false) && filled($value['approved_by_user_id'] ?? null)
            && filled($value['approved_at'] ?? null)
            && hash_equals($this->fingerprint($value), (string) ($value['approval_fingerprint'] ?? ''));
    }

    /** Store in the existing immutable seller snapshot; never fall back to current settings on download. */
    public function snapshot(?array $configuration): array
    {
        return $configuration ?? [];
    }

    public function document(?array $snapshot): array
    {
        if (($snapshot['version'] ?? null) !== 1) {
            return ['entries' => [], 'statement_en' => '', 'statement_ar' => ''];
        }
        $entries = [];
        foreach (self::BRANDS as $key => $label) {
            $entry = $snapshot['entries'][$key] ?? [];
            if ($this->approved($entry) && ($image = $this->image($entry['asset'] ?? null)) !== null) {
                $entries[] = ['key' => $key, 'label' => $label, 'caption' => $entry['caption'], 'order' => $entry['order'], 'image' => $image];
            }
        }
        usort($entries, fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $statement = $snapshot['statement'] ?? [];
        $showStatement = in_array('iso', array_column($entries, 'key'), true) && $this->approved($statement);

        return ['entries' => $entries, 'statement_en' => $showStatement ? $statement['en'] : '', 'statement_ar' => $showStatement ? $statement['ar'] : ''];
    }

    /** Keep identifiers such as ISO 9001:2015 readable after visual Arabic shaping. */
    public function preserveLatinRuns(string $visualArabic): string
    {
        return preg_replace_callback('/[A-Za-z0-9@+._\/:\-]+(?:[ \t]+[A-Za-z0-9@+._\/:\-]+)*/u',
            fn (array $match): string => implode('', array_reverse(preg_split('/([ \t]+)/', $match[0], -1, PREG_SPLIT_DELIM_CAPTURE))), $visualArabic);
    }

    /** Reject remote URLs, traversal, symlink escapes, oversized/changed files and non-raster content. */
    public function image(?array $asset): ?string
    {
        $path = $asset['path'] ?? '';
        if (config('filesystems.disks.local.driver') !== 'local' || ! is_string($path)
            || preg_match('~\Ainvoice-footer/[a-f0-9-]{36}\.(png|jpe?g|webp)\z~', $path) !== 1) {
            return null;
        }
        $disk = Storage::disk('local');
        $base = realpath($disk->path(''));
        $root = realpath($disk->path('invoice-footer'));
        $file = realpath($disk->path($path));
        if ($base === false || $root === false || $file === false || ! str_starts_with($root, $base.DIRECTORY_SEPARATOR)
            || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)
            || ! is_file($file) || filesize($file) > 2097152) {
            return null;
        }
        $size = @getimagesize($file);
        $mime = $size['mime'] ?? null;
        if ($size === false || $size[0] > 2400 || $size[1] > 2400
            || ! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)
            || $mime !== ($asset['mime'] ?? null)
            || ! hash_equals((string) ($asset['sha256'] ?? ''), hash_file('sha256', $file))) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($file));
    }
}
