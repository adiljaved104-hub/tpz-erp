<?php

namespace App\Enums;

enum InvoicePermission: string
{
    case View = 'invoice.view';
    case ViewAll = 'invoice.view_all';
    case Create = 'invoice.create';
    case EditCustomerDetails = 'invoice.edit_customer_details';
    case ManagePaymentStatus = 'invoice.manage_payment_status';
    case EditPrices = 'invoice.edit_prices';
    case ReviewPriceAdjustments = 'invoice.review_price_adjustments';
    case IssuePriceAdjustments = 'invoice.issue_price_adjustments';
    case Void = 'invoice.void';
    case DownloadPdf = 'invoice.download_pdf';
    case Export = 'invoice.export';
    case SettingsView = 'invoice.settings.view';
    case SettingsManage = 'invoice.settings.manage';
}
