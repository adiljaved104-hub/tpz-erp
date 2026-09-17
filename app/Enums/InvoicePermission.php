<?php

namespace App\Enums;

enum InvoicePermission: string
{
    case View = 'invoice.view';
    case ViewAll = 'invoice.view_all';
    case Create = 'invoice.create';
    case EditCustomerDetails = 'invoice.edit_customer_details';
    case Void = 'invoice.void';
    case DownloadPdf = 'invoice.download_pdf';
    case Export = 'invoice.export';
    case SettingsView = 'invoice.settings.view';
    case SettingsManage = 'invoice.settings.manage';
}
