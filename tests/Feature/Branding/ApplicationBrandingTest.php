<?php

namespace Tests\Feature\Branding;

use App\Enums\AuthenticationOtpPurpose;
use App\Enums\QuotationDocumentType;
use App\Models\Quotation;
use App\Notifications\AuthenticationOtpNotification;
use App\Notifications\CriticalAlertMailNotification;
use App\Notifications\LoginEmailChangedNotification;
use App\Notifications\QuotationEmailNotification;
use App\Notifications\SmtpTestNotification;
use App\Services\Branding\ApplicationBranding;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ApplicationBrandingTest extends TestCase
{
    public function test_application_branding_centralizes_names_subjects_and_legacy_cleanup(): void
    {
        $branding = app(ApplicationBranding::class);
        $environment = config('app.env');

        $this->assertSame('Tech Point Zone ERP', $branding->fullName());
        $this->assertSame('TPZ ERP', $branding->shortName());
        $this->assertSame('Tech Point Zone ERP – Task Assigned — TSK-TEST', $branding->emailSubject('[ERP] Task Assigned — TSK-TEST'));
        $this->assertSame('Tech Point Zone ERP – Warranty SLA Alert', $branding->emailSubject('TPZ ERP UAT – Warranty SLA Alert'));
        $this->assertSame('Tech Point Zone ERP', $branding->emailSenderName('ERP UAT'));
        $this->assertSame('Operations Mailer', $branding->emailSenderName('Operations Mailer'));
        $this->assertSame($environment, config('app.env'));
    }

    public function test_external_notification_subjects_use_the_full_brand(): void
    {
        $this->assertSame(
            'Tech Point Zone ERP – Verification Code',
            (new AuthenticationOtpNotification('123456', AuthenticationOtpPurpose::Login))->toMail(new \stdClass)->subject,
        );
        $this->assertSame(
            'Tech Point Zone ERP – Password Reset',
            (new AuthenticationOtpNotification('123456', AuthenticationOtpPurpose::PasswordReset))->toMail(new \stdClass)->subject,
        );
        $this->assertSame(
            'Tech Point Zone ERP – Email Delivery Test',
            (new SmtpTestNotification)->toMail(new \stdClass)->subject,
        );
        $this->assertSame(
            'Tech Point Zone ERP – Login Email Changed',
            (new LoginEmailChangedNotification(false))->toMail(new \stdClass)->subject,
        );
        $this->assertSame(
            'Tech Point Zone ERP – Warranty SLA Alert — WR-TEST',
            (new CriticalAlertMailNotification('[ERP] Warranty SLA Alert — WR-TEST', 'Warranty SLA Alert', 'WR-TEST', 'Due soon'))->toMail(new \stdClass)->subject,
        );

        $quotation = (new Quotation)->forceFill([
            'reference' => 'QUO-TEST',
            'document_type' => QuotationDocumentType::Quotation,
            'customer_name' => 'Test Customer',
            'grand_total' => 100,
            'valid_until' => '2031-04-30',
        ]);
        $this->assertSame(
            'Tech Point Zone ERP – Quotation QUO-TEST',
            (new QuotationEmailNotification($quotation, '%PDF-test'))->toMail(new \stdClass)->subject,
        );
    }

    public function test_shared_email_layout_has_branded_fallback_closing_and_dynamic_footer(): void
    {
        CarbonImmutable::setTestNow('2031-04-05 10:00:00');
        config(['branding.email.logo_path' => null]);

        $html = (string) (new SmtpTestNotification)->toMail(new \stdClass)->render();

        $this->assertStringContainsString('Tech Point Zone ERP', $html);
        $this->assertStringContainsString('Regards,', $html);
        $this->assertStringContainsString('© 2031 Tech Point Zone ERP. All rights reserved.', html_entity_decode($html));
        $this->assertStringNotContainsString('Laravel Logo', $html);

        CarbonImmutable::setTestNow();
    }

    public function test_email_logo_uses_the_official_local_asset_and_falls_back_to_text_when_absent(): void
    {
        $branding = app(ApplicationBranding::class);
        $this->assertSame('branding/tech-point-zone-logo.png', config('branding.email.logo_path'));
        $this->assertStringEndsWith('/branding/tech-point-zone-logo.png', $branding->emailLogoUrl());

        $html = (string) (new SmtpTestNotification)->toMail(new \stdClass)->render();
        $this->assertStringContainsString('src="'.$branding->emailLogoUrl().'"', $html);
        $this->assertStringContainsString('alt="Tech Point Zone ERP"', $html);

        config(['branding.email.logo_path' => 'branding/missing-logo.png']);
        $this->assertNull($branding->emailLogoUrl());
        $fallback = (string) (new SmtpTestNotification)->toMail(new \stdClass)->render();
        $this->assertStringContainsString('Tech Point Zone ERP', $fallback);
        $this->assertStringNotContainsString('<img', $fallback);
    }

    public function test_filament_panel_uses_the_full_application_brand(): void
    {
        $this->assertSame('Tech Point Zone ERP', filament()->getPanel('admin')->getBrandName());
    }
}
