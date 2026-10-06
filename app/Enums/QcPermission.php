<?php

namespace App\Enums;

enum QcPermission: string
{
    case View = 'qc.view';
    case Start = 'qc.start';
    case Update = 'qc.update';
    case Complete = 'qc.complete';
    case PrintCertificate = 'qc.print_certificate';
    case PrintLabel = 'qc.print_label';
    case ViewCustomerEvidence = 'qc.view_customer_evidence';
    case ViewAll = 'qc.view_all';
    case Reopen = 'qc.reopen';
    case ManageTemplates = 'qc.manage_templates';
    case ViewInternalEvidence = 'qc.view_internal_evidence';
}
