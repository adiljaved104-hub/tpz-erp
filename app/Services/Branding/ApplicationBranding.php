<?php

namespace App\Services\Branding;

use Illuminate\Notifications\Messages\MailMessage;

final class ApplicationBranding
{
    public function fullName(): string
    {
        return (string) config('branding.full_name', 'Tech Point Zone ERP');
    }

    public function shortName(): string
    {
        return (string) config('branding.short_name', 'TPZ ERP');
    }

    public function tagline(): string
    {
        return (string) config('branding.tagline', 'Internal Business Management System');
    }

    public function emailSenderName(?string $configured = null): string
    {
        if (filled($configured) && ! $this->isLegacyGenericName($configured)) {
            return trim($configured);
        }

        return (string) config('branding.email.sender_name', $this->fullName());
    }

    public function emailSubject(string $purpose): string
    {
        $purpose = trim((string) preg_replace(
            '/^(?:\[ERP\]|TPZ\s+ERP\s+UAT|ERP\s+UAT|TPZ\s+ERP|Tech\s+Point\s+Zone\s+ERP)\s*(?:[-–—:]\s*)?/iu',
            '',
            trim($purpose),
        ));

        return $this->fullName().config('branding.email.subject_separator', ' – ').$purpose;
    }

    public function mail(MailMessage $message, string $purpose): MailMessage
    {
        return $message
            ->subject($this->emailSubject($purpose))
            ->salutation("Regards,  \n{$this->fullName()}");
    }

    public function loginTitle(?string $configured = null): string
    {
        return filled($configured) && ! $this->isLegacyGenericName($configured)
            ? trim($configured)
            : $this->fullName();
    }

    public function emailLogoUrl(): ?string
    {
        return $this->publicAssetUrl(config('branding.email.logo_path'));
    }

    public function uiLogoUrl(): ?string
    {
        return $this->publicAssetUrl(config('branding.ui.logo_path'));
    }

    public function copyright(): string
    {
        return '© '.now()->year.' '.$this->fullName().'. All rights reserved.';
    }

    private function isLegacyGenericName(string $name): bool
    {
        return in_array(mb_strtolower(trim($name)), [
            'erp',
            'erp uat',
            'tpz erp',
            'tpz erp uat',
            'laravel',
        ], true);
    }

    private function publicAssetUrl(mixed $path): ?string
    {
        $path = ltrim(trim((string) $path), '/\\');
        if ($path === '' || ! is_file(public_path($path))) {
            return null;
        }

        return asset(str_replace('\\', '/', $path));
    }
}
