<?php

use App\Http\Controllers\Auth\EmailOtpAuthenticationController;
use App\Http\Controllers\Auth\LoginBrandingAssetController;
use App\Http\Controllers\Auth\WebSessionActivityController;
use App\Http\Controllers\PublicTaxInvoiceVerificationController;
use App\Http\Controllers\QcDocumentController;
use App\Http\Controllers\QcPassportController;
use App\Http\Controllers\QuotationPdfController;
use App\Http\Controllers\ReportsExportController;
use App\Http\Controllers\TaxInvoicePdfController;
use App\Http\Middleware\EnforceWebSecurityPolicy;
use Illuminate\Support\Facades\Route;

Route::get('/verify/qc', [QcPassportController::class, 'search'])->middleware('throttle:20,1,qc-search')->name('qc.search');
Route::get('/verify/qc/{token}', [QcPassportController::class, 'show'])->middleware('throttle:60,1,qc-passport')->where('token', '[a-f0-9]{64}')->name('qc.verify');
Route::get('/verify/qc/{token}/evidence/{id}', [QcPassportController::class, 'evidence'])->middleware('throttle:120,1,qc-image')->where('token', '[a-f0-9]{64}')->whereUuid('id')->name('qc.evidence.public');
Route::middleware(['auth', EnforceWebSecurityPolicy::class])->group(function (): void {
    Route::get('/admin/qc-documents/labels', [QcDocumentController::class, 'labels'])->name('qc.labels');
    Route::get('/admin/qc-documents/{inspection}/certificate', [QcDocumentController::class, 'certificate'])->name('qc.certificate');
    Route::get('/admin/qc-evidence/{evidence}', [QcDocumentController::class, 'evidence'])->name('qc.evidence.internal');
});

Route::get('/admin/reports/download/{report}/{format}', ReportsExportController::class)
    ->where('format', 'xlsx|csv|pdf')
    ->middleware(['auth', EnforceWebSecurityPolicy::class])
    ->name('reports.export');

Route::get('/', function () {
    return redirect()->to(filament()->getPanel('admin')->getUrl());
});

Route::get('/admin/login/branding/logo', LoginBrandingAssetController::class)
    ->middleware('panel:admin')
    ->name('auth.branding.logo');

Route::middleware(['auth', EnforceWebSecurityPolicy::class])->group(function (): void {
    Route::get('/admin/session/activity', [WebSessionActivityController::class, 'show'])
        ->name('auth.session.activity.show');
    Route::post('/admin/session/activity', [WebSessionActivityController::class, 'store'])
        ->name('auth.session.activity.store');
});

Route::middleware(['guest', 'panel:admin'])->group(function (): void {
    Route::get('/admin/login/email-otp', [EmailOtpAuthenticationController::class, 'requestLogin'])->name('auth.otp.request');
    Route::post('/admin/login/email-otp', [EmailOtpAuthenticationController::class, 'sendLogin'])->name('auth.otp.send');
    Route::get('/admin/login/email-otp/verify', [EmailOtpAuthenticationController::class, 'verifyLoginForm'])->name('auth.otp.verify');
    Route::post('/admin/login/email-otp/verify', [EmailOtpAuthenticationController::class, 'verifyLogin'])->name('auth.otp.verify.submit');
    Route::post('/admin/login/email-otp/resend', [EmailOtpAuthenticationController::class, 'resendLogin'])->name('auth.otp.resend');
    Route::get('/admin/forgot-password', [EmailOtpAuthenticationController::class, 'forgotPassword'])->name('auth.password.request');
    Route::post('/admin/forgot-password', [EmailOtpAuthenticationController::class, 'sendPasswordReset'])->name('auth.password.send');
    Route::get('/admin/forgot-password/verify', [EmailOtpAuthenticationController::class, 'verifyPasswordResetForm'])->name('auth.password.verify');
    Route::post('/admin/forgot-password/verify', [EmailOtpAuthenticationController::class, 'verifyPasswordReset'])->name('auth.password.verify.submit');
    Route::post('/admin/forgot-password/resend', [EmailOtpAuthenticationController::class, 'resendPasswordReset'])->name('auth.password.resend');
    Route::get('/admin/reset-password', [EmailOtpAuthenticationController::class, 'resetPasswordForm'])->name('auth.password.reset');
    Route::post('/admin/reset-password', [EmailOtpAuthenticationController::class, 'resetPassword'])->name('auth.password.update');
});

Route::get('/invoice/verify/{token}', PublicTaxInvoiceVerificationController::class)
    ->name('invoice.verify');

Route::get('/admin/tax-invoices/{invoice}/pdf', TaxInvoicePdfController::class)
    ->middleware(['auth', EnforceWebSecurityPolicy::class])->name('tax-invoices.pdf');

Route::get('/admin/quotations/{quotation}/pdf', QuotationPdfController::class)
    ->middleware(['auth', EnforceWebSecurityPolicy::class])->name('quotations.pdf');
