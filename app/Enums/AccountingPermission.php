<?php

namespace App\Enums;

enum AccountingPermission: string
{
    case ViewChart = 'accounting.view_chart';
    case ViewJournals = 'accounting.view_journals';
    case ViewPeriods = 'accounting.view_periods';
    case ViewGeneralLedger = 'accounting.view_general_ledger';
    case ManageChart = 'accounting.manage_chart';
    case ManagePeriods = 'accounting.manage_periods';
    case CreateJournal = 'accounting.create_journal';
    case PostJournal = 'accounting.post_journal';
    case ReverseJournal = 'accounting.reverse_journal';
    case ViewTrialBalance = 'accounting.view_trial_balance';
    case ViewPayables = 'accounting.view_payables';
    case CreateSupplierBill = 'accounting.create_supplier_bill';
    case RecordSupplierPayment = 'accounting.record_supplier_payment';
    case AllocateSupplierPayment = 'accounting.allocate_supplier_payment';
    case ManageSupplierCredits = 'accounting.manage_supplier_credits';
    case ApproveSupplierPayables = 'accounting.approve_supplier_payables';
    case ViewImportCenter = 'accounting.view_import_center';
    case ManageImportCenter = 'accounting.manage_import_center';
}
