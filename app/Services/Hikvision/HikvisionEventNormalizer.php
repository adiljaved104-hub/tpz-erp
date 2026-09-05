<?php

namespace App\Services\Hikvision;

use App\DTOs\Attendance\AttendanceImportEvent;
use App\DTOs\Hikvision\HikvisionEventClassification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

class HikvisionEventNormalizer
{
    /** @param array<string, mixed> $event */
    public function normalize(array $event, string $source, string $deviceIdentifier): ?AttendanceImportEvent
    {
        return $this->classify($event, $source, $deviceIdentifier)->event;
    }

    /** @param array<string, mixed> $event */
    public function classify(array $event, string $source, string $deviceIdentifier): HikvisionEventClassification
    {
        $external = trim((string) (data_get($event, 'employeeNoString') ?? data_get($event, 'employeeNo')));
        if ($external === '') {
            return new HikvisionEventClassification(null, 'no_employee_identifier');
        }

        $time = data_get($event, 'time');
        if (! is_string($time) || trim($time) === '') {
            return new HikvisionEventClassification(null, 'invalid_timestamp');
        }

        try {
            $punchedAt = CarbonImmutable::parse($time);
        } catch (Throwable) {
            return new HikvisionEventClassification(null, 'invalid_timestamp');
        }

        $rawMajor = data_get($event, 'major');
        $rawMinor = data_get($event, 'minor');
        if (! is_numeric($rawMajor) || ! is_numeric($rawMinor)) {
            return new HikvisionEventClassification(null, 'malformed_event');
        }

        if (! $this->hasAuthenticationSignal($event)) {
            return new HikvisionEventClassification(null, 'unsupported_event_code');
        }

        $major = (int) $rawMajor;
        $minor = (int) $rawMinor;
        $serial = trim((string) data_get($event, 'serialNo', ''));
        $identityMaterial = implode('|', [$serial, $punchedAt->toIso8601String(), $external, $major, $minor]);
        $sourceEventId = ($serial !== '' ? $serial.'-' : '').hash('sha256', $identityMaterial);
        $attendanceStatus = trim((string) data_get($event, 'attendanceStatus', ''));

        return new HikvisionEventClassification(new AttendanceImportEvent(
            source: $source,
            sourceEventId: $sourceEventId,
            externalEmployeeIdentifier: $external,
            deviceIdentifier: $deviceIdentifier,
            punchedAt: $punchedAt,
            punchType: $this->punchType($attendanceStatus),
            safeMetadata: array_filter([
                'major' => $major,
                'minor' => $minor,
                'employee_name' => $this->safeName(data_get($event, 'name')),
                'attendance_status' => $attendanceStatus ?: null,
                'verification_mode' => $this->safeScalar(data_get($event, 'currentVerifyMode')),
                'card_reader_no' => $this->safeScalar(data_get($event, 'cardReaderNo')),
                'serial_no' => $serial ?: null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            idempotencyKey: (string) Str::uuid(),
        ));
    }

    /**
     * The AcsEvent endpoint can associate an Employee with administrative events.
     * Require an authentication/access marker rather than trusting identity alone.
     *
     * @param  array<string, mixed>  $event
     */
    private function hasAuthenticationSignal(array $event): bool
    {
        foreach (['currentVerifyMode', 'attendanceStatus', 'cardReaderNo', 'cardNo', 'doorNo'] as $field) {
            if (array_key_exists($field, $event) && $event[$field] !== null && $event[$field] !== '') {
                return true;
            }
        }

        return false;
    }

    private function punchType(string $status): string
    {
        $normalized = str($status)->lower()->replace(['-', ' '], '_')->toString();

        return match ($normalized) {
            'checkin', 'check_in', 'in' => 'check_in',
            'checkout', 'check_out', 'out' => 'check_out',
            'breakout', 'break_out' => 'break_out',
            'breakin', 'break_in' => 'break_in',
            default => 'unknown',
        };
    }

    private function safeName(mixed $value): ?string
    {
        return is_string($value) ? str($value)->squish()->limit(150)->toString() : null;
    }

    private function safeScalar(mixed $value): string|int|bool|null
    {
        return is_scalar($value) ? $value : null;
    }
}
