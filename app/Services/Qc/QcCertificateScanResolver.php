<?php

namespace App\Services\Qc;

use App\Models\QcCertificate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class QcCertificateScanResolver
{
    public function applyCode(Builder $query, string $value, string $field = 'code'): Builder
    {
        Validator::make([$field => $value], [$field => ['required', 'string', 'max:2048']])->validate();
        $value = trim($value);
        $token = null;

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $expected = parse_url(app(QcDocumentService::class)->url(new QcCertificate(['public_token' => str_repeat('0', 64)])));
            $url = parse_url($value);
            $path = preg_quote($expected['path'], '~');
            $path = str_replace(str_repeat('0', 64), '([a-f0-9]{64})', $path);
            if (($url['scheme'] ?? '') !== ($expected['scheme'] ?? '') || ($url['host'] ?? '') !== ($expected['host'] ?? '')
                || ($url['port'] ?? null) !== ($expected['port'] ?? null) || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment']) || ! preg_match('~\A'.$path.'\z~', $url['path'] ?? '', $match)) {
                throw ValidationException::withMessages([$field => 'Scan a TPZ QC verification QR or enter a device Serial / QC ID. External URLs are not accepted.']);
            }
            $token = $match[1];
        } elseif (! preg_match('/\A[A-Za-z0-9._ -]{3,100}\z/', $value)) {
            throw ValidationException::withMessages([$field => 'Enter a valid device Serial / IMEI or QC ID.']);
        }

        if ($token !== null) {
            return $query->where('public_token', $token);
        }

        return $query->whereHas('device', fn (Builder $device): Builder => $device->where(fn (Builder $identity): Builder => $identity
            ->where('serial_key', QcInspectionService::serialKey($value))
            ->orWhere('reference', strtoupper($value))));
    }

    public function resolve(string $value, string $field = 'code'): QcCertificate
    {
        $query = QcCertificate::query()->with(['device', 'inspection']);
        $this->applyCode($query, $value, $field);

        return $query->first() ?? throw ValidationException::withMessages([$field => 'No eligible current QC device matches the scanned label.']);
    }
}
