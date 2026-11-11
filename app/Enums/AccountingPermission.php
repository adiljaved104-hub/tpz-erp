<?php

namespace App\Enums;

enum AccountingPermission: string
{
    /** Legacy umbrella permission retained for service compatibility. */
    case View = 'accounting.view';
    case ViewChart = 'accounting.view_chart';
    case ViewJournals = 'accounting.view_journals';
    case ViewPeriods = 'accounting.view_periods';
    case ViewLedger = 'accounting.view_ledger';
    case ManageChart = 'accounting.manage_chart';
    case ManagePeriods = 'accounting.manage_periods';
    case CreateJournal = 'accounting.create_journal';
    case PostJournal = 'accounting.post_journal';
    case ReverseJournal = 'accounting.reverse_journal';
    case ViewTrialBalance = 'accounting.view_trial_balance';
    case Export = 'accounting.export';
}
