<?php

use App\Http\Controllers\Auth\EmailOtpAuthenticationController;
use App\Http\Controllers\Auth\LoginBrandingAssetController;
use App\Http\Controllers\PublicTaxInvoiceVerificationController;
use App\Http\Controllers\QuotationPdfController;
use App\Http\Controllers\ReportsExportController;
use App\Http\Controllers\TaxInvoicePdfController;
use Illuminate\Support\Facades\Route;

Route::get('/admin/reports/download/{report}/{format}', ReportsExportController::class)
    ->where('format', 'xlsx|csv|pdf')
    ->middleware('auth')
    ->name('reports.export');

Route::get('/', function () {
    return redirect()->to(filament()->getPanel('admin')->getUrl());
});

Route::get('/admin/login/branding/logo', LoginBrandingAssetController::class)
    ->middleware('panel:admin')
    ->name('auth.branding.logo');

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
    ->middleware('auth')->name('tax-invoices.pdf');

Route::get('/admin/quotations/{quotation}/pdf', QuotationPdfController::class)
    ->middleware('auth')->name('quotations.pdf');
